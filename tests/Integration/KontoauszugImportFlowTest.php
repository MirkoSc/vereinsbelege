<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\App\KontoauszugController;
use App\Config\Paths;
use App\Domain\AuditAction;
use App\Domain\AuditEntry;
use App\Domain\BalanceCheck;
use App\Domain\BankAccountKind;
use App\Domain\BankImportStatus;
use App\Domain\Iban;
use App\Domain\SystemRole;
use App\Http\Cookie;
use App\Http\HttpMethod;
use App\Http\Kernel;
use App\Http\LoginGuard;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Http\Session;
use App\Http\StaticFileHandler;
use App\Repository\AssignmentRuleRepository;
use App\Repository\AuditLogRepository;
use App\Repository\BankAccountRepository;
use App\Repository\BankImportRepository;
use App\Repository\BankTransactionRepository;
use App\Repository\BlobRepository;
use App\Repository\CategoryRepository;
use App\Repository\CsvProfileRepository;
use App\Repository\RoleRepository;
use App\Repository\SettingRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\Service\Account\SessionVault;
use App\Service\Audit\AuditFilter;
use App\Service\Audit\AuditLog;
use App\Service\Bank\BankAccountService;
use App\Service\Bank\Buchungsregeln;
use App\Service\Bank\Import\Dedupschluessel;
use App\Service\Bank\Import\ImportFormat;
use App\Service\Bank\Import\KontoauszugImport;
use App\Service\Bank\Import\KontoauszugLeser;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Migration\Migrator;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Tests\Support\DatabaseTestCase;
use App\View\View;

/**
 * The statement import end to end (M9-4, issue #62, docs/spec/
 * 04-bank-und-abgleich.md section 4 and "Pflicht-Tests": Duplikaterkennung
 * bei überlappendem und doppeltem Import (Idempotenz), Rechte, Einnahme-
 * Default „kein Beleg nötig“, Sparkasse- und VR-Bank-Fixtures für MT940 und
 * CSV-CAMT): the real route table and guard, the real controller, service,
 * repositories and schema.
 */
final class KontoauszugImportFlowTest extends DatabaseTestCase
{
    private const string MT940 = __DIR__ . '/../fixtures/mt940/';

    private const string CSV = __DIR__ . '/../fixtures/bank/';

    private Vault $tresor;
    private AuditLog $audit;
    private int $userId;

    /** Bookings per step - small, so even the fixtures need several steps. */
    private int $schrittPosten = 2;

    /** @var list<string> temp files standing in for PHP's uploads */
    private array $uploads = [];

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->tresor = Vault::create();
        new VaultRepository($this->pdo())->insert($this->tresor->publicKey());
        $this->audit = new AuditLog(new AuditLogRepository($this->pdo()), new VaultRepository($this->pdo()), new ServerCrypto(random_bytes(32)));

        $this->userId = new UserRepository($this->pdo())->insert('enc', random_bytes(32), 'enc', 'hash', mfaRequired: false);
        $rollen = new RoleRepository($this->pdo());
        $rollen->assignToUser($this->userId, [(int) $rollen->findSystem(SystemRole::Finanzen)?->id]);

        new Session()->start();
        $jetzt = time();
        $_SESSION = ['user_id' => $this->userId, 'login_at' => $jetzt, 'last_seen_at' => $jetzt, 'session_epoch' => 0];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        foreach ($this->uploads as $pfad) {
            if (is_file($pfad)) {
                unlink($pfad);
            }
        }
    }

    public function testAnMt940FileIsPreviewedThenImportedEncryptedInSteps(): void
    {
        $konto = $this->bankkonto('Girokonto Sparkasse', self::sparkasseIban(), '1.523,40', '2024-03-11');

        $antwort = $this->hochladen(self::sparkasse());
        $id = self::importId($antwort);

        // The preview writes nothing but the import row and the encrypted file.
        self::assertSame(0, $this->anzahl('bank_transaction'));
        $import = $this->importe()->find($id);
        self::assertNotNull($import);
        self::assertSame(BankImportStatus::Vorschau, $import->status);
        self::assertSame($konto, $import->accountId, 'The account follows from the bank code and account number of :25:.');
        self::assertSame('mt940', $import->format);
        self::assertSame('2024-03-11', $import->periodFrom?->format('Y-m-d'));
        self::assertSame('2024-03-12', $import->periodTo?->format('Y-m-d'));
        $roh = $this->roh('bank_import') . $this->roh('file_blob') . $this->roh('file_blob_chunk');
        foreach (['Getr', 'Sch', 'RE-2024', 'DE40120505550001234567', 'STARTUMSE', 'auszug.sta'] as $klartext) {
            self::assertStringNotContainsString($klartext, $roh, $klartext . ' must only be stored encrypted');
        }

        $seite = $this->get('/app/konten/import/' . $id);
        self::assertSame(200, $seite->status);
        self::assertStringContainsString('11.03.2024 – 12.03.2024', $seite->body);
        self::assertStringContainsString('Girokonto Sparkasse', $seite->body);
        self::assertSame(['5', '5', '0'], self::zahlen($seite->body, ['Buchungen in der Datei', 'davon neu', 'davon schon vorhanden (Duplikat)']));
        self::assertStringContainsString('Salden stimmen', $seite->body);
        self::assertStringContainsString('Anschluss an den Kontostand vor dem 11.03.2024', $seite->body);
        self::assertStringContainsString('Übernehmen', $seite->body);
        self::assertStringContainsString('(5 neue Buchungen)', $seite->body);
        self::assertStringContainsString('Getränke Müller GmbH', $seite->body, 'The preview shows the bookings in the session.');
        self::assertStringNotContainsString('/js/kontoauszug.js', $seite->body);

        self::assertSame(302, $this->post('/app/konten/import/' . $id . '/uebernehmen')->status);
        $laufend = $this->get('/app/konten/import/' . $id);
        self::assertStringContainsString('/js/kontoauszug.js', $laufend->body);
        self::assertStringContainsString('data-gesamt="5"', $laufend->body);

        $schritte = $this->alleSchritte($id);
        self::assertSame([['laeuft', 2], ['laeuft', 4], ['fertig', 5]], array_map(static fn(array $s): array => [$s['status'], $s['verarbeitet']], $schritte));

        $import = $this->importe()->find($id);
        self::assertSame(BankImportStatus::Fertig, $import?->status);
        self::assertSame(BalanceCheck::Ok, $import->balanceCheck);
        self::assertSame(['gesamt' => 5, 'neu' => 5, 'duplikat' => 0, 'fehler' => 0, 'vorgemerkt' => 0, 'vor_stichtag' => 0], $import->stats?->toArray());
        self::assertSame($this->userId, $import->importedBy);

        $zeilen = $this->buchungen($konto);
        self::assertCount(5, $zeilen);
        $roh = $this->roh('bank_transaction');
        foreach (['Getr', 'Müller', 'Schäfer', 'DE40120505550001234567', 'RE-2024-0311-77', 'FOLGELASTSCHRIFT', 'Kontoführung'] as $klartext) {
            self::assertStringNotContainsString($klartext, $roh, $klartext . ' must only be stored encrypted');
        }

        // Blind indexes as the vault computes them.
        $datei = new KontoauszugLeser()->lies(self::sparkasse(), ImportFormat::mt940(), []);
        self::assertSame(Dedupschluessel::fuer($this->tresor->blindIndex(), $konto, $datei->posten), array_column($zeilen, 'dedup_bi'));
        self::assertSame(
            $this->tresor->blindIndex()->forValue(KontoauszugImport::COUNTERPARTY_PURPOSE, 'DE40120505550001234567'),
            $zeilen[0]['counterparty_bi'],
        );

        // Expense needs a receipt, income does not (E-17).
        self::assertSame(
            [['ausgabe', 1, 'fehlt'], ['einnahme', 0, 'nicht_noetig'], ['ausgabe', 1, 'fehlt'], ['einnahme', 0, 'nicht_noetig'], ['ausgabe', 1, 'fehlt']],
            array_map(static fn(array $z): array => [$z['direction'], (int) $z['doc_required'], $z['doc_status']], $zeilen),
        );
        self::assertSame(['import'], array_values(array_unique(array_column($zeilen, 'source'))));
        self::assertSame(['2024-03-11', '2024-03-11'], [$zeilen[0]['booking_date'], $zeilen[0]['value_date']]);

        self::assertSame([
            'amount' => -4590,
            'currency' => 'EUR',
            'counterparty_name' => 'Getränke Müller GmbH & Co. KG Musterstadt',
            'counterparty_iban' => 'DE40120505550001234567',
            'purpose' => 'Getränke Müller Rechnung 4711 Vereinsheim Kd-Nr 1234',
            'eref' => 'RE-2024-0311-77',
            'mref' => 'M-0815',
            'cred' => 'DE98ZZZ09999999999',
            'gvc' => '105',
            'booking_text' => 'FOLGELASTSCHRIFT',
        ], $this->daten($zeilen[0]));

        $protokoll = $this->protokoll($id);
        self::assertCount(1, $protokoll);
        self::assertSame(AuditAction::KontoauszugImportiert, $protokoll[0]->aktion());
        self::assertSame($this->userId, $protokoll[0]->userId);
        self::assertSame(
            ['format' => 'mt940', 'gesamt' => 5, 'neu' => 5, 'duplikat' => 0, 'fehler' => 0, 'vorgemerkt' => 0, 'vor_stichtag' => 0, 'saldenpruefung' => 'ok'],
            $this->audit->details($protokoll[0], $this->tresor),
        );

        $ergebnis = $this->get('/app/konten/import/' . $id);
        self::assertStringContainsString('Übernommen: 5 neue Buchungen, 0 waren schon vorhanden.', $ergebnis->body);
        self::assertStringNotContainsString('/js/kontoauszug.js', $ergebnis->body);

        $verlauf = $this->get('/app/konten/import');
        self::assertStringContainsString('Girokonto Sparkasse', $verlauf->body);
        self::assertStringContainsString('Übernommen', $verlauf->body);
        self::assertStringContainsString('11.03.2024 – 12.03.2024', $verlauf->body);
    }

    /**
     * M9-6 (issue #64): every new booking gets the default of its
     * direction, then the oldest matching active rule on top - here the
     * account fee by its word, the drinks supplier by its IBAN (expenses
     * only, so the returned direct debit stays as it is). The setting makes
     * income need a receipt.
     */
    public function testTheImportAppliesTheOldestMatchingRule(): void
    {
        $konto = $this->bankkonto('Girokonto Sparkasse', self::sparkasseIban(), '1.523,40', '2024-03-11');
        $regeln = $this->regeln();
        $jetzt = new \DateTimeImmutable();
        $gebuehr = $regeln->anlegen($this->tresor, ['bezeichnung' => 'Kontoführung', 'stichwort' => 'kontoführung', 'gegenseite' => '',
            'richtung' => 'ausgabe', 'kein_beleg' => '1', 'kategorie' => (string) $this->kategorie('Bankgebühren')], $this->userId, $jetzt);
        $regeln->anlegen($this->tresor, ['bezeichnung' => 'Später', 'stichwort' => 'Entgelt', 'gegenseite' => '',
            'richtung' => 'ausgabe', 'kein_beleg' => '', 'kategorie' => (string) $this->kategorie('Sonstiges')], $this->userId, $jetzt);
        $getraenke = $regeln->anlegen($this->tresor, ['bezeichnung' => 'Getränke', 'stichwort' => '', 'gegenseite' => 'DE40 1205 0555 0001 2345 67',
            'richtung' => 'ausgabe', 'kein_beleg' => '', 'kategorie' => (string) $this->kategorie('Verpflegung & Bewirtung')], $this->userId, $jetzt);
        $inaktiv = $regeln->anlegen($this->tresor, ['bezeichnung' => 'Aus', 'stichwort' => 'Spende', 'gegenseite' => '',
            'richtung' => '', 'kein_beleg' => '1', 'kategorie' => ''], $this->userId, $jetzt);
        $regeln->aktivieren($regeln->finde($this->tresor, $inaktiv) ?? self::fail(), false, $jetzt);
        new SettingRepository($this->pdo())->set('buchung_einnahme_beleg_noetig', '1');

        $this->importiere(self::sparkasse());

        self::assertSame([
            ['ausgabe', 1, 'fehlt', 'standard', $this->kategorie('Verpflegung & Bewirtung'), 'regel', $getraenke],
            ['einnahme', 1, 'fehlt', 'standard', null, null, null],
            ['ausgabe', 1, 'fehlt', 'standard', null, null, null],
            ['einnahme', 1, 'fehlt', 'standard', null, null, null],
            ['ausgabe', 0, 'nicht_noetig', 'regel', $this->kategorie('Bankgebühren'), 'regel', $gebuehr],
        ], array_map(static fn(array $z): array => [
            $z['direction'], (int) $z['doc_required'], $z['doc_status'], $z['doc_source'],
            $z['category_id'] === null ? null : (int) $z['category_id'], $z['category_source'],
            $z['rule_id'] === null ? null : (int) $z['rule_id'],
        ], $this->buchungen($konto)));
    }

    public function testTheSameExportTwiceAddsNothing(): void
    {
        $konto = $this->bankkonto('Girokonto Sparkasse', self::sparkasseIban(), '1.523,40', '2024-03-11');
        $this->importiere(self::sparkasse());

        $id = self::importId($this->hochladen(self::sparkasse()));
        $seite = $this->get('/app/konten/import/' . $id);
        self::assertSame(['5', '0', '5'], self::zahlen($seite->body, ['Buchungen in der Datei', 'davon neu', 'davon schon vorhanden (Duplikat)']));
        self::assertStringContainsString('(0 neue Buchungen)', $seite->body);

        $this->post('/app/konten/import/' . $id . '/uebernehmen');
        $this->alleSchritte($id);

        self::assertCount(5, $this->buchungen($konto));
        self::assertSame(['gesamt' => 5, 'neu' => 0, 'duplikat' => 5, 'fehler' => 0, 'vorgemerkt' => 0, 'vor_stichtag' => 0], $this->importe()->find($id)?->stats?->toArray());
        self::assertStringContainsString('Übernommen: 0 neue Buchungen, 5 waren schon vorhanden.', $this->get('/app/konten/import/' . $id)->body);
    }

    public function testAnOverlappingExportAddsOnlyTheMissingBookings(): void
    {
        $konto = $this->bankkonto('Girokonto Sparkasse', self::sparkasseIban(), '1.523,40', '2024-03-11');
        $voll = self::sparkasse();
        $ersterAuszug = substr($voll, 0, (int) strpos($voll, "\r\n-\r\n") + 5);

        $this->importiere($ersterAuszug);
        self::assertCount(3, $this->buchungen($konto));

        $id = self::importId($this->hochladen($voll));
        $seite = $this->get('/app/konten/import/' . $id);
        self::assertSame(['5', '2', '3'], self::zahlen($seite->body, ['Buchungen in der Datei', 'davon neu', 'davon schon vorhanden (Duplikat)']));
        self::assertStringContainsString('Salden stimmen', $seite->body, 'The known balance before the file is the opening balance.');

        $this->post('/app/konten/import/' . $id . '/uebernehmen');
        $this->alleSchritte($id);

        self::assertCount(5, $this->buchungen($konto));
        self::assertSame(2, $this->importe()->find($id)?->stats?->neu);
    }

    public function testTheNextStatementConnectsToTheStoredBookings(): void
    {
        $this->bankkonto('Girokonto Sparkasse', self::sparkasseIban(), '1.523,40', '2024-03-01');
        $voll = self::sparkasse();
        $grenze = (int) strpos($voll, "\r\n-\r\n") + 5;
        $this->importiere(substr($voll, 0, $grenze));

        // The second statement alone: its opening balance is the first one's closing balance.
        $id = self::importId($this->hochladen(substr($voll, $grenze)));
        $seite = $this->get('/app/konten/import/' . $id);
        self::assertStringContainsString('Anschluss an den Kontostand vor dem 12.03.2024', $seite->body);
        self::assertStringContainsString('Salden stimmen', $seite->body);
        self::assertSame(BalanceCheck::Ok, $this->vorschauSaldo($id));

        // A statement left out shows as a gap.
        $this->post('/app/konten/import/' . $id . '/verwerfen');
        $luecke = str_replace([':60F:C240312EUR1607,50', ':62F:C240312EUR1649,90'], [':60F:C240312EUR1700,00', ':62F:C240312EUR1742,40'], substr($voll, $grenze));
        $id = self::importId($this->hochladen($luecke));
        self::assertStringContainsString('Salden weichen ab', $this->get('/app/konten/import/' . $id)->body);
        self::assertSame(BalanceCheck::Abweichung, $this->vorschauSaldo($id));
    }

    public function testABalanceMismatchIsAWarningNotARefusal(): void
    {
        $konto = $this->bankkonto('Girokonto Sparkasse', self::sparkasseIban(), '1.523,40', '2024-03-11');
        $falsch = str_replace(':62F:C240312EUR1649,90', ':62F:C240312EUR1650,00', self::sparkasse());

        $id = self::importId($this->hochladen($falsch));
        $seite = $this->get('/app/konten/import/' . $id);
        self::assertStringContainsString('Salden weichen ab', $seite->body);
        self::assertStringContainsString('Trotz Warnungen übernehmen', $seite->body);
        self::assertStringContainsString('-0,10 €', $seite->body);

        $this->post('/app/konten/import/' . $id . '/uebernehmen');
        $this->alleSchritte($id);

        self::assertCount(5, $this->buchungen($konto));
        self::assertSame(BalanceCheck::Abweichung, $this->importe()->find($id)?->balanceCheck);
        self::assertStringContainsString('Salden weichen ab', $this->get('/app/konten/import')->body);
    }

    public function testAVrBankMt940FileFindsItsAccountByIban(): void
    {
        $konto = $this->bankkonto('Vereinskonto VR', 'DE93876543210007654321', '812,05', '2023-12-29');

        $id = self::importId($this->hochladen((string) file_get_contents(self::MT940 . 'vrbank.sta')));
        self::assertSame($konto, $this->importe()->find($id)?->accountId);
        self::assertStringContainsString('29.12.2023 – 02.01.2024', $this->get('/app/konten/import/' . $id)->body);

        $this->post('/app/konten/import/' . $id . '/uebernehmen');
        $this->alleSchritte($id);

        self::assertCount(4, $this->buchungen($konto));
        self::assertSame(BalanceCheck::Ok, $this->importe()->find($id)?->balanceCheck);
    }

    public function testACsvExportAsksForItsAccountAndChecksTheBalanceColumn(): void
    {
        $konto = $this->bankkonto('Vereinskonto VR', 'DE89370400440532013000', '4.535,00', '2024-03-01');

        $id = self::importId($this->hochladen((string) file_get_contents(self::CSV . 'vrbank.csv'), 'export.csv'));
        $import = $this->importe()->find($id);
        self::assertNull($import?->accountId, 'A CSV file names no account.');
        self::assertStringStartsWith('csv:', (string) $import?->format);

        $seite = $this->get('/app/konten/import/' . $id);
        self::assertStringContainsString('Welches Konto?', $seite->body);
        self::assertStringContainsString('Die Datei nennt ihr Konto nicht.', $seite->body);
        self::assertStringNotContainsString('/uebernehmen', $seite->body, 'Nothing to confirm without an account.');
        self::assertSame(422, $this->post('/app/konten/import/' . $id . '/uebernehmen')->status);

        self::assertSame(302, $this->post('/app/konten/import/' . $id . '/konto', ['konto' => (string) $konto])->status);
        $seite = $this->get('/app/konten/import/' . $id);
        self::assertSame(['3', '3', '0'], self::zahlen($seite->body, ['Buchungen in der Datei', 'davon neu', 'davon schon vorhanden (Duplikat)']));
        self::assertStringContainsString('Saldo nach Buchung, 26.03.2024 bis 28.03.2024', $seite->body);
        self::assertStringContainsString('Salden stimmen', $seite->body);

        $this->post('/app/konten/import/' . $id . '/uebernehmen');
        $this->alleSchritte($id);

        $zeilen = $this->buchungen($konto);
        self::assertSame(['2024-03-26', '2024-03-27', '2024-03-28'], array_column($zeilen, 'booking_date'), 'Written oldest first.');
        self::assertSame(-123456, $this->daten($zeilen[2])['amount']);
        self::assertSame('VERS-4711', $this->daten($zeilen[0])['mref']);
        self::assertSame(BalanceCheck::Ok, $this->importe()->find($id)?->balanceCheck);

        // The same export again, the account chosen right in the form.
        $wieder = self::importId($this->hochladen((string) file_get_contents(self::CSV . 'vrbank.csv'), 'export.csv', ['konto' => (string) $konto]));
        self::assertSame($konto, $this->importe()->find($wieder)?->accountId);
        self::assertSame(['3', '0', '3'], self::zahlen($this->get('/app/konten/import/' . $wieder)->body, ['Buchungen in der Datei', 'davon neu', 'davon schon vorhanden (Duplikat)']));
    }

    public function testASparkasseCsvSkipsPendingRowsAndKeepsBookingsBeforeTheOpeningDate(): void
    {
        $konto = $this->bankkonto('Girokonto Sparkasse', 'DE89370400440532013000', '0,00', '2024-03-01');

        $id = self::importId($this->hochladen((string) file_get_contents(self::CSV . 'sparkasse-csv-camt-v2.csv'), 'export.csv', ['konto' => (string) $konto]));
        $seite = $this->get('/app/konten/import/' . $id);
        self::assertSame(['4', '4', '1', '1'], self::zahlen($seite->body, ['Buchungen in der Datei', 'davon neu', 'davon vor dem Stichtag 01.03.2024', 'vorgemerkte Umsätze (übersprungen)']));
        self::assertStringContainsString('keine Salden in der Datei', $seite->body);
        self::assertStringContainsString('vor Stichtag', $seite->body);

        $this->post('/app/konten/import/' . $id . '/uebernehmen');
        $this->alleSchritte($id);

        self::assertSame(['2024-01-02', '2024-03-26', '2024-03-27', '2024-03-28'], array_column($this->buchungen($konto), 'booking_date'));
        $import = $this->importe()->find($id);
        self::assertSame(BalanceCheck::NichtVerfuegbar, $import?->balanceCheck);
        self::assertSame(['gesamt' => 4, 'neu' => 4, 'duplikat' => 0, 'fehler' => 0, 'vorgemerkt' => 1, 'vor_stichtag' => 1], $import->stats?->toArray());
    }

    public function testRowsThatDoNotFitAreShownLeftOutAndCanComeLater(): void
    {
        $konto = $this->bankkonto('Girokonto Sparkasse', 'DE89370400440532013000', '0,00', '2024-01-01');
        $richtig = (string) file_get_contents(self::CSV . 'sparkasse-csv-camt-v2.csv');
        $kaputt = str_replace('"-89,00"', '"-89,0x"', $richtig);

        $id = self::importId($this->hochladen($kaputt, 'export.csv', ['konto' => (string) $konto]));
        $seite = $this->get('/app/konten/import/' . $id);
        self::assertSame(['3', '1'], self::zahlen($seite->body, ['Buchungen in der Datei', 'fehlerhafte Zeilen (nicht übernommen)']));
        self::assertStringContainsString('Zeile 3', $seite->body);
        self::assertStringNotContainsString('89,0x', $seite->body, 'The message names the line, not the cell.');
        self::assertStringContainsString('Trotz Warnungen übernehmen', $seite->body);

        $this->post('/app/konten/import/' . $id . '/uebernehmen');
        $this->alleSchritte($id);
        self::assertCount(3, $this->buchungen($konto));
        self::assertSame(1, $this->importe()->find($id)?->stats?->fehler);

        // The corrected file brings exactly the missing booking.
        $nachher = self::importId($this->hochladen($richtig, 'export.csv', ['konto' => (string) $konto]));
        $this->post('/app/konten/import/' . $nachher . '/uebernehmen');
        $this->alleSchritte($nachher);
        self::assertCount(4, $this->buchungen($konto));
        self::assertSame(1, $this->importe()->find($nachher)?->stats?->neu);
    }

    public function testAnUnknownAccountLineIsAskedOnceAndThenRemembered(): void
    {
        $sparbuch = $this->bankkonto('Sparbuch ohne IBAN', '', '1.523,40', '2024-03-11');

        $id = self::importId($this->hochladen(self::sparkasse()));
        self::assertNull($this->importe()->find($id)?->accountId);
        self::assertStringContainsString('Die Wahl wird gemerkt', $this->get('/app/konten/import/' . $id)->body);
        $this->post('/app/konten/import/' . $id . '/konto', ['konto' => (string) $sparbuch]);
        $this->post('/app/konten/import/' . $id . '/uebernehmen');
        $this->alleSchritte($id);

        $wieder = self::importId($this->hochladen(self::sparkasse()));
        self::assertSame($sparbuch, $this->importe()->find($wieder)?->accountId, 'Remembered from the last finished import of this line.');
        self::assertSame($this->importe()->find($id)?->sourceBi, $this->importe()->find($wieder)?->sourceBi);
        self::assertStringNotContainsString('12345678', $this->roh('bank_import'));
    }

    public function testTheFileDecidesItsAccount(): void
    {
        $richtig = $this->bankkonto('Girokonto Sparkasse', self::sparkasseIban());
        $anderes = $this->bankkonto('Vereinskonto VR', 'DE93876543210007654321');

        $antwort = $this->hochladen(self::sparkasse(), 'auszug.sta', ['konto' => (string) $anderes]);
        self::assertSame(422, $antwort->status);
        self::assertStringContainsString('Die Datei gehört zum Konto „Girokonto Sparkasse“', $antwort->body);
        self::assertSame(0, $this->anzahl('bank_import'));
        self::assertSame(0, $this->anzahl('file_blob'), 'A refused file is not stored.');

        $id = self::importId($this->hochladen(self::sparkasse(), 'auszug.sta', ['konto' => (string) $richtig]));
        self::assertSame($richtig, $this->importe()->find($id)?->accountId);
        $antwort = $this->post('/app/konten/import/' . $id . '/konto', ['konto' => (string) $anderes]);
        self::assertSame(422, $antwort->status);
        self::assertSame($richtig, $this->importe()->find($id)?->accountId);
    }

    public function testOnlyActiveBankAccountsTakeImports(): void
    {
        $kasse = $this->service()->anlegen($this->tresor, BankAccountKind::Kasse, [
            'name' => 'Vereinsheim-Kasse', 'opening_balance' => '100,00', 'opening_date' => '2024-01-01',
        ], new \DateTimeImmutable())->id;
        $antwort = $this->hochladen((string) file_get_contents(self::CSV . 'vrbank.csv'), 'export.csv', ['konto' => (string) $kasse]);
        self::assertSame(422, $antwort->status);
        self::assertStringContainsString('nicht in eine Kasse', $antwort->body);

        $konto = $this->bankkonto('Altes Konto', self::sparkasseIban());
        $this->pdo()->exec('UPDATE bank_account SET active = 0 WHERE id = ' . $konto);
        $antwort = $this->hochladen(self::sparkasse());
        self::assertSame(422, $antwort->status);
        self::assertStringContainsString('ist deaktiviert', $antwort->body);
        self::assertSame(0, $this->anzahl('bank_import'));
        self::assertSame(0, $this->anzahl('file_blob'));

        $start = $this->get('/app/konten/import');
        self::assertStringNotContainsString('Vereinsheim-Kasse', $start->body, 'Only active bank accounts are offered.');
        self::assertStringNotContainsString('>Altes Konto<', $start->body);
    }

    public function testFilesThatCannotBeImportedAreRefusedWithoutStoringThem(): void
    {
        $this->bankkonto('Girokonto', self::sparkasseIban());

        $unbekannt = $this->hochladen((string) file_get_contents(self::CSV . 'unbekannt.csv'), 'export.csv');
        self::assertSame(422, $unbekannt->status);
        self::assertStringContainsString('Neues CSV-Format anlegen', $unbekannt->body);

        $kaputt = $this->hochladen(str_replace(':61:2403110311CR250,00', ':61:2403110311CR2x50,00', self::sparkasse()));
        self::assertSame(422, $kaputt->status);
        self::assertStringContainsString('Die MT940-Datei ist nicht lesbar', $kaputt->body);
        self::assertStringNotContainsString('2x50', $kaputt->body);

        $zweiKonten = $this->hochladen(self::sparkasse() . (string) file_get_contents(self::MT940 . 'vrbank.sta'));
        self::assertSame(422, $zweiKonten->status);
        self::assertStringContainsString('mehrerer Konten', $zweiKonten->body);

        $falschesFormat = $this->hochladen(self::sparkasse(), 'auszug.sta', ['format' => 'csv:1']);
        self::assertSame(422, $falschesFormat->status);

        self::assertSame(422, $this->hochladen("   \r\n")->status);
        self::assertSame(422, $this->post('/app/konten/import')->status, 'No file at all.');

        self::assertSame(0, $this->anzahl('bank_import'));
        self::assertSame(0, $this->anzahl('file_blob'));
    }

    public function testDiscardingAPreviewDeletesItsFileButAConfirmedImportStays(): void
    {
        $this->bankkonto('Girokonto Sparkasse', self::sparkasseIban(), '1.523,40', '2024-03-11');

        $id = self::importId($this->hochladen(self::sparkasse()));
        self::assertSame(1, $this->anzahl('file_blob'));
        $antwort = $this->post('/app/konten/import/' . $id . '/verwerfen');
        self::assertSame('/app/konten/import', $antwort->headers['Location'] ?? null);
        self::assertSame(0, $this->anzahl('bank_import'));
        self::assertSame(0, $this->anzahl('file_blob'));
        self::assertSame(404, $this->get('/app/konten/import/' . $id)->status);

        $fertig = $this->importiere(self::sparkasse());
        $this->post('/app/konten/import/' . $fertig . '/verwerfen');
        self::assertSame(BankImportStatus::Fertig, $this->importe()->find($fertig)?->status);
        self::assertSame(1, $this->anzahl('file_blob'), 'The original of the bookings stays.');
    }

    public function testARepeatedOrParallelStepWritesNothingTwice(): void
    {
        $konto = $this->bankkonto('Girokonto Sparkasse', self::sparkasseIban(), '1.523,40', '2024-03-11');
        $id = self::importId($this->hochladen(self::sparkasse()));
        $this->post('/app/konten/import/' . $id . '/uebernehmen');

        self::assertSame(['status' => 'laeuft', 'verarbeitet' => 2, 'gesamt' => 5], $this->schritt($id));

        // A second tab still holding the old cursor loses: nothing moves twice.
        self::assertFalse($this->importe()->advance($id, 0, 2, new \DateTimeImmutable()));
        self::assertCount(2, $this->buchungen($konto));

        $this->alleSchritte($id);
        self::assertSame(['status' => 'fertig', 'verarbeitet' => 5, 'gesamt' => 5], $this->schritt($id), 'A step after the end only reports.');
        self::assertCount(5, $this->buchungen($konto));
        self::assertCount(1, $this->protokoll($id), 'Finished once, logged once.');

        // The unique key is the net under the check.
        $erste = $this->buchungen($konto)[0];
        self::assertNull(new BankTransactionRepository($this->pdo())->insert(
            $konto, $id, new \DateTimeImmutable('2024-03-11'), null,
            \App\Domain\BankTransactionDirection::Ausgabe, 'x', (string) $erste['dedup_bi'], null,
            \App\Domain\BankTransactionDocStatus::Fehlt, true, \App\Domain\BankTransactionSource::Import, new \DateTimeImmutable(),
        ));
    }

    public function testAStepOnAPreviewOrAnUnknownImportIsRefused(): void
    {
        $this->bankkonto('Girokonto Sparkasse', self::sparkasseIban());
        $id = self::importId($this->hochladen(self::sparkasse()));

        $antwort = $this->post('/app/konten/import/' . $id . '/schritt');
        self::assertSame(409, $antwort->status);
        self::assertSame(['fehler' => 'Dieser Import ist nicht bestätigt.'], json_decode($antwort->body, true));
        self::assertSame(409, $this->post('/app/konten/import/999/schritt')->status);
        self::assertSame(404, $this->get('/app/konten/import/999')->status);
        self::assertSame(0, $this->anzahl('bank_transaction'));
    }

    public function testWithoutTheVaultNothingIsReadOrWritten(): void
    {
        $this->bankkonto('Girokonto Sparkasse', self::sparkasseIban());

        $start = $this->get('/app/konten/import', entsperrt: false);
        self::assertSame(200, $start->status);
        self::assertStringContainsString('nur mit entsperrtem Tresor lesbar', $start->body);
        self::assertStringNotContainsString('Girokonto Sparkasse', $start->body);

        $antwort = $this->hochladen(self::sparkasse(), entsperrt: false);
        self::assertSame('/app/konten/import', $antwort->headers['Location'] ?? null);
        self::assertSame(0, $this->anzahl('file_blob'));

        $id = self::importId($this->hochladen(self::sparkasse()));
        self::assertSame(302, $this->get('/app/konten/import/' . $id, entsperrt: false)->status);
        $this->post('/app/konten/import/' . $id . '/uebernehmen', entsperrt: false);
        self::assertSame(BankImportStatus::Vorschau, $this->importe()->find($id)?->status);

        $this->post('/app/konten/import/' . $id . '/uebernehmen');
        $schritt = $this->post('/app/konten/import/' . $id . '/schritt', entsperrt: false);
        self::assertSame(403, $schritt->status);
        self::assertSame(0, $this->anzahl('bank_transaction'));
    }

    public function testEveryWriteNeedsTheCsrfToken(): void
    {
        $this->bankkonto('Girokonto Sparkasse', self::sparkasseIban());

        $ohne = $this->dispatch(new Request(HttpMethod::Post, '/app/konten/import', cookies: $this->tresorCookie(), files: $this->datei(self::sparkasse(), 'auszug.sta')));
        self::assertSame(302, $ohne->status);
        self::assertSame(0, $this->anzahl('bank_import'));

        $id = self::importId($this->hochladen(self::sparkasse()));
        foreach (['uebernehmen', 'verwerfen', 'konto'] as $aktion) {
            $this->dispatch(new Request(HttpMethod::Post, '/app/konten/import/' . $id . '/' . $aktion, post: ['konto' => '1'], cookies: $this->tresorCookie()));
        }
        self::assertSame(BankImportStatus::Vorschau, $this->importe()->find($id)?->status);

        $this->post('/app/konten/import/' . $id . '/uebernehmen');
        $schritt = $this->dispatch(new Request(HttpMethod::Post, '/app/konten/import/' . $id . '/schritt', cookies: $this->tresorCookie()));
        self::assertSame(403, $schritt->status);
        self::assertSame(0, $this->anzahl('bank_transaction'));
    }

    public function testAnAccountOrCategoryInUseCannotBeDeleted(): void
    {
        $konto = $this->bankkonto('Girokonto Sparkasse', self::sparkasseIban(), '1.523,40', '2024-03-11');
        $konten = new BankAccountRepository($this->pdo());

        self::importId($this->hochladen(self::sparkasse()));
        self::assertSame(1, $konten->usageCount($konto), 'A preview already uses the account.');

        $this->importiere(self::sparkasse());
        self::assertSame(2 + 5, $konten->usageCount($konto));

        $kategorie = (int) $this->pdo()->query("INSERT INTO category (name, direction, sort, active, ai_hint) VALUES ('Testkategorie Import', 'ausgabe', 1, 1, '') RETURNING id")->fetchColumn();
        self::assertSame(0, new CategoryRepository($this->pdo())->usageCount($kategorie));
        $this->pdo()->exec('UPDATE bank_transaction SET category_id = ' . $kategorie);
        self::assertSame(5, new CategoryRepository($this->pdo())->usageCount($kategorie));
    }

    private function regeln(): Buchungsregeln
    {
        $pdo = $this->pdo();

        return new Buchungsregeln($pdo, new AssignmentRuleRepository($pdo), new BankTransactionRepository($pdo), new CategoryRepository($pdo), new SettingRepository($pdo));
    }

    private function kategorie(string $name): int
    {
        foreach (new CategoryRepository($this->pdo())->all() as $kategorie) {
            if ($kategorie->name === $name) {
                return $kategorie->id;
            }
        }

        self::fail('No category ' . $name);
    }

    private static function sparkasse(): string
    {
        return (string) file_get_contents(self::MT940 . 'sparkasse.sta');
    }

    private static function sparkasseIban(): string
    {
        return (string) Iban::ausBlzUndKonto('12345678', '0001234567');
    }

    private function service(): BankAccountService
    {
        return new BankAccountService($this->pdo(), new BankAccountRepository($this->pdo()));
    }

    private function bankkonto(string $name, string $iban, string $saldo = '0,00', string $stichtag = '2024-01-01'): int
    {
        return $this->service()->anlegen($this->tresor, BankAccountKind::Bank, [
            'name' => $name, 'iban' => $iban, 'opening_balance' => $saldo, 'opening_date' => $stichtag,
        ], new \DateTimeImmutable())->id;
    }

    private function importe(): BankImportRepository
    {
        return new BankImportRepository($this->pdo());
    }

    /** Upload, confirm, run every step - returns the import id. */
    private function importiere(string $inhalt): int
    {
        $id = self::importId($this->hochladen($inhalt));
        self::assertSame(302, $this->post('/app/konten/import/' . $id . '/uebernehmen')->status);
        $this->alleSchritte($id);
        self::assertSame(BankImportStatus::Fertig, $this->importe()->find($id)?->status);

        return $id;
    }

    /**
     * @param array<string, string> $felder
     */
    private function hochladen(string $inhalt, string $name = 'auszug.sta', array $felder = [], bool $entsperrt = true): Response
    {
        return $this->dispatch(new Request(
            HttpMethod::Post,
            '/app/konten/import',
            post: [...$felder, '_csrf' => new Session()->csrfToken()],
            files: $this->datei($inhalt, $name),
            cookies: $entsperrt ? $this->tresorCookie() : [],
        ));
    }

    private static function importId(Response $antwort): int
    {
        self::assertSame(302, $antwort->status, $antwort->body);
        $ort = $antwort->headers['Location'] ?? '';
        self::assertMatchesRegularExpression('#^/app/konten/import/\d+$#', $ort);

        return (int) substr($ort, strlen('/app/konten/import/'));
    }

    /**
     * @return array<string, mixed>
     */
    private function schritt(int $id): array
    {
        $antwort = $this->post('/app/konten/import/' . $id . '/schritt');
        self::assertSame(200, $antwort->status, $antwort->body);
        $daten = json_decode($antwort->body, true, 4, JSON_THROW_ON_ERROR);
        self::assertIsArray($daten);

        return $daten;
    }

    /**
     * @return list<array<string, mixed>> every answer until `fertig`
     */
    private function alleSchritte(int $id): array
    {
        $antworten = [];
        for ($i = 0; $i < 50; $i++) {
            $antworten[] = $daten = $this->schritt($id);
            if ($daten['status'] === 'fertig') {
                return $antworten;
            }
        }

        self::fail('The step chain did not finish.');
    }

    private function vorschauSaldo(int $id): ?BalanceCheck
    {
        return $this->kontoauszugImport()->vorschau($this->tresor, $id)?->salden->ergebnis;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buchungen(int $konto): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM bank_transaction WHERE account_id = ? ORDER BY id');
        $stmt->execute([$konto]);

        return $stmt->fetchAll();
    }

    /**
     * @param array<string, mixed> $zeile
     *
     * @return array<string, mixed>
     */
    private function daten(array $zeile): array
    {
        $id = (int) $zeile['id'];
        $json = FieldCipher::decrypt($this->tresor->openDataKey((string) $zeile['dek_sealed']), (string) $zeile['data_enc'], new FieldContext('bank_transaction', $id, 'data_enc'));

        return (array) json_decode($json, true, 4, JSON_THROW_ON_ERROR);
    }

    /**
     * The number in the table row whose header is $zeile, for each label.
     *
     * @param list<string> $zeilen
     *
     * @return list<string>
     */
    private static function zahlen(string $html, array $zeilen): array
    {
        $werte = [];
        foreach ($zeilen as $zeile) {
            $muster = '#<th scope="row">\s*' . preg_quote($zeile, '#') . '\s*</th>\s*<td class="zahl">(?:<span[^>]*>)?(\d+)#u';
            self::assertSame(1, preg_match($muster, $html, $treffer), 'No row "' . $zeile . '"');
            $werte[] = $treffer[1];
        }

        return $werte;
    }

    private function anzahl(string $tabelle): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM ' . $tabelle)->fetchColumn();
    }

    private function roh(string $tabelle): string
    {
        $roh = '';
        foreach ($this->pdo()->query('SELECT * FROM ' . $tabelle)->fetchAll() as $zeile) {
            foreach ($zeile as $wert) {
                if (is_string($wert)) {
                    $roh .= $wert . "\x00";
                }
            }
        }

        return $roh;
    }

    /**
     * @return list<AuditEntry> newest first
     */
    private function protokoll(int $id): array
    {
        return new AuditLogRepository($this->pdo())->page(new AuditFilter(entity: 'bank_import', entityId: $id), null, 10);
    }

    /**
     * @return array<string, string>
     */
    private function tresorCookie(): array
    {
        return [Cookie::vaultKey('x', Request::httpsFromGlobals())->name => new SessionVault()->store($this->tresor)];
    }

    /**
     * A file as PHP hands it over in $_FILES, written to a temp file.
     *
     * @return array<string, array<string, mixed>>
     */
    private function datei(string $inhalt, string $name): array
    {
        $pfad = tempnam(sys_get_temp_dir(), 'importtest');
        assert(is_string($pfad));
        $this->uploads[] = $pfad;
        file_put_contents($pfad, $inhalt);

        return ['datei' => ['name' => $name, 'type' => 'application/octet-stream', 'tmp_name' => $pfad, 'error' => \UPLOAD_ERR_OK, 'size' => strlen($inhalt)]];
    }

    private function get(string $pfad, bool $entsperrt = true): Response
    {
        return $this->dispatch(new Request(HttpMethod::Get, $pfad, cookies: $entsperrt ? $this->tresorCookie() : []));
    }

    /**
     * @param array<string, string> $felder
     */
    private function post(string $pfad, array $felder = [], bool $entsperrt = true): Response
    {
        return $this->dispatch(new Request(
            HttpMethod::Post,
            $pfad,
            post: [...$felder, '_csrf' => new Session()->csrfToken()],
            cookies: $entsperrt ? $this->tresorCookie() : [],
        ));
    }

    private function dispatch(Request $request): Response
    {
        $antwort = $this->kernel()->handle($request);
        self::assertInstanceOf(Response::class, $antwort);

        return $antwort;
    }

    private function kontoauszugImport(): KontoauszugImport
    {
        $pdo = $this->pdo();
        $blobs = new BlobRepository($pdo);
        $kontoRecords = new BankAccountRepository($pdo);

        return new KontoauszugImport(
            $pdo,
            new BankImportRepository($pdo),
            new BankTransactionRepository($pdo),
            $kontoRecords,
            new BankAccountService($pdo, $kontoRecords),
            new CsvProfileRepository($pdo),
            new BlobService($blobs, new DbBlobBackend($blobs), new FsBlobBackend(sys_get_temp_dir())),
            new SettingRepository($pdo),
            $this->audit,
            $this->regeln(),
            schrittPosten: $this->schrittPosten,
        );
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
        $kontoauszuege = fn(): KontoauszugController => new KontoauszugController(
            $view,
            new Session(),
            new SessionVault(),
            $this->kontoauszugImport(),
            $this->service(),
            new CsvProfileRepository($pdo),
            fn(string $pfad): bool => in_array($pfad, $this->uploads, true),
        );
        $unerreichbar = static fn(): never => throw new \LogicException('Diese Route gehört nicht zu diesem Test.');

        // Every controller closure of app/src/routes.php in order: the
        // guard second, the statement import 33rd.
        $controller = array_fill(0, 36, $unerreichbar);
        $controller[1] = $guard;
        $controller[32] = $kontoauszuege;

        $router = new Router();
        (require dirname(__DIR__, 2) . '/app/src/routes.php')($router, $view, ...$controller);

        return new Kernel(
            router: $router,
            staticFiles: new StaticFileHandler($paths->publicDir(), longCache: false),
            view: $view,
            debug: true,
        );
    }
}
