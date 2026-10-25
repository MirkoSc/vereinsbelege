<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\App\AuswertungController;
use App\Config\Paths;
use App\Domain\BankAccountKind;
use App\Domain\BankTransactionDirection;
use App\Domain\BankTransactionDocStatus;
use App\Domain\BankTransactionSource;
use App\Domain\CategoryDirection;
use App\Domain\DocumentSource;
use App\Domain\DocumentStatus;
use App\Domain\InvoiceData;
use App\Domain\InvoiceDirection;
use App\Domain\InvoiceStructure;
use App\Domain\InvoiceType;
use App\Domain\SupplierRole;
use App\Domain\SystemRole;
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
use App\Repository\AuswertungsStandRepository;
use App\Repository\BankAccountRepository;
use App\Repository\BankTransactionRepository;
use App\Repository\CategoryRepository;
use App\Repository\CostCenterRepository;
use App\Repository\DocumentRepository;
use App\Repository\InvoiceRepository;
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
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\MasterData\SupplierService;
use App\Service\Migration\Migrator;
use App\Service\Report\AuswertungsCache;
use App\Service\Report\Auswertungsdaten;
use App\Service\Report\Geldfluss;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\ZuordnungFabrik;
use App\View\View;

/**
 * "Wohin fließt das Geld" with drilldown (issue #73/M11-3, docs/spec/
 * 05-auswertung-und-export.md section 1) through the real route table,
 * guard, services, schema and vault: expenses and income by category and
 * cost center on both bases, the period filter, the drilldown to the single
 * receipt and booking, rights and scopes (cost center, period), a locked
 * vault, and that links carry only structure. Synthetic data; past years
 * only, so nothing depends on the day the suite runs.
 */
final class GeldflussFlowTest extends DatabaseTestCase
{
    private const string IP = '192.0.2.10';

    private Vault $tresor;
    private AuditLog $audit;
    private int $userId;
    private int $laufnummer = 0;

    private int $platz;
    private int $reise;
    private int $jugend;
    private int $herren;
    private int $belegRasen;
    private int $belegReise;
    private int $buchungReise;
    private int $buchungOhneBeleg;

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

        $this->beispieldaten();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public function testExpensesByCategoryAndCostCenterOnTheBookingBasis(): void
    {
        $seite = $this->get('/app/auswertung/geldfluss', ['jahr' => '2025']);

        self::assertSame(200, $seite->status);
        self::assertSame('no-store, private', $seite->headers['Cache-Control'] ?? null);
        self::assertStringContainsString('Ausgaben nach Kategorie', $seite->body);
        self::assertStringContainsString('vom 01.01.2025 bis 31.12.2025', $seite->body);

        // Booking basis: 300,00 Rasen (receipt allocated), 100,00 travel
        // receipt + 40,00 booking with the travel category; the unpaid
        // 50,00 receipt and the booking of 2024 do not count.
        $kategorien = $this->tabelle($seite->body, 'kategorie');
        self::assertSame([
            ['Testkat Platzpflege', '300,00 €', '68,2 %', '1'],
            ['Testkat Reisekosten', '140,00 €', '31,8 %', '2'],
        ], $kategorien);
        self::assertMatchesRegularExpression('#geldfluss-kategorie.*?Summe</th>.*?<strong>440,00 €</strong>#s', $seite->body);

        self::assertSame([
            ['Testkst Jugend', '300,00 €', '68,2 %', '1'],
            ['Testkst Herren', '100,00 €', '22,7 %', '1'],
            ['ohne Kostenstelle', '40,00 €', '9,1 %', '1'],
        ], $this->tabelle($seite->body, 'kostenstelle'));

        // The largest group has the full bar; the bar only repeats the text.
        self::assertStringContainsString('<rect class="balken-wert" width="1000" height="10"/>', $seite->body);
        self::assertStringContainsString('<rect class="balken-wert" width="467" height="10"/>', $seite->body);

        $this->linksTragenNurStruktur($seite->body);
    }

    public function testOnTheReceiptBasisAndForIncome(): void
    {
        $belege = $this->get('/app/auswertung/geldfluss', ['jahr' => '2025', 'grundlage' => 'belege']);
        self::assertSame([
            ['Testkat Platzpflege', '350,00 €', '77,8 %', '2'],
            ['Testkat Reisekosten', '100,00 €', '22,2 %', '1'],
        ], $this->tabelle($belege->body, 'kategorie'));
        self::assertStringContainsString('nur geprüfte Belege', $belege->body);

        $einnahmen = $this->get('/app/auswertung/geldfluss', ['jahr' => '2025', 'seite' => 'einnahmen']);
        self::assertStringContainsString('Einnahmen nach Kategorie', $einnahmen->body);
        self::assertSame([['ohne Kategorie', '500,00 €', '100,0 %', '1']], $this->tabelle($einnahmen->body, 'kategorie'));
    }

    public function testThePeriodFilter(): void
    {
        $quartal = $this->get('/app/auswertung/geldfluss', ['zeitraum' => 'quartal', 'jahr' => '2025', 'quartal' => '2']);
        self::assertStringContainsString('vom 01.04.2025 bis 30.06.2025', $quartal->body);
        self::assertSame([['Testkat Reisekosten', '140,00 €', '100,0 %', '2']], $this->tabelle($quartal->body, 'kategorie'));
        // The drilldown keeps the period.
        self::assertStringContainsString('zeitraum=quartal&amp;jahr=2025&amp;quartal=2', $quartal->body);

        $monat = $this->get('/app/auswertung/geldfluss', ['zeitraum' => 'monat', 'jahr' => '2025', 'monat' => '3']);
        self::assertSame([['Testkat Platzpflege', '300,00 €', '100,0 %', '1']], $this->tabelle($monat->body, 'kategorie'));

        $frei = $this->get('/app/auswertung/geldfluss', ['zeitraum' => 'frei', 'von' => '2025-05-01', 'bis' => '2025-05-31']);
        self::assertStringContainsString('vom 01.05.2025 bis 31.05.2025', $frei->body);
        self::assertSame([['Testkat Reisekosten', '100,00 €', '100,0 %', '1']], $this->tabelle($frei->body, 'kategorie'));

        $leer = $this->get('/app/auswertung/geldfluss', ['jahr' => '2023']);
        self::assertStringContainsString('Keine Ausgaben in diesem Zeitraum.', $leer->body);

        $falsch = $this->get('/app/auswertung/geldfluss', ['zeitraum' => 'monat', 'jahr' => '2025', 'monat' => '13']);
        self::assertSame(200, $falsch->status);
        self::assertStringContainsString('Der gewählte Zeitraum war ungültig', $falsch->body);
    }

    public function testDrilldownToTheSingleReceiptAndBooking(): void
    {
        $seite = $this->get('/app/auswertung/geldfluss/posten', ['jahr' => '2025', 'nach' => 'kategorie', 'schluessel' => (string) $this->reise]);

        self::assertSame(200, $seite->status);
        self::assertSame('no-store, private', $seite->headers['Cache-Control'] ?? null);
        self::assertStringContainsString('<h2>Testkat Reisekosten</h2>', $seite->body);
        self::assertStringContainsString('2 Posten vom 01.01.2025 bis 31.12.2025', $seite->body);
        // The receipt part: supplier, number, its cost center, both links.
        self::assertMatchesRegularExpression('#10\.05\.2025</td>\s*<td>\s*Bahn Testreisen\s*<br><span class="klein gedaempft">Nr\. B-7 · Fahrt Auswärtsspiel</span>\s*</td>\s*<td>Testkst Herren</td>\s*<td class="zahl">100,00 €</td>#', $seite->body);
        self::assertStringContainsString('href="/app/belege/pruefen/' . $this->dokumentVon($this->belegReise) . '">Beleg</a>', $seite->body);
        self::assertStringContainsString('href="/app/buchungen/' . $this->buchungReise . '">Buchung</a>', $seite->body);
        // The booking without a receipt: its counterparty and purpose (Finanzen has bank.view).
        self::assertMatchesRegularExpression('#01\.06\.2025</td>\s*<td>\s*Gegenseite\s*<br><span class="klein gedaempft">Zweck</span>\s*</td>\s*<td>ohne Kostenstelle</td>\s*<td class="zahl">40,00 €</td>#', $seite->body);
        self::assertStringContainsString('href="/app/buchungen/' . $this->buchungOhneBeleg . '">Buchung</a>', $seite->body);
        self::assertMatchesRegularExpression('#Summe</th>\s*<td class="zahl"><strong>140,00 €</strong>#', $seite->body);
        // Back to the overview with the same filter.
        self::assertStringContainsString('href="/app/auswertung/geldfluss?zeitraum=jahr&amp;jahr=2025&amp;grundlage=buchungen&amp;seite=ausgaben"', $seite->body);

        $ohne = $this->get('/app/auswertung/geldfluss/posten', ['jahr' => '2025', 'nach' => 'kostenstelle', 'schluessel' => 'ohne']);
        self::assertStringContainsString('<h2>ohne Kostenstelle</h2>', $ohne->body);
        self::assertStringContainsString('1 Posten', $ohne->body);
        self::assertStringContainsString('40,00 €', $ohne->body);

        $rasen = $this->get('/app/auswertung/geldfluss/posten', ['jahr' => '2025', 'grundlage' => 'belege', 'nach' => 'kategorie', 'schluessel' => (string) $this->platz]);
        self::assertStringContainsString('Rasen Testbetrieb', $rasen->body);
        self::assertStringContainsString('Nr. R-1', $rasen->body);
        self::assertStringContainsString('ohne Lieferant', $rasen->body);
        // Receipt basis: no booking.
        self::assertStringNotContainsString('>Buchung</a>', $rasen->body);
        self::assertStringContainsString('href="/app/belege/pruefen/' . $this->dokumentVon($this->belegRasen) . '">Beleg</a>', $rasen->body);

        $this->linksTragenNurStruktur($seite->body . $ohne->body . $rasen->body);
    }

    public function testOnlyStructureIsAcceptedAsDrilldownKey(): void
    {
        foreach ([
            ['nach' => 'lieferant', 'schluessel' => '1'],
            ['nach' => 'monat', 'schluessel' => '2025-03'],
            ['nach' => 'kategorie', 'schluessel' => 'abc'],
            ['nach' => 'kategorie', 'schluessel' => '0'],
            ['nach' => 'kategorie', 'schluessel' => '-1'],
            ['nach' => 'kategorie'],
            ['schluessel' => '1'],
        ] as $query) {
            self::assertSame(404, $this->get('/app/auswertung/geldfluss/posten', $query)->status, (string) json_encode($query));
        }
        // An unknown id is a group without lines, not an error.
        $unbekannt = $this->get('/app/auswertung/geldfluss/posten', ['jahr' => '2025', 'nach' => 'kategorie', 'schluessel' => '999999']);
        self::assertSame(200, $unbekannt->status);
        self::assertStringContainsString('Keine Posten in diesem Zeitraum.', $unbekannt->body);
    }

    public function testACostCenterScopeSeesOnlyItsCostCenter(): void
    {
        $this->rolle(SystemRole::Vereinsverantwortlicher);
        new UserAccessRepository($this->pdo())->setCostCenters($this->userId, [$this->jugend]);

        $seite = $this->get('/app/auswertung/geldfluss', ['jahr' => '2025']);
        self::assertSame([['Testkat Platzpflege', '300,00 €', '100,0 %', '1']], $this->tabelle($seite->body, 'kategorie'));
        self::assertSame([['Testkst Jugend', '300,00 €', '100,0 %', '1']], $this->tabelle($seite->body, 'kostenstelle'));
        self::assertStringNotContainsString('Testkst Herren', $seite->body);
        self::assertStringNotContainsString('140,00', $seite->body);

        // Another cost center through the URL: nothing.
        $fremd = $this->get('/app/auswertung/geldfluss/posten', ['jahr' => '2025', 'nach' => 'kostenstelle', 'schluessel' => (string) $this->herren]);
        self::assertSame(200, $fremd->status);
        self::assertStringContainsString('Keine Posten in diesem Zeitraum.', $fremd->body);
        self::assertStringNotContainsString('Bahn Testreisen', $fremd->body);
        $ohne = $this->get('/app/auswertung/geldfluss/posten', ['jahr' => '2025', 'nach' => 'kategorie', 'schluessel' => (string) $this->reise]);
        self::assertStringNotContainsString('Gegenseite', $ohne->body);
        self::assertStringNotContainsString('40,00', $ohne->body);

        // The own one: the receipt opens in the inbox (no document.edit), no booking link (no bank.view).
        $eigen = $this->get('/app/auswertung/geldfluss/posten', ['jahr' => '2025', 'nach' => 'kostenstelle', 'schluessel' => (string) $this->jugend]);
        self::assertStringContainsString('href="/app/posteingang/' . $this->dokumentVon($this->belegRasen) . '">Beleg</a>', $eigen->body);
        self::assertStringNotContainsString('/app/belege/pruefen/', $eigen->body);
        self::assertStringNotContainsString('/app/buchungen/', $eigen->body);
    }

    public function testAPeriodScopeSeesOnlyTheReleasedPart(): void
    {
        $this->rolle(SystemRole::Kassenpruefer);
        new UserAccessRepository($this->pdo())->setScope($this->userId, new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2025-04-30'));

        $seite = $this->get('/app/auswertung/geldfluss');
        self::assertStringContainsString('vom 01.01.2025 bis 30.04.2025', $seite->body);
        self::assertSame([['Testkat Platzpflege', '300,00 €', '100,0 %', '1']], $this->tabelle($seite->body, 'kategorie'));
        // The year select offers only the released year.
        self::assertStringContainsString('<option value="2025" selected>2025</option>', $seite->body);
        self::assertStringNotContainsString('<option value="2024"', $seite->body);

        $drilldown = $this->get('/app/auswertung/geldfluss/posten', ['jahr' => '2025', 'nach' => 'kategorie', 'schluessel' => (string) $this->reise]);
        self::assertStringContainsString('Keine Posten in diesem Zeitraum.', $drilldown->body);

        $vorher = $this->get('/app/auswertung/geldfluss', ['jahr' => '2024']);
        self::assertStringContainsString('außerhalb des für Sie freigegebenen Zeitraums', $vorher->body);
        self::assertStringNotContainsString('9,99', $vorher->body);
    }

    public function testWithoutAnUnlockedVaultNoFigures(): void
    {
        $seite = $this->get('/app/auswertung/geldfluss', ['jahr' => '2025'], entsperrt: false);
        self::assertSame(200, $seite->status);
        self::assertStringContainsString('nur mit entsperrtem Tresor lesbar', $seite->body);
        self::assertStringNotContainsString('300,00', $seite->body);
        self::assertStringNotContainsString('Testkat Platzpflege', $seite->body);

        $drilldown = $this->get('/app/auswertung/geldfluss/posten', ['jahr' => '2025', 'nach' => 'kategorie', 'schluessel' => (string) $this->platz], entsperrt: false);
        self::assertStringContainsString('nur mit entsperrtem Tresor lesbar', $drilldown->body);
        self::assertStringNotContainsString('Rasen', $drilldown->body);
        self::assertArrayNotHasKey('auswertung_cache', $_SESSION);
    }

    public function testTheEntryPointAndTheNavigation(): void
    {
        $start = $this->get('/app/auswertung');
        self::assertSame(302, $start->status);
        self::assertSame('/app/auswertung/geldfluss', $start->headers['Location'] ?? null);

        $seite = $this->get('/app/auswertung/geldfluss', ['jahr' => '2025']);
        self::assertStringContainsString('href="/app/auswertung" aria-current="page">Auswertungen</a>', $seite->body);
    }

    // ------------------------------------------------------------ scaffolding

    /**
     * 2025: receipts Rasen 300,00 (Platzpflege, Jugend, paid), Reise 100,00
     * (Reisekosten, Herren, paid), 50,00 (Platzpflege, no cost center, no
     * supplier, unpaid); bookings -300,00 / -100,00 for the two, -40,00
     * Reisekosten without a receipt, +500,00 income without category, and
     * -9,99 in 2024.
     */
    private function beispieldaten(): void
    {
        $kategorien = new CategoryRepository($this->pdo());
        $this->platz = $kategorien->create('Testkat Platzpflege', CategoryDirection::Ausgabe, null, '');
        $this->reise = $kategorien->create('Testkat Reisekosten', CategoryDirection::Ausgabe, null, '');
        $kostenstellen = new CostCenterRepository($this->pdo());
        $this->jugend = $kostenstellen->create('Testkst Jugend');
        $this->herren = $kostenstellen->create('Testkst Herren');
        $rasen = $this->lieferant('Rasen Testbetrieb');
        $bahn = $this->lieferant('Bahn Testreisen');

        $konto = $this->bankkonto();
        $this->belegRasen = $this->beleg(30000, '2025-03-14', $this->platz, $this->jugend, $rasen, 'R-1', 'Rasenmähen');
        $this->zuordnen($this->belegRasen, $this->buchung($konto, '2025-03-20', -30000), 30000);
        $this->belegReise = $this->beleg(10000, '2025-05-02', $this->reise, $this->herren, $bahn, 'B-7', 'Fahrt Auswärtsspiel');
        $this->buchungReise = $this->buchung($konto, '2025-05-10', -10000);
        $this->zuordnen($this->belegReise, $this->buchungReise, 10000);
        $this->beleg(5000, '2025-08-01', $this->platz, null, null, '', '');
        $this->buchungOhneBeleg = $this->buchung($konto, '2025-06-01', -4000, $this->reise);
        $this->buchung($konto, '2025-07-01', 50000);
        $this->buchung($konto, '2024-06-01', -999, $this->reise);
    }

    /**
     * Rows of the table of one dimension: name, amount, share, count.
     *
     * @return list<list<string>>
     */
    private function tabelle(string $html, string $dimension): array
    {
        self::assertSame(1, preg_match('#<section aria-labelledby="geldfluss-' . $dimension . '">(.*?)</section>#s', $html, $abschnitt), $dimension);
        preg_match_all('#<tr>\s*<td><a href="[^"]*">([^<]*)</a></td>.*?<td class="zahl">([^<]*)</td>\s*<td class="zahl">([^<]*)</td>\s*<td class="zahl">(\d+)</td>#s', $abschnitt[1], $treffer, PREG_SET_ORDER);

        return array_map(static fn(array $t): array => array_map(html_entity_decode(...), array_slice($t, 1)), $treffer);
    }

    /** Every report link carries only structure: paths with ids, digits, keywords, dates. */
    private function linksTragenNurStruktur(string $html): void
    {
        preg_match_all('#href="(/app/auswertung[^"]*)"#', $html, $links);
        self::assertNotEmpty($links[1]);
        foreach ($links[1] as $link) {
            self::assertMatchesRegularExpression('#^/app/auswertung(/geldfluss(/posten)?)?(\?([a-z]+=[a-z0-9-]+)(&amp;[a-z]+=[a-z0-9-]+)*)?$#', $link);
        }
    }

    private function rolle(SystemRole $rolle): void
    {
        $rollen = new RoleRepository($this->pdo());
        $id = $rollen->findSystem($rolle)?->id;
        assert($id !== null);
        $rollen->assignToUser($this->userId, [$id]);
    }

    private function lieferant(string $name): int
    {
        $lieferanten = new SupplierService($this->pdo(), new SupplierRepository($this->pdo()), new CategoryRepository($this->pdo()));

        return $lieferanten->anlegen($this->tresor, ['name' => $name, 'rolle' => SupplierRole::Lieferant->value], new \DateTimeImmutable())->id;
    }

    private function beleg(int $brutto, string $datum, ?int $kategorie, ?int $kostenstelle, ?int $lieferant, string $nummer, string $zweck): int
    {
        ++$this->laufnummer;
        $dokument = new DocumentRepository($this->pdo())->insert(
            DocumentSource::Intern,
            null,
            [],
            $this->tresor->sealDataKey(DataKey::generate()),
            new \DateTimeImmutable()->modify('-' . $this->laufnummer . ' seconds'),
            DocumentStatus::Geprueft,
            costCenterId: $kostenstelle,
            createdBy: $this->userId,
        );
        $schluessel = DataKey::generate();
        $rechnungen = new InvoiceRepository($this->pdo());
        $id = $rechnungen->insert(
            $dokument,
            new InvoiceStructure(InvoiceType::Rechnung, InvoiceDirection::Ausgabe, $lieferant, new \DateTimeImmutable($datum), null, null, null, $kategorie, $kostenstelle),
            $this->tresor->sealDataKey($schluessel),
            null,
            $this->userId,
            new \DateTimeImmutable(),
        );
        $daten = new InvoiceData($nummer, $brutto, null, [], 'EUR', $zweck, '');
        $rechnungen->setData($id, FieldCipher::encrypt($schluessel, json_encode($daten->toPayload(), JSON_THROW_ON_ERROR), new FieldContext('invoice', $id, 'data_enc')));

        return $id;
    }

    private function dokumentVon(int $invoiceId): int
    {
        return new InvoiceRepository($this->pdo())->find($invoiceId)?->documentId ?? self::fail('no invoice');
    }

    private function bankkonto(): int
    {
        return new BankAccountService($this->pdo(), new BankAccountRepository($this->pdo()))->anlegen(
            $this->tresor,
            BankAccountKind::Bank,
            ['name' => 'Girokonto', 'iban' => '', 'bic' => '', 'bank' => '', 'opening_balance' => '1000,00', 'opening_date' => '2024-01-01'],
            new \DateTimeImmutable(),
        )->id;
    }

    private function buchung(int $konto, string $datum, int $cent, ?int $kategorie = null): int
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
            $kategorie,
        ) ?? self::fail();
        $repository->setCiphertext($id, FieldCipher::encrypt($key, (string) json_encode([
            'amount' => $cent, 'currency' => 'EUR', 'counterparty_name' => 'Gegenseite', 'counterparty_iban' => '',
            'purpose' => 'Zweck', 'eref' => '', 'mref' => '', 'cred' => '', 'gvc' => '', 'booking_text' => 'SEPA-Überweisung',
        ]), new FieldContext('bank_transaction', $id, 'data_enc')));

        return $id;
    }

    private function zuordnen(int $beleg, int $buchung, int $betrag): void
    {
        ZuordnungFabrik::bauen($this->pdo(), $this->audit)->zuordnen($this->tresor, $beleg, $buchung, $betrag, ZuordnungMethode::Manuell, null, null, $this->userId, self::IP, new \DateTimeImmutable());
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
        $audit = $this->audit;

        $guard = static fn(): LoginGuard => new LoginGuard(
            new Session(),
            $view,
            static fn(): SessionTimeouts => new SessionTimeouts(),
            static function (int $id) use ($pdo): ?SessionUser {
                $user = new UserRepository($pdo)->findById($id);

                return $user === null ? null : new SessionUser($user, 'Test', new UserAccessRepository($pdo)->berechtigungen($id));
            },
        );
        $userId = $this->userId;
        $geldfluss = static fn(): AuswertungController => new AuswertungController(
            $view,
            new Session(),
            new SessionVault(),
            static fn(): Geldfluss => new Geldfluss(
                new AuswertungsCache(
                    new Auswertungsdaten(
                        new InvoiceRepository($pdo),
                        new BankTransactionRepository($pdo),
                        ZuordnungFabrik::bauen($pdo, $audit),
                        new BankAccountService($pdo, new BankAccountRepository($pdo)),
                        new SettingRepository($pdo),
                    ),
                    new AuswertungsStandRepository($pdo),
                    $userId,
                ),
                new CategoryRepository($pdo),
                new CostCenterRepository($pdo),
                new InvoiceRepository($pdo),
                new BankTransactionRepository($pdo),
                new SupplierService($pdo, new SupplierRepository($pdo), new CategoryRepository($pdo)),
            ),
        );
        $unerreichbar = static fn(): never => throw new \LogicException('Diese Route gehört nicht zu diesem Test.');

        $router = new Router();
        (require dirname(__DIR__, 2) . '/app/src/routes.php')(
            $router,
            $view,
            $unerreichbar,
            $guard,
            ...array_fill(0, 36, $unerreichbar),
            nextcloudAdmin: $unerreichbar,
            geldfluss: $geldfluss,
        );

        return new Kernel(
            router: $router,
            staticFiles: new StaticFileHandler($paths->publicDir(), longCache: false),
            view: $view,
            debug: true,
        );
    }
}
