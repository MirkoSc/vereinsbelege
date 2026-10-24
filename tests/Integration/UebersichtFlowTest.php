<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\App\UebersichtController;
use App\Config\Paths;
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
use App\Domain\SupplierRole;
use App\Domain\SystemRole;
use App\Domain\Zahlungsweg;
use App\Domain\ZuordnungMethode;
use App\Http\Cookie;
use App\Http\HttpMethod;
use App\Http\Kernel;
use App\Http\LoginGuard;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Http\Session;
use App\Http\StaticFileHandler;
use App\Repository\AuditLogRepository;
use App\Repository\BankAccountRepository;
use App\Repository\BankTransactionRepository;
use App\Repository\BlobRepository;
use App\Repository\CategoryRepository;
use App\Repository\CostCenterRepository;
use App\Repository\DocumentRepository;
use App\Repository\InvoiceRepository;
use App\Repository\RecurringSeriesRepository;
use App\Repository\RoleRepository;
use App\Repository\SettingRepository;
use App\Repository\SupplierRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\Service\Account\SessionVault;
use App\Service\Audit\AuditLog;
use App\Service\Bank\BankAccountService;
use App\Service\Bank\BelegFaelligkeit;
use App\Service\Bank\BelegStandard;
use App\Service\Bank\Buchungen;
use App\Service\Bank\Uebersichten;
use App\Service\Bank\Zuordnung;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Invoice\Serien;
use App\Service\Mail\MailSettingsRepository;
use App\Service\MasterData\SupplierService;
use App\Service\Migration\Migrator;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\KiAuslesungFabrik;
use App\Tests\Support\ZuordnungFabrik;
use App\View\View;

/**
 * The work lists "Offen" (M10-5, issue #69, docs/spec/
 * 04-bank-und-abgleich.md section 5 "Übersichten", E-37) through the real
 * route table, guard, services, schema and vault: unpaid receipts (due,
 * overdue, partly paid, sums, receipts from before the matching), open
 * reimbursements per submitter, bookings without a receipt (rules and
 * "kein Beleg nötig" respected, collective transfers), scopes and rights.
 * The expected receipts of series are covered in SerienFlowTest.
 * Synthetic data only.
 */
final class UebersichtFlowTest extends DatabaseTestCase
{
    private const string IP = '192.0.2.10';

    private Vault $tresor;
    private AuditLog $audit;
    private int $userId;
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
        $this->userId = new UserRepository($this->pdo())->insert('enc', random_bytes(32), 'enc', 'hash', mfaRequired: false);
        $this->rolle(SystemRole::Finanzen);

        new Session()->start();
        $jetzt = time();
        $_SESSION = ['user_id' => $this->userId, 'login_at' => $jetzt, 'last_seen_at' => $jetzt, 'session_epoch' => 0];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    // ------------------------------------------------------------ unpaid

    public function testUnpaidListsOpenAndPartlyPaidReceiptsDueFirst(): void
    {
        $heute = new \DateTimeImmutable('today');
        $lieferant = $this->lieferant('Sporthaus Beispiel');
        $ueberfaellig = $this->beleg(12000, faellig: $heute->modify('-3 days'), lieferant: $lieferant, nummer: 'RE-77');
        $bald = $this->beleg(5000, faellig: $heute->modify('+2 days'));
        $spaeter = $this->beleg(3000, faellig: $heute->modify('+30 days'));
        $ohne = $this->beleg(2000);
        $teil = $this->beleg(10000, faellig: $heute->modify('+40 days'));
        $einnahme = $this->beleg(25000, richtung: InvoiceDirection::Einnahme);
        $bezahlt = $this->beleg(999);
        $bar = $this->beleg(888, weg: Zahlungsweg::Bar);
        $keine = $this->beleg(777, weg: Zahlungsweg::Keine);
        $abgelehnt = $this->beleg(666, status: DocumentStatus::Abgelehnt);
        $erstattung = $this->beleg(555, weg: Zahlungsweg::Erstattung);

        $konto = $this->bankkonto();
        $this->zuordnen($teil, $this->buchung($konto, '2026-03-20', -4000), 4000);
        $this->zuordnen($bezahlt, $this->buchung($konto, '2026-03-21', -999), 999);

        $seite = $this->get('/app/offen/rechnungen');
        self::assertSame(200, $seite->status);
        $body = $seite->body;
        self::assertStringContainsString('Unbezahlte Rechnungen', $body);
        self::assertStringContainsString('aria-current="page">Unbezahlt</a>', $body);

        // Due date first, receipts without one last.
        $reihenfolge = array_map(fn(int $id): int|false => strpos($body, 'href="/app/belege/pruefen/' . $this->dokumentVon($id) . '"'), [$ueberfaellig, $bald, $spaeter, $teil, $ohne]);
        self::assertNotContains(false, $reihenfolge);
        $sortiert = $reihenfolge;
        sort($sortiert);
        self::assertSame($sortiert, $reihenfolge, 'overdue, due, later, partly paid, without due date');
        self::assertStringContainsString('Sporthaus Beispiel', $body);
        self::assertStringContainsString('RE-77', $body);
        self::assertStringContainsString('<span class="marke marke-fehler">überfällig</span>', $body);
        self::assertStringContainsString('<span class="marke marke-warnung">fällig</span>', $body);
        self::assertStringContainsString('1 Rechnung ist überfällig.', $body);
        self::assertStringContainsString('teilweise bezahlt', $body);
        self::assertStringContainsString('60,00 EUR', $body, 'what is still open of the partly paid one');

        // Sums of what is open, per direction: 120 + 50 + 30 + 20 + 60 = 280.
        self::assertStringContainsString('Noch zu zahlen:</strong>', $body);
        self::assertStringContainsString('280,00 EUR', $body);
        self::assertStringContainsString('Noch zu erhalten:</strong>', $body);
        self::assertStringContainsString('250,00 EUR', $body);
        self::assertStringContainsString('href="/app/belege/pruefen/' . $this->dokumentVon($einnahme) . '"', $body);

        foreach ([$bezahlt, $bar, $keine, $abgelehnt, $erstattung] as $nicht) {
            self::assertStringNotContainsString('href="/app/belege/pruefen/' . $this->dokumentVon($nicht) . '"', $body, 'paid, cash, no payment, rejected and reimbursements are not unpaid invoices');
        }
    }

    public function testDueDatesAreClassified(): void
    {
        $heute = new \DateTimeImmutable('2026-05-10 15:00');
        self::assertSame(BelegFaelligkeit::OhneDatum, BelegFaelligkeit::fuer(null, $heute));
        self::assertSame(BelegFaelligkeit::Ueberfaellig, BelegFaelligkeit::fuer(new \DateTimeImmutable('2026-05-09'), $heute));
        self::assertSame(BelegFaelligkeit::Faellig, BelegFaelligkeit::fuer(new \DateTimeImmutable('2026-05-10'), $heute), 'due today is not overdue yet');
        self::assertSame(BelegFaelligkeit::Faellig, BelegFaelligkeit::fuer(new \DateTimeImmutable('2026-05-17'), $heute));
        self::assertSame(BelegFaelligkeit::Offen, BelegFaelligkeit::fuer(new \DateTimeImmutable('2026-05-18'), $heute));
    }

    public function testReceiptsFromBeforeTheMatchingAreAListOfTheirOwn(): void
    {
        $neu = $this->beleg(1000);
        $alt = $this->beleg(2000);
        $this->pdo()->prepare("UPDATE invoice SET created_at = '2020-01-01 00:00:00', updated_at = '2020-01-01 00:00:00' WHERE id = ?")->execute([$alt]);

        $body = $this->get('/app/offen/rechnungen')->body;
        self::assertStringContainsString('href="/app/belege/pruefen/' . $this->dokumentVon($neu) . '"', $body);
        self::assertStringNotContainsString('href="/app/belege/pruefen/' . $this->dokumentVon($alt) . '"', $body);
        self::assertStringContainsString('1 Beleg wurde vor Einführung des Abgleichs erfasst', $body);
        self::assertStringContainsString('href="/app/offen/rechnungen?altbestand=1"', $body);
        self::assertStringNotContainsString('20,00 EUR', $body, 'not in the sum either');

        $altbestand = $this->get('/app/offen/rechnungen', ['altbestand' => '1'])->body;
        self::assertStringContainsString('href="/app/belege/pruefen/' . $this->dokumentVon($alt) . '"', $altbestand);
        self::assertStringNotContainsString('href="/app/belege/pruefen/' . $this->dokumentVon($neu) . '"', $altbestand);
        self::assertStringContainsString('bevor es den Abgleich gab', $altbestand);

        // Saved again in the review page after the matching came: no longer old.
        $this->pdo()->prepare('UPDATE invoice SET updated_at = ? WHERE id = ?')->execute([new \DateTimeImmutable('+1 minute')->format('Y-m-d H:i:s'), $alt]);
        $wieder = $this->get('/app/offen/rechnungen')->body;
        self::assertStringContainsString('href="/app/belege/pruefen/' . $this->dokumentVon($alt) . '"', $wieder);
        self::assertStringNotContainsString('vor Einführung des Abgleichs', $wieder);
    }

    // ---------------------------------------------------- reimbursements

    public function testOpenReimbursementsAreGroupedPerSubmitterWithTheirSum(): void
    {
        $erste = $this->beleg(1250, weg: Zahlungsweg::Erstattung, einreichung: ['name' => 'Erika Beispiel', 'iban' => 'DE02120300000000202051']);
        $zweite = $this->beleg(4000, weg: Zahlungsweg::Erstattung, einreichung: ['name' => ' erika  beispiel', 'iban' => 'de02 1203 0000 0000 2020 51']);
        $andere = $this->beleg(700, weg: Zahlungsweg::Erstattung, einreichung: ['name' => 'Max Muster', 'iban' => '']);
        $intern = $this->beleg(300, weg: Zahlungsweg::Erstattung);
        $erstattet = $this->beleg(900, weg: Zahlungsweg::Erstattung, einreichung: ['name' => 'Max Muster', 'iban' => '']);
        $konto = $this->beleg(5000);

        $girokonto = $this->bankkonto();
        $this->zuordnen($zweite, $this->buchung($girokonto, '2026-03-20', -1000), 1000);
        $this->zuordnen($erstattet, $this->buchung($girokonto, '2026-03-21', -900), 900);

        $body = $this->get('/app/offen/erstattungen')->body;
        self::assertStringContainsString('Offene Erstattungen', $body);
        self::assertSame(1, substr_count($body, '<h3>Erika Beispiel</h3>'), 'one group, case and spacing ignored');
        self::assertStringContainsString('42,50 EUR', $body, '12,50 + (40,00 - 10,00)');
        self::assertStringContainsString('IBAN laut Einreichung: <strong>DE02120300000000202051</strong>', $body);
        self::assertStringNotContainsString('hinweis-warnung', $body, 'one name, one account: nothing to warn about');
        self::assertStringContainsString('<h3>Max Muster</h3>', $body);
        self::assertStringContainsString('<h3>Ohne Angabe des Einreichers</h3>', $body);
        self::assertGreaterThan(strpos($body, '<h3>Max Muster</h3>'), strpos($body, '<h3>Ohne Angabe des Einreichers</h3>'), 'without a name last');
        foreach ([$erste, $zweite, $andere, $intern] as $id) {
            self::assertStringContainsString('href="/app/belege/pruefen/' . $this->dokumentVon($id) . '"', $body);
        }
        foreach ([$erstattet, $konto] as $id) {
            self::assertStringNotContainsString('href="/app/belege/pruefen/' . $this->dokumentVon($id) . '"', $body);
        }
    }

    public function testAnotherIbanUnderTheSameNameIsItsOwnGroupWithAWarning(): void
    {
        // The public submission lets anybody use any name: a second
        // submission under Erika's name with a foreign account must not end
        // up next to her sum.
        $echt = $this->beleg(1250, weg: Zahlungsweg::Erstattung, einreichung: ['name' => 'Erika Beispiel', 'iban' => 'DE02120300000000202051']);
        $fremd = $this->beleg(99900, weg: Zahlungsweg::Erstattung, einreichung: ['name' => 'Erika Beispiel', 'iban' => 'DE89370400440532013000']);

        $body = $this->get('/app/offen/erstattungen')->body;
        self::assertSame(2, substr_count($body, '<h3>Erika Beispiel</h3>'), 'one group per account');
        self::assertSame(2, substr_count($body, 'Unter diesem Namen gibt es Einreichungen mit unterschiedlicher oder ohne IBAN'));
        $erste = strpos($body, 'DE02120300000000202051');
        $zweite = strpos($body, 'DE89370400440532013000');
        self::assertNotFalse($erste);
        self::assertNotFalse($zweite);
        // Each sum sits in the card of its own account.
        $karteEcht = substr($body, (int) strrpos(substr($body, 0, $erste), '<section class="karte">'), 2000);
        self::assertStringContainsString('12,50 EUR', $karteEcht);
        self::assertStringNotContainsString('999,00', $karteEcht);
        self::assertStringNotContainsString('1.011,50', $body, 'never one sum across both accounts');
        foreach ([$echt, $fremd] as $id) {
            self::assertStringContainsString('href="/app/belege/pruefen/' . $this->dokumentVon($id) . '"', $body);
        }
    }

    // ------------------------------------------------ bookings w/o receipt

    public function testBookingsWithoutAReceiptRespectRulesAndCollectiveTransfers(): void
    {
        $konto = $this->bankkonto();
        $fehlt = $this->buchung($konto, '2026-03-02', -4990, 'Rasenmäher Wartung', 'Gartenbau Beispiel');
        $einnahme = $this->buchung($konto, '2026-03-03', 15000, 'Beitrag', 'Mitglied');
        $nichtNoetig = $this->buchung($konto, '2026-03-04', -500, 'Kontoführung', 'Bank');
        $this->pdo()->prepare("UPDATE bank_transaction SET doc_required = 0, doc_status = 'nicht_noetig', doc_source = 'manuell' WHERE id = ?")->execute([$nichtNoetig]);
        $sammel = $this->buchung($konto, '2026-03-05', -15000, 'RE-1 RE-2', 'Bauhaus');
        $belegt = $this->buchung($konto, '2026-03-06', -2000, 'RE-3', 'Bauhaus');
        $this->zuordnen($this->beleg(10000), $sammel, 10000);
        $this->zuordnen($this->beleg(2000), $belegt, 2000);

        $body = $this->get('/app/offen/buchungen')->body;
        self::assertStringContainsString('Buchungen ohne Beleg', $body);
        self::assertStringContainsString('2 Buchungen, neueste zuerst', $body);
        self::assertLessThan(strpos($body, 'href="/app/buchungen/' . $fehlt . '"'), strpos($body, 'href="/app/buchungen/' . $sammel . '"'), 'newest first');
        self::assertStringContainsString('Gartenbau Beispiel', $body);
        self::assertStringContainsString('teilweise belegt', $body);
        self::assertStringContainsString('50,00 EUR', $body, 'the part of the collective transfer no receipt explains');
        self::assertStringContainsString('href="/app/belege/neu">Beleg hochladen</a>', $body);
        self::assertStringContainsString('<summary>Beleg anfordern</summary>', $body);
        self::assertStringContainsString('02.03.2026, 49,90 EUR, Gartenbau Beispiel – Rasenmäher Wartung', $body);
        self::assertStringContainsString('Bitte reiche ihn über unser Formular zur Belegeinreichung ein', $body, 'no checked public URL: no link from the Host header');
        self::assertStringNotContainsString('localhost', $body);

        new SettingRepository($this->pdo())->set('oeffentliche_url', 'https://belege.example.org');
        self::assertStringContainsString('Bitte reiche ihn über https://belege.example.org/einreichen ein', $this->get('/app/offen/buchungen')->body);
        foreach ([$einnahme, $nichtNoetig, $belegt] as $id) {
            self::assertStringNotContainsString('href="/app/buchungen/' . $id . '"', $body);
        }
    }

    // ------------------------------------------------- rights and scopes

    public function testACostCenterScopeSeesOnlyItsReceiptsAndNoBookings(): void
    {
        $kostenstellen = new CostCenterRepository($this->pdo());
        $jugend = $kostenstellen->create('Jugend');
        $heim = $kostenstellen->create('Vereinsheim');
        $eigen = $this->beleg(1000, kostenstelle: $jugend);
        $fremd = $this->beleg(2000, kostenstelle: $heim);
        $this->buchung($this->bankkonto(), '2026-03-02', -4990);

        $this->rolle(SystemRole::Vereinsverantwortlicher);
        new UserAccessRepository($this->pdo())->setCostCenters($this->userId, [$jugend]);

        $body = $this->get('/app/offen/rechnungen')->body;
        self::assertStringContainsString('href="/app/posteingang/' . $this->dokumentVon($eigen) . '"', $body, 'without document.edit the receipt opens read-only in the inbox');
        self::assertStringNotContainsString('/app/belege/pruefen/', $body);
        self::assertStringNotContainsString('href="/app/posteingang/' . $this->dokumentVon($fremd) . '"', $body);
        self::assertStringNotContainsString('20,00 EUR', $body);
        self::assertStringNotContainsString('/app/offen/buchungen', $body, 'no tab for a list it may not open');
        self::assertStringNotContainsString('/app/offen/erwartet', $body);

        self::assertSame(403, $this->get('/app/offen/buchungen')->status);
        self::assertSame(403, $this->get('/app/offen/erwartet')->status);
        $start = $this->get('/app/offen');
        self::assertSame(302, $start->status);
        self::assertSame('/app/offen/rechnungen', $start->headers['Location'] ?? null);
    }

    public function testWithoutTheVaultNothingIsShown(): void
    {
        $this->beleg(4711, faellig: new \DateTimeImmutable('today'));
        $this->buchung($this->bankkonto(), '2026-03-02', -4990, 'Wartung', 'Gartenbau Beispiel');

        foreach (['/app/offen/rechnungen', '/app/offen/erstattungen', '/app/offen/buchungen'] as $pfad) {
            $seite = $this->get($pfad, entsperrt: false);
            self::assertSame(200, $seite->status, $pfad);
            self::assertStringContainsString('nur mit entsperrtem Tresor lesbar', $seite->body, $pfad);
            self::assertStringNotContainsString('47,11', $seite->body, $pfad);
            self::assertStringNotContainsString('Gartenbau', $seite->body, $pfad);
        }
    }

    // ----------------------------------------------------------- helpers

    private function rolle(SystemRole $rolle): void
    {
        $rollen = new RoleRepository($this->pdo());
        $id = $rollen->findSystem($rolle)?->id;
        assert($id !== null);
        $rollen->assignToUser($this->userId, [$id]);
    }

    /**
     * @param array{name: string, iban: string}|null $einreichung
     */
    private function beleg(
        int $brutto,
        InvoiceDirection $richtung = InvoiceDirection::Ausgabe,
        Zahlungsweg $weg = Zahlungsweg::Konto,
        DocumentStatus $status = DocumentStatus::Geprueft,
        ?\DateTimeImmutable $faellig = null,
        ?int $lieferant = null,
        string $nummer = '',
        ?int $kostenstelle = null,
        ?array $einreichung = null,
    ): int {
        ++$this->laufnummer;
        $submissionId = $einreichung === null ? null : $this->einreichung($einreichung['name'], $einreichung['iban']);
        $dokument = new DocumentRepository($this->pdo())->insert(
            $submissionId === null ? DocumentSource::Intern : DocumentSource::Einreichung,
            $submissionId,
            [],
            $this->tresor->sealDataKey(DataKey::generate()),
            new \DateTimeImmutable()->modify('+' . $this->laufnummer . ' seconds'),
            $status,
            costCenterId: $kostenstelle,
            createdBy: $this->userId,
        );
        $schluessel = DataKey::generate();
        $rechnungen = new InvoiceRepository($this->pdo());
        $id = $rechnungen->insert(
            $dokument,
            new InvoiceStructure(InvoiceType::Rechnung, $richtung, $lieferant, new \DateTimeImmutable('2026-03-14'), $faellig, null, null, null, $kostenstelle, $weg),
            $this->tresor->sealDataKey($schluessel),
            null,
            $this->userId,
            new \DateTimeImmutable(),
        );
        $daten = new InvoiceData($nummer, $brutto, null, [], 'EUR', '', '');
        $rechnungen->setData($id, FieldCipher::encrypt($schluessel, json_encode($daten->toPayload(), JSON_THROW_ON_ERROR), new FieldContext('invoice', $id, 'data_enc')));

        return $id;
    }

    private function einreichung(string $name, string $iban): int
    {
        $this->pdo()->prepare("INSERT INTO submission (form_hash, received_at, status) VALUES (?, NOW(), 'eingegangen')")->execute([random_bytes(32)]);
        $id = (int) $this->pdo()->lastInsertId();
        $key = DataKey::generate();
        $payload = json_encode([
            'name' => $name, 'email' => null, 'freitext' => '', 'kostenstelle_hinweis' => null,
            'erstattung' => ['art' => 'ueberweisung', 'iban' => $iban, 'kontoinhaber' => $name],
        ], JSON_THROW_ON_ERROR);
        $this->pdo()->prepare('UPDATE submission SET dek_sealed = ?, payload_enc = ? WHERE id = ?')->execute([
            $this->tresor->sealDataKey($key),
            FieldCipher::encrypt($key, $payload, new FieldContext('submission', $id, 'payload_enc')),
            $id,
        ]);

        return $id;
    }

    private function dokumentVon(int $invoiceId): int
    {
        return new InvoiceRepository($this->pdo())->find($invoiceId)?->documentId ?? self::fail('no invoice');
    }

    private function lieferant(string $name): int
    {
        return $this->lieferanten()->anlegen($this->tresor, ['name' => $name, 'rolle' => SupplierRole::Lieferant->value], new \DateTimeImmutable())->id;
    }

    private function lieferanten(): SupplierService
    {
        return new SupplierService($this->pdo(), new SupplierRepository($this->pdo()), new CategoryRepository($this->pdo()));
    }

    private function bankkonto(): int
    {
        return $this->konten()->anlegen($this->tresor, BankAccountKind::Bank, ['name' => 'Girokonto', 'iban' => '', 'bic' => '', 'bank' => '', 'opening_balance' => '0,00', 'opening_date' => '2026-01-01'], new \DateTimeImmutable())->id;
    }

    private function konten(): BankAccountService
    {
        return new BankAccountService($this->pdo(), new BankAccountRepository($this->pdo()));
    }

    private function buchung(int $konto, string $datum, int $cent, string $zweck = 'Zweck', string $name = 'Gegenseite'): int
    {
        $repository = new BankTransactionRepository($this->pdo());
        $richtung = BankTransactionDirection::ausDemBetrag($cent);
        $key = DataKey::generate();
        $id = $repository->insert(
            $konto,
            null,
            new \DateTimeImmutable($datum),
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
            'amount' => $cent, 'currency' => 'EUR', 'counterparty_name' => $name, 'counterparty_iban' => '',
            'purpose' => $zweck, 'eref' => '', 'mref' => '', 'cred' => '', 'gvc' => '', 'booking_text' => 'SEPA-Überweisung',
        ]), new FieldContext('bank_transaction', $id, 'data_enc')));

        return $id;
    }

    private function zuordnen(int $beleg, int $buchung, int $betrag): void
    {
        $this->zuordnung()->zuordnen($this->tresor, $beleg, $buchung, $betrag, ZuordnungMethode::Manuell, null, null, $this->userId, self::IP, new \DateTimeImmutable());
    }

    private function zuordnung(): Zuordnung
    {
        return ZuordnungFabrik::bauen($this->pdo(), $this->audit);
    }

    /**
     * @param array<string, string> $query
     */
    private function get(string $pfad, array $query = [], bool $entsperrt = true): Response
    {
        $antwort = $this->kernel()->handle(new Request(HttpMethod::Get, $pfad, query: $query, cookies: $entsperrt ? $this->tresorCookie() : []));
        self::assertInstanceOf(Response::class, $antwort);

        return $antwort;
    }

    /**
     * @return array<string, string>
     */
    private function tresorCookie(): array
    {
        return [Cookie::vaultKey('x', Request::httpsFromGlobals())->name => new SessionVault()->store($this->tresor)];
    }

    private function kernel(): Kernel
    {
        $paths = new Paths(dirname(__DIR__, 2));
        $view = new View($paths->viewsDir(), '0.0.0-test');
        $pdo = $this->pdo();

        $guard = static fn(): LoginGuard => new LoginGuard(
            new Session(),
            $view,
            static fn(): SessionTimeouts => new SessionTimeouts(),
            static function (int $id) use ($pdo): ?SessionUser {
                $user = new UserRepository($pdo)->findById($id);

                return $user === null ? null : new SessionUser($user, 'Test', new UserAccessRepository($pdo)->berechtigungen($id));
            },
        );
        $offen = function () use ($view, $pdo): UebersichtController {
            $lieferanten = new SupplierRepository($pdo);
            $kategorien = new CategoryRepository($pdo);
            $blobs = new BlobRepository($pdo);
            $blobService = new BlobService($blobs, new DbBlobBackend($blobs), new FsBlobBackend(sys_get_temp_dir() . '/vb_offen_unbenutzt'));
            $crypto = new ServerCrypto((string) base64_decode(self::configData()['server_key'], true));

            return new UebersichtController(
                $view,
                new Session(),
                new SessionVault(),
                new Uebersichten(
                    new InvoiceRepository($pdo),
                    $this->lieferanten(),
                    $this->zuordnung(),
                    new Buchungen($pdo, new BankTransactionRepository($pdo), $this->konten(), $kategorien, BelegStandard::fromSettings(new SettingRepository($pdo))),
                ),
                new Serien(
                    $pdo,
                    new RecurringSeriesRepository($pdo),
                    new InvoiceRepository($pdo),
                    new DocumentRepository($pdo),
                    $lieferanten,
                    $this->lieferanten(),
                    KiAuslesungFabrik::bauen($pdo, $blobService, $crypto),
                    new SettingRepository($pdo),
                    $this->audit,
                ),
                new MailSettingsRepository(new SettingRepository($pdo), $crypto),
            );
        };
        $unerreichbar = static fn(): never => throw new \LogicException('Diese Route gehört nicht zu diesem Test.');

        $router = new Router();
        (require dirname(__DIR__, 2) . '/app/src/routes.php')(
            $router,
            $view,
            $unerreichbar,
            $guard,
            ...array_fill(0, 36, $unerreichbar),
            nextcloudAdmin: $unerreichbar,
            offen: $offen,
        );

        return new Kernel(
            router: $router,
            staticFiles: new StaticFileHandler($paths->publicDir(), longCache: false),
            view: $view,
            debug: true,
        );
    }
}
