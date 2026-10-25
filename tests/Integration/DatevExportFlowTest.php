<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Admin\DatevSettingsController;
use App\App\DatevController;
use App\Config\Paths;
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
use App\Domain\PermissionScope;
use App\Domain\SystemRole;
use App\Domain\UmbuchungMethode;
use App\Domain\ZuordnungMethode;
use App\Domain\Zugriffsbereich;
use App\Http\Cookie;
use App\Http\HttpMethod;
use App\Http\Kernel;
use App\Http\LoginGuard;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Http\Session;
use App\Http\StaticFileHandler;
use App\Repository\AllocationRepository;
use App\Repository\AuditLogRepository;
use App\Repository\BankAccountRepository;
use App\Repository\BankTransactionRepository;
use App\Repository\CategoryRepository;
use App\Repository\DocumentRepository;
use App\Repository\InvoiceRepository;
use App\Repository\RoleRepository;
use App\Repository\SettingRepository;
use App\Repository\TransferRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\Service\Account\SessionVault;
use App\Service\Audit\AuditFilter;
use App\Service\Audit\AuditLog;
use App\Service\Bank\BankAccountService;
use App\Service\Bank\Buchungen;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Export\DatevBuchungsstapel;
use App\Service\Export\DatevEinstellungen;
use App\Service\Export\DatevExport;
use App\Service\Export\DatevPlan;
use App\Service\Export\DatevZeile;
use App\Service\Export\DatevZeitraum;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\ZuordnungFabrik;
use App\View\View;

/**
 * The DATEV export (issue #93, E-49, docs/spec/05-auswertung-und-export.md
 * section 4): every booking of a period as one Buchungsstapel row - Konto
 * from the account, Gegenkonto from the category, a transfer once, the
 * invoice number of a checked receipt in Belegfeld 1, no counterparty in
 * the file -, the scope on the booking date, the page, the download and the
 * settings.
 */
final class DatevExportFlowTest extends DatabaseTestCase
{
    private const string IP = '192.0.2.20';

    /** Counterparty of the member fee - must never reach the file. */
    private const string MITGLIED = 'Anna Marker-Mitglied';

    private const array QUARTAL = ['von' => '2026-01-01', 'bis' => '2026-03-31'];

    private Vault $tresor;
    private AuditLog $audit;
    private int $userId;
    private int $giro;
    private int $kasse;

    /** @var array<string, int> category name => id */
    private array $kategorie = [];

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $crypto = new ServerCrypto((string) base64_decode(self::configData()['server_key'], true));
        $this->tresor = Vault::create();
        new VaultRepository($this->pdo())->insert($this->tresor->publicKey());
        $this->audit = new AuditLog(new AuditLogRepository($this->pdo()), new VaultRepository($this->pdo()), $crypto);
        $this->userId = new UserRepository($this->pdo())->insert($crypto->encrypt('steuer@example.org'), random_bytes(32), $crypto->encrypt('Stefan Steuer'), 'hash', mfaRequired: false);

        $konten = new BankAccountService($this->pdo(), new BankAccountRepository($this->pdo()));
        $this->giro = $konten->anlegen($this->tresor, BankAccountKind::Bank, ['name' => 'Girokonto', 'iban' => 'DE12500105170648489890', 'bic' => '', 'bank' => '', 'opening_balance' => '1000,00', 'opening_date' => '2026-01-01'], new \DateTimeImmutable())->id;
        $this->kasse = $konten->anlegen($this->tresor, BankAccountKind::Kasse, ['name' => 'Barkasse', 'opening_balance' => '0,00', 'opening_date' => '2026-01-01'], new \DateTimeImmutable())->id;
        foreach (new CategoryRepository($this->pdo())->all() as $kategorie) {
            $this->kategorie[$kategorie->name] = $kategorie->id;
        }
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testEveryBookingBecomesOneRowAgainstItsCategory(): void
    {
        $this->einstellen();
        $this->buchung($this->giro, '2026-01-15', '50,00', 'einnahme', 'Mitgliedsbeiträge', self::MITGLIED);
        $gebuehr = $this->buchung($this->giro, '2026-01-31', '12,34', 'ausgabe', 'Bankgebühren');
        $this->umbuchung('2026-01-20', '100,00');
        $bar = $this->buchung($this->kasse, '2026-02-01', '20,00', 'ausgabe', 'Büro & Verwaltung');
        $this->zuordnen($this->beleg('RE 2026/17', 2000, DocumentStatus::Geprueft, '2026-01-30'), $bar, 2000);
        $this->zuordnen($this->beleg('KI-VORSCHLAG-1', 1234, DocumentStatus::InPruefung, '2026-01-31'), $gebuehr, 1234);
        $this->buchung($this->giro, '2026-04-01', '9,99', 'ausgabe', 'Bankgebühren');

        $plan = $this->plan();

        self::assertSame(1, $plan->gegenbuchungen, 'the incoming half of the transfer');
        self::assertSame(0, $plan->fehlend());
        self::assertTrue($plan->istExportierbar());
        self::assertSame([
            ['50,00', 'S', '1800', '2110', '1501', '', 'Mitgliedsbeiträge'],
            ['100,00', 'H', '1800', '1000', '2001', '', 'Umbuchung'],
            ['12,34', 'H', '1800', '6855', '3101', '', 'Bankgebühren'],
            ['20,00', 'H', '1000', '6815', '0102', 'RE2026/17', 'Büro & Verwaltung'],
        ], array_map(self::kurz(...), $plan->zeilen), 'by date; an unchecked receipt gives no number; April is outside');
    }

    public function testATransferAcrossThePeriodBoundaryIsBookedOnceOrOnItsOwnDays(): void
    {
        $this->einstellen();
        $this->umbuchung('2026-03-31', '80,00', ankunft: '2026-04-01');
        $zweitesQuartal = ['von' => '2026-04-01', 'bis' => '2026-06-30'];

        self::assertSame([['80,00', 'H', '1800', '1000', '3103', '', 'Umbuchung']], array_map(self::kurz(...), $this->plan()->zeilen), 'in the period the money left');
        $q2 = $this->plan($zweitesQuartal);
        self::assertSame([], $q2->zeilen, 'never twice');
        self::assertSame(1, $q2->gegenbuchungen);

        // With a money-in-transit account each half is booked on its day.
        $this->einstellen(geldtransitkonto: '1460');
        self::assertSame([['80,00', 'H', '1800', '1460', '3103', '', 'Umbuchung']], array_map(self::kurz(...), $this->plan()->zeilen));
        $mitTransit = $this->plan($zweitesQuartal);
        self::assertSame([['80,00', 'S', '1000', '1460', '0104', '', 'Umbuchung']], array_map(self::kurz(...), $mitTransit->zeilen));
        self::assertSame(0, $mitTransit->gegenbuchungen);
    }

    public function testWhatIsNotMappedHoldsTheExportBack(): void
    {
        $this->einstellen(kategorien: ['Mitgliedsbeiträge' => '2110'], konten: [$this->giro => '1800']);
        $this->buchung($this->giro, '2026-01-15', '50,00', 'einnahme', 'Mitgliedsbeiträge');
        $this->buchung($this->giro, '2026-01-16', '5,00', 'ausgabe', 'Bankgebühren');
        $this->buchung($this->giro, '2026-01-17', '6,00', 'ausgabe', 'Bankgebühren');
        $this->umbuchung('2026-01-20', '100,00');
        $this->rohbuchung($this->giro, '2026-02-01', 0);
        $this->rohbuchung($this->giro, '2026-02-02', -500, 'USD');
        $this->rohbuchung($this->giro, '2026-02-03', 700);

        $plan = $this->plan();

        self::assertSame([$this->kasse => 1], $plan->ohneFinanzkonto, 'the receiving account of the transfer');
        self::assertSame([$this->kategorie['Bankgebühren'] => 2, 0 => 1], $plan->ohneGegenkonto);
        self::assertSame(1, $plan->fremdwaehrung);
        self::assertSame(1, $plan->nullbetraege);
        self::assertSame(5, $plan->fehlend());
        self::assertFalse($plan->istExportierbar());
        self::assertSame('Barkasse', $plan->kontoNamen[$this->kasse]);

        // A clearing account takes the booking without category.
        $this->einstellen(kategorien: ['Mitgliedsbeiträge' => '2110', 'Bankgebühren' => '6855'], klaerungskonto: '9999');
        $danach = $this->plan();
        self::assertSame(1, $danach->fehlend(), 'only the foreign currency is left');
        self::assertContains(['7,00', 'S', '1800', '9999', '0302', '', 'ohne Kategorie'], array_map(self::kurz(...), $danach->zeilen));
    }

    public function testTheScopeLimitsTheBookingsAndTheReceiptNumbers(): void
    {
        $this->einstellen();
        $this->buchung($this->giro, '2026-01-15', '50,00', 'einnahme', 'Mitgliedsbeiträge');
        $maerz = $this->buchung($this->giro, '2026-03-02', '30,00', 'ausgabe', 'Büro & Verwaltung');
        $this->zuordnen($this->beleg('ALT-1', 3000, DocumentStatus::Festgeschrieben, '2026-01-10'), $maerz, 3000);

        $bereich = new Zugriffsbereich(null, new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-12-31'));
        $plan = $this->export()->vorbereiten($this->tresor, DatevZeitraum::ausFeldern(self::QUARTAL, new \DateTimeImmutable()), $bereich, new \DateTimeImmutable());

        self::assertSame([['30,00', 'H', '1800', '6815', '0203', '', 'Büro & Verwaltung']], array_map(self::kurz(...), $plan->zeilen), 'January and the January receipt are outside the scope');
        self::assertSame('ALT-1', $this->plan()->zeilen[1]->belegfeld1, 'without scope the number is there');
    }

    public function testThePageAndTheDownload(): void
    {
        $this->anmelden(SystemRole::Steuerberater);
        $this->buchung($this->giro, '2026-01-15', '1.234,56', 'einnahme', 'Mitgliedsbeiträge', self::MITGLIED);

        $ohneEinstellung = $this->get('/app/datev?' . http_build_query(self::QUARTAL));
        self::assertSame(200, $ohneEinstellung->status);
        self::assertStringContainsString('Berater- und Mandantennummer fehlen noch', $ohneEinstellung->body);
        self::assertStringNotContainsString('/app/datev/datei', $ohneEinstellung->body);

        $this->einstellen();
        $seite = $this->get('/app/datev?' . http_build_query(self::QUARTAL));
        self::assertStringContainsString('EXTF_Buchungsstapel_2026-01-01_bis_2026-03-31.csv', $seite->body);
        self::assertStringContainsString('1 Buchungen', $seite->body);
        self::assertStringContainsString('action="/app/datev/datei"', $seite->body);
        self::assertStringNotContainsString('/admin/datev', $seite->body, 'the tax advisor has no admin.settings');
        self::assertStringContainsString('nur mit entsperrtem Tresor', $this->get('/app/datev', entsperrt: false)->body);
        self::assertStringContainsString('höchstens ein Geschäftsjahr', $this->get('/app/datev?von=2025-12-01&bis=2026-01-31')->body);

        // Without CSRF token or vault: no file.
        $ohneToken = $this->kernel()->handle(new Request(HttpMethod::Post, '/app/datev/datei', cookies: $this->tresorCookie(), post: self::QUARTAL));
        self::assertInstanceOf(Response::class, $ohneToken);
        self::assertSame(302, $ohneToken->status);
        self::assertSame(302, $this->post('/app/datev/datei', self::QUARTAL, entsperrt: false)->status);
        self::assertSame([], $this->audits());

        $vorher = scandir(sys_get_temp_dir()) ?: [];
        $datei = $this->post('/app/datev/datei', self::QUARTAL);
        self::assertSame(200, $datei->status);
        self::assertSame('text/csv; charset=windows-1252', $datei->headers['Content-Type'] ?? null);
        self::assertSame('attachment; filename="EXTF_Buchungsstapel_2026-01-01_bis_2026-03-31.csv"', $datei->headers['Content-Disposition'] ?? null);
        self::assertSame('no-store, private', $datei->headers['Cache-Control'] ?? null);
        self::assertStringStartsWith('"EXTF";700;21;"Buchungsstapel";13;', $datei->body);
        self::assertStringContainsString("\r\n1234,56;\"S\";\"EUR\";;;;1800;2110;;1501;\"\";;;\"Mitgliedsbeitr\xE4ge\";", $datei->body);
        self::assertStringNotContainsString('Marker', $datei->body, 'no counterparty in the file');

        // Nothing of it on disk.
        foreach (array_diff(scandir(sys_get_temp_dir()) ?: [], $vorher) as $neu) {
            $pfad = sys_get_temp_dir() . '/' . $neu;
            if (is_file($pfad) && is_readable($pfad)) {
                self::assertStringNotContainsString('1234,56', (string) file_get_contents($pfad), $neu);
            }
        }

        $audits = $this->audits();
        self::assertCount(1, $audits);
        self::assertSame(['von' => '2026-01-01', 'bis' => '2026-03-31', 'buchungen' => 1], $this->audit->details($audits[0], $this->tresor));
        self::assertSame($this->userId, $audits[0]->userId);

        // An incomplete export is refused on the server too.
        $this->buchung($this->giro, '2026-02-01', '3,00', 'ausgabe', 'Sonstiges');
        self::assertSame(302, $this->post('/app/datev/datei', self::QUARTAL)->status);
        self::assertCount(1, $this->audits());
    }

    public function testTheExportAloneDoesNotOpenTheBookings(): void
    {
        $rollen = new RoleRepository($this->pdo());
        $rolle = $rollen->create('Nur Export', false, ['export.csv' => PermissionScope::Alle, 'admin.settings' => PermissionScope::Alle]);
        $rollen->assignToUser($this->userId, [$rolle]);
        $this->sitzung();
        $this->einstellen();
        $this->buchung($this->giro, '2026-01-15', '50,00', 'einnahme', 'Mitgliedsbeiträge');

        self::assertSame(403, $this->get('/app/datev')->status);
        self::assertSame(403, $this->post('/app/datev/datei', self::QUARTAL)->status);
        self::assertSame([], $this->audits());
        $einstellungen = $this->get('/admin/datev');
        self::assertSame(200, $einstellungen->status);
        self::assertStringNotContainsString('Girokonto', $einstellungen->body, 'no account names without bank.view');
        self::assertStringContainsString('Bankkonto Nr. ' . $this->giro, $einstellungen->body);
    }

    public function testTheAdminPageSavesTheMapping(): void
    {
        $this->anmelden(SystemRole::Admin);

        $leer = $this->get('/admin/datev');
        self::assertSame(200, $leer->status);
        self::assertStringContainsString('name="konto[' . $this->giro . ']"', $leer->body);
        self::assertStringContainsString('Barkasse', $leer->body, 'names with the unlocked vault');
        self::assertStringContainsString('Kasse Nr. ' . $this->kasse, $this->get('/admin/datev', entsperrt: false)->body, 'without: kind and number');

        $felder = [
            'beraternummer' => '29098',
            'mandantennummer' => '55003',
            'sachkontenlaenge' => '4',
            'kontenrahmen' => '49',
            'klaerungskonto' => '',
            'kategorie' => [(string) $this->kategorie['Spenden'] => '2300', (string) $this->kategorie['Sonstiges'] => ''],
            'konto' => [(string) $this->giro => '1800', (string) $this->kasse => '1000'],
        ];
        self::assertSame(302, $this->post('/admin/datev', $felder)->status);
        $gespeichert = DatevEinstellungen::fromSettings(new SettingRepository($this->pdo()));
        self::assertSame([$this->kategorie['Spenden'] => '2300'], $gespeichert->kategorien);
        self::assertSame([$this->giro => '1800', $this->kasse => '1000'], $gespeichert->konten);
        self::assertSame('49', $gespeichert->kontenrahmen);
        self::assertStringContainsString('value="2300"', $this->get('/admin/datev')->body);

        $falsch = $this->post('/admin/datev', [...$felder, 'konto' => [(string) $this->giro => '18000']]);
        self::assertSame(422, $falsch->status);
        self::assertStringContainsString('value="18000"', $falsch->body, 'shown as typed');
        self::assertSame([$this->giro => '1800', $this->kasse => '1000'], DatevEinstellungen::fromSettings(new SettingRepository($this->pdo()))->konten, 'nothing saved');

        $einstellungen = $this->audits(AuditAction::EinstellungDatev);
        self::assertCount(1, $einstellungen);
        self::assertSame(['vollstaendig' => true, 'sachkontenlaenge' => 4, 'kontenrahmen' => '49', 'kategorien' => 1, 'konten' => 2, 'geldtransit' => false], $this->audit->details($einstellungen[0], $this->tresor));
    }

    public function testAStoredValueThatNoLongerFitsIsDropped(): void
    {
        new SettingRepository($this->pdo())->set(DatevEinstellungen::SETTING, '{"beraternummer":"12","mandantennummer":"7","sachkontenlaenge":4,"kategorien":{"3":"12345","4":"2110"},"konten":"x"}');
        $e = DatevEinstellungen::fromSettings(new SettingRepository($this->pdo()));

        self::assertSame('', $e->beraternummer);
        self::assertSame('7', $e->mandantennummer);
        self::assertSame([4 => '2110'], $e->kategorien);
        self::assertSame([], $e->konten);

        new SettingRepository($this->pdo())->set(DatevEinstellungen::SETTING, 'kein json');
        self::assertFalse(DatevEinstellungen::fromSettings(new SettingRepository($this->pdo()))->istVollstaendig());
    }

    // ------------------------------------------------------------ helpers

    /**
     * @param array<string, string>|null $kategorien category name => account
     * @param array<int, string>|null $konten account id => account
     */
    private function einstellen(?array $kategorien = null, ?array $konten = null, string $klaerungskonto = '', string $geldtransitkonto = ''): void
    {
        $kategorien ??= ['Mitgliedsbeiträge' => '2110', 'Bankgebühren' => '6855', 'Büro & Verwaltung' => '6815'];
        $konten ??= [$this->giro => '1800', $this->kasse => '1000'];
        $ids = [];
        foreach ($kategorien as $name => $konto) {
            $ids[$this->kategorie[$name]] = $konto;
        }
        new DatevEinstellungen('29098', '55003', 4, '49', $klaerungskonto, $ids, $konten, $geldtransitkonto)->speichern(new SettingRepository($this->pdo()));
    }

    /** @param array<string, string> $zeitraum */
    private function plan(array $zeitraum = self::QUARTAL): DatevPlan
    {
        return $this->export()->vorbereiten($this->tresor, DatevZeitraum::ausFeldern($zeitraum, new \DateTimeImmutable()), Zugriffsbereich::unbeschraenkt(), new \DateTimeImmutable());
    }

    /** @return list<string> */
    private static function kurz(DatevZeile $z): array
    {
        return [DatevBuchungsstapel::betrag($z->betragCent), $z->betragCent < 0 ? 'H' : 'S', $z->konto, $z->gegenkonto, $z->belegdatum->format('dm'), $z->belegfeld1, $z->buchungstext];
    }

    private function export(): DatevExport
    {
        $pdo = $this->pdo();
        $kategorien = new CategoryRepository($pdo);
        $transaktionen = new BankTransactionRepository($pdo);
        $konten = new BankAccountService($pdo, new BankAccountRepository($pdo));

        return new DatevExport(
            new Buchungen($pdo, $transaktionen, $konten, $kategorien),
            new TransferRepository($pdo),
            new AllocationRepository($pdo),
            new InvoiceRepository($pdo),
            $kategorien,
            $konten,
            new SettingRepository($pdo),
        );
    }

    private function buchung(int $konto, string $datum, string $betrag, string $richtung, string $kategorie, string $gegenseite = ''): int
    {
        return new Buchungen($this->pdo(), new BankTransactionRepository($this->pdo()), new BankAccountService($this->pdo(), new BankAccountRepository($this->pdo())), new CategoryRepository($this->pdo()))->anlegen($this->tresor, [
            'konto' => (string) $konto,
            'datum' => $datum,
            'betrag' => $betrag,
            'richtung' => $richtung,
            'kategorie' => (string) $this->kategorie[$kategorie],
            'zweck' => 'Testbuchung ' . $gegenseite,
            'gegenseite' => $gegenseite,
            'beleg' => '0',
        ], new \DateTimeImmutable('2026-10-01'));
    }

    /**
     * A booking straight into the table - without category, in any
     * currency, of any amount, which the form would not allow.
     */
    private function rohbuchung(int $konto, string $datum, int $cent, string $waehrung = 'EUR'): int
    {
        $repository = new BankTransactionRepository($this->pdo());
        $richtung = BankTransactionDirection::ausDemBetrag($cent);
        $key = DataKey::generate();
        $id = $repository->insert($konto, null, new \DateTimeImmutable($datum), null, $richtung, $this->tresor->sealDataKey($key), null, null, BankTransactionDocStatus::NichtNoetig, false, BankTransactionSource::Manuell, new \DateTimeImmutable()) ?? self::fail();
        $repository->setCiphertext($id, FieldCipher::encrypt($key, (string) json_encode([
            'amount' => $cent, 'currency' => $waehrung, 'counterparty_name' => '', 'counterparty_iban' => '',
            'purpose' => 'roh', 'eref' => '', 'mref' => '', 'cred' => '', 'gvc' => '', 'booking_text' => '',
        ]), new FieldContext('bank_transaction', $id, 'data_enc')));

        return $id;
    }

    private function umbuchung(string $datum, string $betrag, ?string $ankunft = null): void
    {
        $ab = $this->buchung($this->giro, $datum, $betrag, 'ausgabe', 'Sonstiges');
        $an = $this->buchung($this->kasse, $ankunft ?? $datum, $betrag, 'einnahme', 'Sonstige Einnahmen');
        $transfers = new TransferRepository($this->pdo());
        $transfers->insert($ab, $an, UmbuchungMethode::Manuell, null, new \DateTimeImmutable());
        $transfers->markieren($ab, new \DateTimeImmutable());
        $transfers->markieren($an, new \DateTimeImmutable());
    }

    private function beleg(string $nummer, int $brutto, DocumentStatus $status, string $datum): int
    {
        $dokument = new DocumentRepository($this->pdo())->insert(DocumentSource::Intern, null, [], $this->tresor->sealDataKey(DataKey::generate()), new \DateTimeImmutable(), $status);
        $key = DataKey::generate();
        $rechnungen = new InvoiceRepository($this->pdo());
        $id = $rechnungen->insert(
            $dokument,
            new InvoiceStructure(InvoiceType::Rechnung, InvoiceDirection::Ausgabe, null, new \DateTimeImmutable($datum), null, null, null, null, null),
            $this->tresor->sealDataKey($key),
            null,
            $this->userId,
            new \DateTimeImmutable(),
        );
        $daten = new InvoiceData($nummer, $brutto, null, [], 'EUR', 'Bürobedarf', '');
        $rechnungen->setData($id, FieldCipher::encrypt($key, json_encode($daten->toPayload(), JSON_THROW_ON_ERROR), new FieldContext('invoice', $id, 'data_enc')));

        return $id;
    }

    private function zuordnen(int $invoiceId, int $transactionId, int $cent): void
    {
        ZuordnungFabrik::bauen($this->pdo(), $this->audit)->zuordnen($this->tresor, $invoiceId, $transactionId, $cent, ZuordnungMethode::Manuell, null, null, $this->userId, self::IP, new \DateTimeImmutable());
    }

    private function anmelden(SystemRole $rolle): void
    {
        $rollen = new RoleRepository($this->pdo());
        $id = $rollen->findSystem($rolle)?->id;
        assert($id !== null);
        if ($rolle === SystemRole::Steuerberater) {
            new UserRepository($this->pdo())->updateExpiry($this->userId, new \DateTimeImmutable('+30 days'));
        }
        $rollen->assignToUser($this->userId, [$id]);
        $this->sitzung();
    }

    private function sitzung(): void
    {
        new Session()->start();
        $jetzt = time();
        $_SESSION = ['user_id' => $this->userId, 'login_at' => $jetzt, 'last_seen_at' => $jetzt, 'session_epoch' => 0];
    }

    /** @return list<AuditEntry> */
    private function audits(AuditAction $aktion = AuditAction::ExportDatev): array
    {
        return array_values(array_filter(
            new AuditLogRepository($this->pdo())->page(new AuditFilter(), null, 100),
            static fn(AuditEntry $e): bool => $e->action === $aktion->value,
        ));
    }

    /** @return array<string, string> */
    private function tresorCookie(): array
    {
        return [Cookie::vaultKey('x', Request::httpsFromGlobals())->name => new SessionVault()->store($this->tresor)];
    }

    private function get(string $pfad, bool $entsperrt = true): Response
    {
        [$weg, $abfrage] = array_pad(explode('?', $pfad, 2), 2, '');
        parse_str($abfrage, $query);
        $antwort = $this->kernel()->handle(new Request(HttpMethod::Get, $weg, query: $query, cookies: $entsperrt ? $this->tresorCookie() : []));
        self::assertInstanceOf(Response::class, $antwort);

        return $antwort;
    }

    /** @param array<string, mixed> $felder */
    private function post(string $pfad, array $felder, bool $entsperrt = true): Response
    {
        $antwort = $this->kernel()->handle(new Request(
            HttpMethod::Post,
            $pfad,
            cookies: $entsperrt ? $this->tresorCookie() : [],
            post: [...$felder, '_csrf' => new Session()->csrfToken()],
        ));
        self::assertInstanceOf(Response::class, $antwort);

        return $antwort;
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
        $datev = fn(): DatevController => new DatevController($view, new Session(), new SessionVault(), $this->export(), $this->audit);
        $datevAdmin = fn(): DatevSettingsController => new DatevSettingsController(
            $view,
            new Session(),
            new SessionVault(),
            new SettingRepository($pdo),
            new CategoryRepository($pdo),
            new BankAccountRepository($pdo),
            new BankAccountService($pdo, new BankAccountRepository($pdo)),
            $this->audit,
        );
        $unerreichbar = static fn(): never => throw new \LogicException('Diese Route gehört nicht zu diesem Test.');

        $controller = array_fill(0, 38, $unerreichbar);
        $controller[1] = $guard;

        $router = new Router();
        (require dirname(__DIR__, 2) . '/app/src/routes.php')($router, $view, ...$controller, datev: $datev, datevAdmin: $datevAdmin);

        return new Kernel(
            router: $router,
            staticFiles: new StaticFileHandler($paths->publicDir(), longCache: false),
            view: $view,
            debug: true,
        );
    }
}
