<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\AuditAction;
use App\Domain\AuditEntry;
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
use App\Domain\Zahlungsweg;
use App\Domain\Zugriffsbereich;
use App\Domain\ZuordnungMethode;
use App\Repository\AuditLogRepository;
use App\Repository\BankAccountRepository;
use App\Repository\BankTransactionRepository;
use App\Repository\DocumentRepository;
use App\Repository\ErstattungRepository;
use App\Repository\InvoiceRepository;
use App\Repository\SubmissionRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Audit\AuditFilter;
use App\Service\Audit\AuditLog;
use App\Service\Bank\BankAccountService;
use App\Service\Bank\BankRuleViolation;
use App\Service\Bank\Erstattungen;
use App\Service\Bank\Erstattungskandidat;
use App\Service\Bank\Import\KontoauszugImport;
use App\Service\Bank\Kombinationssuche;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\ZuordnungFabrik;
use App\View\Area;
use App\View\View;

/**
 * Reimbursements (M10-3, issue #67, docs/spec/04-bank-und-abgleich.md
 * section 5, E-36): the submitter's IBAN links open reimbursements with a
 * transfer, a bounded combination search finds the receipts one transfer
 * reimburses together, and all of it is written through App\Service\Bank\
 * Zuordnung in one transaction.
 *
 * Mandatory test of the spec for this scope: Erstattungs-Kombinationssuche
 * (with App\Tests\Service\Bank\KombinationssucheTest for the bounds).
 */
final class ErstattungFlowTest extends DatabaseTestCase
{
    private const string IP = '192.0.2.10';

    private const string IBAN_ANNA = 'DE89370400440532013000';

    private const string IBAN_BERT = 'DE02120300000000202051';

    private Vault $tresor;
    private AuditLog $audit;
    private int $userId;
    private int $konto;
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
    }

    public function testOneTransferReimbursesSeveralReceiptsOfOneSubmitter(): void
    {
        $erste = $this->erstattung(25013, 'Anna Auslage', self::IBAN_ANNA, datum: '2026-02-01', nummer: 'K-1');
        $zweite = $this->erstattung(40517, 'Anna Auslage', 'de89 3704 0044 0532 0130 00', datum: '2026-02-10');
        $dritte = $this->erstattung(12331, 'Anna Auslage', self::IBAN_ANNA, datum: '2026-02-20');
        // Another member: the same sum of two receipts must not be offered.
        $fremd = $this->erstattung(65530, 'Bert Beispiel', self::IBAN_BERT);
        $buchung = $this->buchung(-77861, self::IBAN_ANNA);

        $vorschlag = $this->erstattungen()->vorschlag($this->tresor, $buchung, Zugriffsbereich::unbeschraenkt()) ?? self::fail();

        self::assertNull($vorschlag->hinweis);
        self::assertSame(77861, $vorschlag->offen);
        self::assertSame([$erste, $zweite, $dritte], self::ids($vorschlag->kandidaten), 'oldest first, only this IBAN');
        self::assertSame('Anna Auslage', $vorschlag->kandidaten[0]->einreicher);
        self::assertSame('K-1', $vorschlag->kandidaten[0]->invoiceNumber);
        self::assertSame([[$erste, $zweite, $dritte]], array_map(self::ids(...), $vorschlag->kombinationen));
        self::assertTrue($vorschlag->vollstaendig);

        $ids = $this->erstattungen()->zuordnen($this->tresor, $buchung, [$dritte, $erste, $zweite], Zugriffsbereich::unbeschraenkt(), $this->userId, self::IP, new \DateTimeImmutable());

        self::assertCount(3, $ids);
        foreach ([$erste, $zweite, $dritte] as $beleg) {
            self::assertSame('erstattet', $this->zeile('invoice', $beleg)['payment_status']);
        }
        self::assertSame('erstattung_offen', $this->zeile('invoice', $fremd)['payment_status']);
        self::assertSame('zugeordnet', $this->zeile('bank_transaction', $buchung)['doc_status']);

        $zuordnungen = ZuordnungFabrik::bauen($this->pdo(), $this->audit)->fuerBuchung($this->tresor, $buchung);
        self::assertSame([25013, 40517, 12331], array_map(static fn($z): int => $z->amount, $zuordnungen));
        foreach ($zuordnungen as $zuordnung) {
            self::assertSame(ZuordnungMethode::VorschlagBestaetigt, $zuordnung->method);
        }
        self::assertSame(['erstattung_iban' => 60, 'belege' => 3], $zuordnungen[0]->ruleTrace);

        // The log names ids and the method, never an amount or the IBAN.
        $protokoll = new AuditLogRepository($this->pdo())->page(new AuditFilter(entity: 'allocation', entityId: $ids[0]), null, 10);
        self::assertSame([AuditAction::ZuordnungAngelegt->value], array_map(static fn(AuditEntry $e): string => $e->action, $protokoll));
        $roh = $this->rohTabelle('audit_log') . $this->rohTabelle('allocation');
        foreach (['25013', '40517', '12331', '77861', self::IBAN_ANNA, 'Anna'] as $klartext) {
            self::assertStringNotContainsString($klartext, $roh);
        }

        // Settled: nothing is left to search.
        $danach = $this->erstattungen()->vorschlag($this->tresor, $buchung, Zugriffsbereich::unbeschraenkt()) ?? self::fail();
        self::assertNotNull($danach->hinweis);
        self::assertSame([], $danach->kombinationen);
    }

    public function testTheReceiptSideFindsTheTransferByTheBlindIndex(): void
    {
        $beleg = $this->erstattung(3000, 'Anna Auslage', self::IBAN_ANNA);
        $passend = $this->buchung(-3000, self::IBAN_ANNA, '2026-03-20');
        $this->buchung(-3000, self::IBAN_BERT);
        $this->buchung(3000, self::IBAN_ANNA);
        $konto = $this->erstattung(3000, 'Anna Auslage', self::IBAN_ANNA, weg: Zahlungsweg::Konto);

        $gefunden = $this->erstattungen()->buchungenFuerBeleg($this->tresor, $beleg, Zugriffsbereich::unbeschraenkt());

        self::assertSame([$passend], array_map(static fn($b): int => $b->id, $gefunden));
        self::assertSame([], $this->erstattungen()->buchungenFuerBeleg($this->tresor, $konto, Zugriffsbereich::unbeschraenkt()), 'not a reimbursement');
        self::assertSame([], $this->erstattungen()->buchungenFuerBeleg($this->tresor, $beleg, new Zugriffsbereich(von: new \DateTimeImmutable('2026-04-01'))), 'outside the period');
        self::assertSame([], $this->erstattungen()->buchungenFuerBeleg($this->tresor, $beleg, new Zugriffsbereich(kostenstellen: [1])), 'a cost-center scope sees no booking');

        // One receipt, one transfer: the 1:1 case of the same search.
        $vorschlag = $this->erstattungen()->vorschlag($this->tresor, $passend, Zugriffsbereich::unbeschraenkt()) ?? self::fail();
        self::assertSame([[$beleg]], array_map(self::ids(...), $vorschlag->kombinationen));
    }

    public function testOnlyOpenReimbursementsByTransferToThisIbanAreCandidates(): void
    {
        $passend = $this->erstattung(1000, 'Anna Auslage', self::IBAN_ANNA);
        $this->erstattung(1000, 'Anna Auslage', self::IBAN_ANNA, weg: Zahlungsweg::Konto);
        $this->erstattung(1000, 'Anna Auslage', self::IBAN_ANNA, art: 'bar');
        $this->erstattung(1000, 'Anna Auslage', self::IBAN_ANNA, status: DocumentStatus::Abgelehnt);
        $this->erstattung(1000, 'Anna Auslage', 'DE00123', datum: '2026-01-05');
        $buchung = $this->buchung(-1000, self::IBAN_ANNA);

        $vorschlag = $this->erstattungen()->vorschlag($this->tresor, $buchung, Zugriffsbereich::unbeschraenkt()) ?? self::fail();

        self::assertSame([$passend], self::ids($vorschlag->kandidaten));
    }

    public function testBookingsThatAreNoReimbursementSayWhy(): void
    {
        $this->erstattung(1000, 'Anna Auslage', self::IBAN_ANNA);
        $einnahme = $this->buchung(1000, self::IBAN_ANNA);
        $ohneIban = $this->buchung(-1000, '');

        foreach ([$einnahme, $ohneIban] as $buchung) {
            $vorschlag = $this->erstattungen()->vorschlag($this->tresor, $buchung, Zugriffsbereich::unbeschraenkt()) ?? self::fail();
            self::assertNotNull($vorschlag->hinweis);
            self::assertSame([], $vorschlag->kandidaten);
        }
        self::assertNull($this->erstattungen()->vorschlag($this->tresor, 999_999, Zugriffsbereich::unbeschraenkt()));
        self::assertNull($this->erstattungen()->vorschlag($this->tresor, $einnahme, new Zugriffsbereich(bis: new \DateTimeImmutable('2026-01-01'))), 'outside the period');
    }

    public function testAPartlyAllocatedTransferSearchesWhatIsLeft(): void
    {
        $schon = $this->erstattung(2000, 'Anna Auslage', self::IBAN_ANNA, datum: '2026-01-10');
        $a = $this->erstattung(1000, 'Anna Auslage', self::IBAN_ANNA);
        $b = $this->erstattung(2000, 'Anna Auslage', self::IBAN_ANNA);
        $buchung = $this->buchung(-5000, self::IBAN_ANNA);
        ZuordnungFabrik::bauen($this->pdo(), $this->audit)->zuordnen($this->tresor, $schon, $buchung, 2000, ZuordnungMethode::Manuell, null, null, $this->userId, self::IP, new \DateTimeImmutable());
        self::assertSame('teilweise', $this->zeile('bank_transaction', $buchung)['doc_status']);

        $vorschlag = $this->erstattungen()->vorschlag($this->tresor, $buchung, Zugriffsbereich::unbeschraenkt()) ?? self::fail();

        self::assertSame(3000, $vorschlag->offen);
        self::assertSame([[$a, $b]], array_map(self::ids(...), $vorschlag->kombinationen));
        $this->erstattungen()->zuordnen($this->tresor, $buchung, [$a, $b], Zugriffsbereich::unbeschraenkt(), $this->userId, self::IP, new \DateTimeImmutable());
        self::assertSame('zugeordnet', $this->zeile('bank_transaction', $buchung)['doc_status']);
    }

    public function testAllocatingChecksIbanSumScopeAndWritesNothingOnRefusal(): void
    {
        $anna = $this->erstattung(1000, 'Anna Auslage', self::IBAN_ANNA);
        $anna2 = $this->erstattung(500, 'Anna Auslage', self::IBAN_ANNA);
        $bert = $this->erstattung(500, 'Bert Beispiel', self::IBAN_BERT);
        $konto = $this->erstattung(500, 'Anna Auslage', self::IBAN_ANNA, weg: Zahlungsweg::Konto);
        $buchung = $this->buchung(-1500, self::IBAN_ANNA);
        $alle = Zugriffsbereich::unbeschraenkt();

        $faelle = [
            'another submitter' => [[$anna, $bert], $alle],
            'not the whole amount' => [[$anna], $alle],
            'not a reimbursement' => [[$anna, $konto], $alle],
            'receipt outside the scope' => [[$anna, $anna2], new Zugriffsbereich(von: new \DateTimeImmutable('2026-03-01'), bis: new \DateTimeImmutable('2026-03-31'))],
            'nothing chosen' => [[], $alle],
            'too many' => [range(900_001, 900_001 + Kombinationssuche::MAX_BELEGE), $alle],
        ];
        foreach ($faelle as $fall => [$belege, $bereich]) {
            try {
                $this->erstattungen()->zuordnen($this->tresor, $buchung, $belege, $bereich, $this->userId, self::IP, new \DateTimeImmutable());
                self::fail($fall . ' was accepted');
            } catch (BankRuleViolation) {
            }
            self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM allocation')->fetchColumn(), $fall);
            self::assertFalse($this->pdo()->inTransaction(), $fall);
        }
        self::assertSame('erstattung_offen', $this->zeile('invoice', $anna)['payment_status']);
        self::assertSame('fehlt', $this->zeile('bank_transaction', $buchung)['doc_status']);

        $this->erstattungen()->zuordnen($this->tresor, $buchung, [$anna, $anna2], $alle, $this->userId, self::IP, new \DateTimeImmutable());
        self::assertSame(2, (int) $this->pdo()->query('SELECT COUNT(*) FROM allocation')->fetchColumn());
    }

    public function testThePageShowsTheCombination(): void
    {
        $this->erstattung(2500, 'Anna Auslage', self::IBAN_ANNA, nummer: 'K-7');
        $this->erstattung(1500, 'Anna Auslage', self::IBAN_ANNA);
        $buchung = $this->buchung(-4000, self::IBAN_ANNA);
        $vorschlag = $this->erstattungen()->vorschlag($this->tresor, $buchung, Zugriffsbereich::unbeschraenkt()) ?? self::fail();

        $html = new View(dirname(__DIR__, 2) . '/app/views', '0.0.0-test')->render('app/erstattung', [
            'title' => 'Erstattung zuordnen',
            'flash' => null,
            'vorschlag' => $vorschlag,
            'csrf' => 'token',
        ], Area::App);

        self::assertStringContainsString('Vorschlag 1: 2 Belege', $html);
        self::assertStringContainsString('K-7', $html);
        self::assertStringContainsString('action="/app/buchungen/' . $buchung . '/erstattung"', $html);
        self::assertStringContainsString('Diese Belege zuordnen', $html);
    }

    private function erstattungen(): Erstattungen
    {
        return new Erstattungen(
            $this->pdo(),
            new ErstattungRepository($this->pdo()),
            new InvoiceRepository($this->pdo()),
            new BankTransactionRepository($this->pdo()),
            ZuordnungFabrik::bauen($this->pdo(), $this->audit),
        );
    }

    /**
     * @param list<Erstattungskandidat> $kandidaten
     *
     * @return list<int>
     */
    private static function ids(array $kandidaten): array
    {
        return array_map(static fn(Erstattungskandidat $k): int => $k->invoiceId, $kandidaten);
    }

    /**
     * A submitted receipt the way the public page and the review page leave
     * it: sealed submission with the reimbursement wish, document,
     * encrypted receipt. Returns the `invoice` id.
     */
    private function erstattung(
        int $brutto,
        string $name,
        string $iban,
        string $art = 'ueberweisung',
        Zahlungsweg $weg = Zahlungsweg::Erstattung,
        DocumentStatus $status = DocumentStatus::Geprueft,
        string $datum = '2026-02-14',
        string $nummer = '',
    ): int {
        ++$this->laufnummer;
        $einreichungen = new SubmissionRepository($this->pdo());
        $submission = $einreichungen->insertDraft(random_bytes(32), new \DateTimeImmutable());
        $schluessel = DataKey::generate();
        $payload = json_encode([
            'name' => $name,
            'email' => null,
            'erstattung' => ['art' => $art, 'iban' => $iban, 'kontoinhaber' => $name],
            'freitext' => '',
            'kostenstelle_hinweis' => null,
        ], JSON_THROW_ON_ERROR);
        $einreichungen->complete(
            $submission,
            sprintf('R-2026-%04d', $this->laufnummer),
            $this->tresor->sealDataKey($schluessel),
            FieldCipher::encrypt($schluessel, $payload, new FieldContext('submission', $submission, 'payload_enc')),
        );

        $dokument = new DocumentRepository($this->pdo())->insert(
            DocumentSource::Einreichung,
            $submission,
            [],
            $this->tresor->sealDataKey(DataKey::generate()),
            new \DateTimeImmutable()->modify('+' . $this->laufnummer . ' seconds'),
            $status,
        );
        $key = DataKey::generate();
        $rechnungen = new InvoiceRepository($this->pdo());
        $id = $rechnungen->insert(
            $dokument,
            new InvoiceStructure(InvoiceType::Rechnung, InvoiceDirection::Ausgabe, null, new \DateTimeImmutable($datum), null, null, null, null, null, $weg),
            $this->tresor->sealDataKey($key),
            null,
            $this->userId,
            new \DateTimeImmutable(),
        );
        $daten = new InvoiceData($nummer, $brutto, null, [], 'EUR', 'Auslage', '');
        $rechnungen->setData($id, FieldCipher::encrypt($key, json_encode($daten->toPayload(), JSON_THROW_ON_ERROR), new FieldContext('invoice', $id, 'data_enc')));

        return $id;
    }

    /** A booking as the statement import writes it - with `counterparty_bi`. */
    private function buchung(int $cent, string $iban, string $datum = '2026-03-02'): int
    {
        $repository = new BankTransactionRepository($this->pdo());
        $richtung = BankTransactionDirection::ausDemBetrag($cent);
        $key = DataKey::generate();
        $id = $repository->insert(
            $this->konto,
            null,
            new \DateTimeImmutable($datum),
            null,
            $richtung,
            $this->tresor->sealDataKey($key),
            random_bytes(32),
            $iban === '' ? null : $this->tresor->blindIndex()->forValue(KontoauszugImport::COUNTERPARTY_PURPOSE, $iban),
            BankTransactionDocStatus::fuerNeueBuchung($richtung === BankTransactionDirection::Ausgabe),
            $richtung === BankTransactionDirection::Ausgabe,
            BankTransactionSource::Import,
            new \DateTimeImmutable(),
        ) ?? self::fail();
        $repository->setCiphertext($id, FieldCipher::encrypt($key, (string) json_encode([
            'amount' => $cent, 'currency' => 'EUR', 'counterparty_name' => 'Anna Auslage', 'counterparty_iban' => $iban,
            'purpose' => 'Erstattung Auslagen', 'eref' => '', 'mref' => '', 'cred' => '', 'gvc' => '', 'booking_text' => 'SEPA-Überweisung',
        ]), new FieldContext('bank_transaction', $id, 'data_enc')));

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function zeile(string $tabelle, int $id): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM ' . $tabelle . ' WHERE id = ?');
        $stmt->execute([$id]);
        $zeile = $stmt->fetch();
        self::assertIsArray($zeile);

        return $zeile;
    }

    private function rohTabelle(string $tabelle): string
    {
        $roh = '';
        foreach ($this->pdo()->query('SELECT * FROM ' . $tabelle)->fetchAll() as $zeile) {
            $roh .= implode('|', array_map(static fn(mixed $w): string => (string) $w, $zeile)) . "\n";
        }

        return $roh;
    }
}
