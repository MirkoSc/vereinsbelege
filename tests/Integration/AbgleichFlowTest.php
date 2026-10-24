<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\AuditAction;
use App\Domain\BankAccountKind;
use App\Domain\BankTransactionDirection;
use App\Domain\BankTransactionDocStatus;
use App\Domain\BankTransactionSource;
use App\Domain\DocumentSource;
use App\Domain\DocumentStatus;
use App\Domain\InvoiceData;
use App\Domain\InvoiceDirection;
use App\Domain\InvoiceStructure;
use App\Domain\InvoiceType;
use App\Domain\SupplierData;
use App\Domain\SupplierOrigin;
use App\Domain\SupplierRole;
use App\Domain\Zahlungsweg;
use App\Domain\Zugriffsbereich;
use App\Domain\ZuordnungMethode;
use App\Repository\AuditLogRepository;
use App\Repository\BankAccountRepository;
use App\Repository\BankTransactionRepository;
use App\Repository\CategoryRepository;
use App\Repository\DocumentRepository;
use App\Repository\InvoiceRepository;
use App\Repository\SettingRepository;
use App\Repository\SupplierRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Audit\AuditFilter;
use App\Service\Audit\AuditLog;
use App\Service\Bank\Abgleich;
use App\Service\Bank\Abgleichsparameter;
use App\Service\Bank\BankAccountService;
use App\Service\Bank\BankRuleViolation;
use App\Service\Bank\Signal;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\MasterData\SupplierService;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\ZuordnungFabrik;

/**
 * The matching run on the real schema and vault (M10-2, issue #66,
 * docs/spec/04-bank-und-abgleich.md section 5 "Punktebewertung"): what is
 * unambiguous is allocated as `auto` with score and trace, the rest stays a
 * suggestion, accepting one re-scores it, only checked receipts and
 * bookings within the scope take part, the settings are stored and
 * audited - and nothing but ids, counts, signal keys and points leaves the
 * vault.
 */
final class AbgleichFlowTest extends DatabaseTestCase
{
    private const string IP = '192.0.2.10';

    private const string IBAN_LIEFERANT = 'DE89370400440532013000';

    private Vault $tresor;
    private AuditLog $audit;
    private int $userId;
    private int $konto;
    private int $lieferant;
    private int $laufnummer = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $crypto = new ServerCrypto((string) base64_decode(self::configData()['server_key'], true));
        $this->tresor = Vault::create();
        new VaultRepository($this->pdo())->insert($this->tresor->publicKey());
        $this->audit = new AuditLog(new AuditLogRepository($this->pdo()), new VaultRepository($this->pdo()), $crypto);
        $this->userId = new UserRepository($this->pdo())->insert(
            $crypto->encrypt('finanzen@example.org'),
            random_bytes(32),
            $crypto->encrypt('Fritz Finanzen'),
            'hash',
            mfaRequired: false,
        );
        $this->konto = new BankAccountService($this->pdo(), new BankAccountRepository($this->pdo()))->anlegen(
            $this->tresor,
            BankAccountKind::Bank,
            ['name' => 'Girokonto', 'iban' => '', 'bic' => '', 'bank' => '', 'opening_balance' => '0,00', 'opening_date' => '2026-01-01'],
            new \DateTimeImmutable(),
        )->id;
        $this->lieferant = $this->lieferant('Getränke Müller GmbH', [self::IBAN_LIEFERANT], ['M-12345']);
    }

    public function testAnUnambiguousPairIsAllocatedAutomaticallyWithScoreAndTrace(): void
    {
        $beleg = $this->beleg(11900, nummer: 'RE-2026-0815');
        $buchung = $this->buchung(-11900, 'Rechnung RE-2026-0815', 'Getraenke Mueller', self::IBAN_LIEFERANT);
        $andere = $this->buchung(-5000, 'Miete März', 'Stadt Musterhausen', 'DE02120300000000202051');

        $ergebnis = $this->abgleich()->automatischZuordnen($this->tresor, Zugriffsbereich::unbeschraenkt(), $this->userId, self::IP, new \DateTimeImmutable());

        self::assertSame(['angelegt' => 1, 'uebersprungen' => 0], $ergebnis);
        $zeile = $this->pdo()->query('SELECT * FROM allocation')->fetch();
        self::assertIsArray($zeile);
        self::assertSame([$beleg, $buchung], [(int) $zeile['invoice_id'], (int) $zeile['transaction_id']]);
        self::assertSame(ZuordnungMethode::Auto->value, $zeile['method']);
        self::assertSame(45 + 40 + 30 + 15 + 10, (int) $zeile['score']);
        $trace = json_decode((string) $zeile['rule_trace'], true);
        self::assertSame(['v' => 1, 'signale' => [
            Signal::BetragExakt->value => 45,
            Signal::Rechnungsnummer->value => 40,
            Signal::Iban->value => 30,
            Signal::Name->value => 15,
            Signal::DatumImFenster->value => 10,
        ]], $trace);
        self::assertStringNotContainsString('0815', (string) $zeile['rule_trace'], 'signal keys and points, never a value');
        self::assertStringNotContainsString(self::IBAN_LIEFERANT, (string) $zeile['rule_trace']);

        self::assertSame('bezahlt', $this->spalte('invoice', $beleg, 'payment_status'));
        self::assertSame('zugeordnet', $this->spalte('bank_transaction', $buchung, 'doc_status'));
        self::assertSame('fehlt', $this->spalte('bank_transaction', $andere, 'doc_status'));

        // Released, it is open again - and the next run allocates it again.
        $this->zuordnung()->loesen($this->tresor, (int) $zeile['id'], $this->userId, self::IP, new \DateTimeImmutable());
        self::assertSame('offen', $this->spalte('invoice', $beleg, 'payment_status'));
        self::assertSame(1, $this->abgleich()->automatischZuordnen($this->tresor, Zugriffsbereich::unbeschraenkt(), $this->userId, self::IP, new \DateTimeImmutable())['angelegt']);
        self::assertSame(0, $this->abgleich()->automatischZuordnen($this->tresor, Zugriffsbereich::unbeschraenkt(), $this->userId, self::IP, new \DateTimeImmutable())['angelegt'], 'nothing left');
    }

    public function testAmbiguousPairsStaySuggestionsAndAcceptingOneReScoresIt(): void
    {
        // Two receipts over the same amount of the same supplier, one booking.
        $eins = $this->beleg(5000, datum: '2026-03-10');
        $zwei = $this->beleg(5000, datum: '2026-03-12');
        $buchung = $this->buchung(-5000, 'Lieferung', 'Getränke Müller', self::IBAN_LIEFERANT);

        $stand = $this->abgleich()->planen($this->tresor, Zugriffsbereich::unbeschraenkt());
        self::assertSame([], $stand->plan->auto);
        self::assertSame([$eins, $zwei], array_map(static fn($v): int => $v->invoiceId, $stand->plan->vorschlaege));
        self::assertSame(5000, $stand->belege[$eins]->offen);
        self::assertSame('Getränke Müller GmbH', $stand->belege[$eins]->lieferant);
        self::assertSame(-5000, $stand->buchungen[$buchung]->buchung->amount);
        self::assertSame(0, $this->abgleich()->automatischZuordnen($this->tresor, Zugriffsbereich::unbeschraenkt(), $this->userId, self::IP, new \DateTimeImmutable())['angelegt']);

        $id = $this->abgleich()->uebernehmen($this->tresor, Zugriffsbereich::unbeschraenkt(), $zwei, $buchung, 5000, $this->userId, self::IP, new \DateTimeImmutable());

        $zuordnung = $this->zuordnung()->fuerBeleg($this->tresor, $zwei)[0];
        self::assertSame($id, $zuordnung->id);
        self::assertSame(ZuordnungMethode::VorschlagBestaetigt, $zuordnung->method);
        self::assertSame(5000, $zuordnung->amount);
        self::assertSame(45 + 30 + 15 + 10, $zuordnung->score);

        // The booking is used up: the other receipt's suggestion is gone and cannot be accepted from an old page.
        self::assertSame([], $this->abgleich()->planen($this->tresor, Zugriffsbereich::unbeschraenkt())->plan->vorschlaege);
        $this->expectException(BankRuleViolation::class);
        $this->abgleich()->uebernehmen($this->tresor, Zugriffsbereich::unbeschraenkt(), $eins, $buchung, 5000, $this->userId, self::IP, new \DateTimeImmutable());
    }

    public function testACollectiveTransferIsSuggestedPerReceiptWithItsOpenAmount(): void
    {
        $a = $this->beleg(6000, nummer: 'RE-1001');
        $b = $this->beleg(4000, nummer: 'RE-1002');
        $buchung = $this->buchung(-10000, 'RE-1001 RE-1002', 'Getränke Müller', self::IBAN_LIEFERANT);

        $plan = $this->abgleich()->planen($this->tresor, Zugriffsbereich::unbeschraenkt())->plan;
        self::assertSame([], $plan->auto);
        self::assertSame([6000, 4000], array_map(static fn($v): int => $v->betrag, $plan->vorschlaege));

        $this->abgleich()->uebernehmen($this->tresor, Zugriffsbereich::unbeschraenkt(), $a, $buchung, 6000, $this->userId, self::IP, new \DateTimeImmutable());
        self::assertSame('teilweise', $this->spalte('bank_transaction', $buchung, 'doc_status'));
        $this->abgleich()->uebernehmen($this->tresor, Zugriffsbereich::unbeschraenkt(), $b, $buchung, 4000, $this->userId, self::IP, new \DateTimeImmutable());
        self::assertSame('zugeordnet', $this->spalte('bank_transaction', $buchung, 'doc_status'));
        self::assertSame(['bezahlt', 'bezahlt'], [$this->spalte('invoice', $a, 'payment_status'), $this->spalte('invoice', $b, 'payment_status')]);
    }

    public function testAcceptingRefusesAChangedAmountAndAPairBelowTheMinimum(): void
    {
        $beleg = $this->beleg(5000);
        $buchung = $this->buchung(-5000, '', 'Getränke Müller', '');      // 45 + 15 + 10 = 70
        $fremd = $this->buchung(-1234, 'Miete', 'Stadt', '');            // 10

        try {
            $this->abgleich()->uebernehmen($this->tresor, Zugriffsbereich::unbeschraenkt(), $beleg, $buchung, 4000, $this->userId, self::IP, new \DateTimeImmutable());
            self::fail('the page showed another amount');
        } catch (BankRuleViolation) {
        }
        try {
            $this->abgleich()->uebernehmen($this->tresor, Zugriffsbereich::unbeschraenkt(), $beleg, $fremd, 1234, $this->userId, self::IP, new \DateTimeImmutable());
            self::fail('never a suggestion');
        } catch (BankRuleViolation) {
        }
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM allocation')->fetchColumn());
    }

    public function testAnExpenseMarkedAsNeedingNoReceiptIsNoCandidate(): void
    {
        $this->beleg(5000);
        $buchung = $this->buchung(-5000, '', 'Getränke Müller', self::IBAN_LIEFERANT);
        $this->pdo()->prepare('UPDATE bank_transaction SET doc_required = 0, doc_status = ? WHERE id = ?')->execute([BankTransactionDocStatus::NichtNoetig->value, $buchung]);
        $this->buchung(5000, 'Beitrag', 'Mitglied', '');

        self::assertSame([], $this->abgleich()->planen($this->tresor, Zugriffsbereich::unbeschraenkt())->buchungen, 'and no income is read while no outgoing invoice is open');
    }

    public function testOnlyCheckedReceiptsExpectingABookingWithinTheScopeTakePart(): void
    {
        $this->beleg(7000, status: DocumentStatus::InPruefung);
        $this->beleg(7000, weg: Zahlungsweg::Bar);
        $this->beleg(7000, status: DocumentStatus::Abgelehnt);
        $alt = $this->beleg(7000, datum: '2025-12-20');
        $this->buchung(-7000, '', 'Getränke Müller', self::IBAN_LIEFERANT);

        $alle = $this->abgleich()->planen($this->tresor, Zugriffsbereich::unbeschraenkt());
        self::assertSame([$alt], array_keys($alle->belege));

        $nur2026 = new Zugriffsbereich(null, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));
        self::assertSame([], $this->abgleich()->planen($this->tresor, $nur2026)->belege, 'the receipt lies outside the period');
        self::assertSame([], $this->abgleich()->planen($this->tresor, $nur2026)->buchungen, 'without an open receipt no booking is read');
        self::assertSame([], $this->abgleich()->planen($this->tresor, new Zugriffsbereich([999]))->buchungen, 'a booking has no cost center');

        $this->expectException(BankRuleViolation::class);
        $this->abgleich()->uebernehmen($this->tresor, $nur2026, $alt, (int) $this->pdo()->query('SELECT MAX(id) FROM bank_transaction')->fetchColumn(), 7000, $this->userId, self::IP, new \DateTimeImmutable());
    }

    public function testACreditNoteIsMatchedWithTheOppositeDirection(): void
    {
        $gutschrift = $this->beleg(2500, art: InvoiceType::Gutschrift);
        $this->buchung(-2500, 'Gutschrift', 'Getränke Müller', self::IBAN_LIEFERANT);
        $rueck = $this->buchung(2500, 'Gutschrift', 'Getränke Müller', self::IBAN_LIEFERANT);

        $plan = $this->abgleich()->planen($this->tresor, Zugriffsbereich::unbeschraenkt())->plan;

        self::assertSame([[$gutschrift, $rueck]], array_map(static fn($v): array => [$v->invoiceId, $v->transactionId], $plan->auto));
    }

    public function testTheParametersAreSettingsAndTakeEffect(): void
    {
        $this->beleg(5000);
        $this->buchung(-5000, '', 'Getränke Müller', '');   // 45 + 15 + 10 = 70

        self::assertSame([], $this->abgleich()->planen($this->tresor, Zugriffsbereich::unbeschraenkt())->plan->auto);

        $neu = Abgleichsparameter::ausWerten([...new Abgleichsparameter()->alsWerte(), 'auto_schwelle' => 70]);
        self::assertTrue($this->abgleich()->parameterSpeichern($neu));
        self::assertFalse($this->abgleich()->parameterSpeichern($neu), 'unchanged');
        self::assertSame(70, Abgleichsparameter::fromSettings(new SettingRepository($this->pdo()))->autoSchwelle);
        self::assertCount(1, $this->abgleich()->planen($this->tresor, Zugriffsbereich::unbeschraenkt())->plan->auto);

        new SettingRepository($this->pdo())->set(Abgleichsparameter::SETTING, 'kaputt');
        self::assertSame(85, $this->abgleich()->parameter()->autoSchwelle, 'a broken setting falls back to the start values');
    }

    public function testTheAuditNamesIdsAndMethodOnly(): void
    {
        $this->beleg(11900, nummer: 'RE-77');
        $this->buchung(-11900, 'RE-77', 'Getränke Müller', self::IBAN_LIEFERANT);
        $this->abgleich()->automatischZuordnen($this->tresor, Zugriffsbereich::unbeschraenkt(), $this->userId, self::IP, new \DateTimeImmutable());

        $eintraege = new AuditLogRepository($this->pdo())->page(new AuditFilter(entity: 'allocation'), null, 10);
        self::assertCount(1, $eintraege);
        self::assertSame(AuditAction::ZuordnungAngelegt->value, $eintraege[0]->action);
        $details = $this->audit->details($eintraege[0], $this->tresor) ?? self::fail();
        self::assertSame(['invoice_id', 'transaction_id', 'method', 'score'], array_keys($details));
        self::assertSame('auto', $details['method']);
    }

    // ------------------------------------------------------------ helpers

    private function abgleich(): Abgleich
    {
        $pdo = $this->pdo();

        return new Abgleich(
            new InvoiceRepository($pdo),
            new BankTransactionRepository($pdo),
            new SupplierService($pdo, new SupplierRepository($pdo), new CategoryRepository($pdo)),
            $this->zuordnung(),
            new SettingRepository($pdo),
        );
    }

    private function zuordnung(): \App\Service\Bank\Zuordnung
    {
        return ZuordnungFabrik::bauen($this->pdo(), $this->audit);
    }

    /**
     * @param list<string> $ibans
     * @param list<string> $mandate
     */
    private function lieferant(string $name, array $ibans, array $mandate): int
    {
        $repository = new SupplierRepository($this->pdo());
        $key = DataKey::generate();
        $id = $repository->insert(SupplierRole::Lieferant, null, SupplierOrigin::Manuell, $this->tresor->sealDataKey($key), new \DateTimeImmutable());
        $repository->setData($id, SupplierService::verschluesseln($key, $id, new SupplierData($name, ibans: $ibans, mandateRefs: $mandate)));

        return $id;
    }

    /** A receipt of the test supplier straight into the encrypted store; returns the `invoice` id. */
    private function beleg(
        int $brutto,
        string $nummer = '',
        string $datum = '2026-03-14',
        DocumentStatus $status = DocumentStatus::Geprueft,
        Zahlungsweg $weg = Zahlungsweg::Konto,
        InvoiceType $art = InvoiceType::Rechnung,
    ): int {
        ++$this->laufnummer;
        $dokument = new DocumentRepository($this->pdo())->insert(
            DocumentSource::Intern,
            null,
            [],
            $this->tresor->sealDataKey(DataKey::generate()),
            new \DateTimeImmutable()->modify('+' . $this->laufnummer . ' seconds'),
            $status,
            createdBy: $this->userId,
        );
        $schluessel = DataKey::generate();
        $rechnungen = new InvoiceRepository($this->pdo());
        $id = $rechnungen->insert(
            $dokument,
            new InvoiceStructure($art, InvoiceDirection::Ausgabe, $this->lieferant, new \DateTimeImmutable($datum), null, null, null, null, null, $weg),
            $this->tresor->sealDataKey($schluessel),
            null,
            $this->userId,
            new \DateTimeImmutable(),
        );
        $daten = new InvoiceData($nummer, $brutto, null, [], 'EUR', 'Zweck', '');
        $rechnungen->setData($id, FieldCipher::encrypt($schluessel, json_encode($daten->toPayload(), JSON_THROW_ON_ERROR), new FieldContext('invoice', $id, 'data_enc')));

        return $id;
    }

    /** A booking as the statement import writes it, on 2026-03-20. */
    private function buchung(int $cent, string $zweck, string $name, string $iban): int
    {
        $repository = new BankTransactionRepository($this->pdo());
        $richtung = BankTransactionDirection::ausDemBetrag($cent);
        $key = DataKey::generate();
        $id = $repository->insert(
            $this->konto,
            null,
            new \DateTimeImmutable('2026-03-20'),
            null,
            $richtung,
            $this->tresor->sealDataKey($key),
            random_bytes(32),
            null,
            BankTransactionDocStatus::fuerNeueBuchung($richtung === BankTransactionDirection::Ausgabe),
            $richtung === BankTransactionDirection::Ausgabe,
            BankTransactionSource::Import,
            new \DateTimeImmutable(),
        ) ?? self::fail();
        $repository->setCiphertext($id, FieldCipher::encrypt($key, (string) json_encode([
            'amount' => $cent, 'currency' => 'EUR', 'counterparty_name' => $name, 'counterparty_iban' => $iban,
            'purpose' => $zweck, 'eref' => '', 'mref' => '', 'cred' => '', 'gvc' => '', 'booking_text' => 'SEPA-Überweisung',
        ]), new FieldContext('bank_transaction', $id, 'data_enc')));

        return $id;
    }

    private function spalte(string $tabelle, int $id, string $spalte): string
    {
        $stmt = $this->pdo()->prepare(sprintf('SELECT %s FROM %s WHERE id = ?', $spalte, $tabelle));
        $stmt->execute([$id]);

        return (string) $stmt->fetchColumn();
    }
}
