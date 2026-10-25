<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\App\DashboardController;
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
use App\Domain\SeriesPeriod;
use App\Domain\SeriesStatus;
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
use App\Repository\AllocationRepository;
use App\Repository\AuditLogRepository;
use App\Repository\AuswertungsStandRepository;
use App\Repository\BankAccountRepository;
use App\Repository\BankTransactionRepository;
use App\Repository\CategoryRepository;
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
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\MasterData\SupplierService;
use App\Service\Migration\Migrator;
use App\Service\Report\AuswertungsCache;
use App\Service\Report\Auswertungsdaten;
use App\Service\Report\Dashboard;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\ZuordnungFabrik;
use App\View\View;

/**
 * The dashboard on /app (issue #72/M11-2, docs/spec/
 * 05-auswertung-und-export.md section 1 "Dashboard") through the real route
 * table, guard, services, schema and vault: the year's sums from the
 * ReportService (booking basis), balances per account, the counts of open
 * work, the year switch, rights and scopes (cost center, period), a locked
 * vault - and that the session cache never shows a figure from before a
 * change. Synthetic data only; past years only, so the result does not
 * depend on the day the suite runs.
 */
final class DashboardFlowTest extends DatabaseTestCase
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

    public function testFinanceSeesSumsBalancesAndCountsOfTheYear(): void
    {
        $konto = $this->bankkonto('Girokonto', '2024-01-01', '1000,00');
        $this->buchung($konto, '2024-06-01', -999);
        $this->buchung($konto, '2025-03-01', 50000);
        $this->buchung($konto, '2025-04-01', -12000);
        $kasse = $this->bankkonto('Kasse', '2025-02-01', '50,00');
        $this->buchung($kasse, '2025-02-10', -1500);

        $this->dokument(DocumentStatus::Eingegangen);
        $this->dokument(DocumentStatus::Eingegangen);
        $this->dokument(DocumentStatus::Abgelehnt);
        $this->dokument(DocumentStatus::InPruefung);
        $this->beleg(4000);
        $this->beleg(800, weg: Zahlungsweg::Erstattung);
        $this->serie(new \DateTimeImmutable('today')->modify('-30 days'));
        $this->serie(new \DateTimeImmutable('today')->modify('+30 days'));

        $seite = $this->get('/app', ['jahr' => '2025']);

        self::assertSame(200, $seite->status);
        self::assertSame('no-store, private', $seite->headers['Cache-Control'] ?? null);
        self::assertStringContainsString('Übersicht 2025', $seite->body);
        self::assertSame('500,00 €', $this->kennzahl($seite->body, 'Einnahmen'));
        self::assertSame('135,00 €', $this->kennzahl($seite->body, 'Ausgaben'));
        self::assertSame('+365,00 €', $this->kennzahl($seite->body, 'Saldo'));
        self::assertStringContainsString('Überschuss', $seite->body);
        self::assertStringContainsString('vom 01.01.2025', $seite->body);
        self::assertStringContainsString('bis 31.12.2025', $seite->body);

        // 1.000,00 - 9,99 + 500,00 - 120,00 at the end of 2025; the box 50,00 - 15,00.
        self::assertStringContainsString('Stand am Ende des 31.12.2025', $seite->body);
        self::assertMatchesRegularExpression('#>Girokonto</a>.*?1\.370,01 €#s', $seite->body);
        self::assertMatchesRegularExpression('#>Kasse</a>.*?35,00 €#s', $seite->body);
        self::assertMatchesRegularExpression('#Summe</th>\s*<td class="zahl"><strong>1\.405,01 €#', $seite->body);

        self::assertSame(2, $this->zaehler($seite->body, 'Posteingang offen'));
        // The capture of the two receipts left their documents `geprueft`; only the one in review counts.
        self::assertSame(1, $this->zaehler($seite->body, 'Belege in Prüfung'));
        self::assertSame(1, $this->zaehler($seite->body, 'Unbezahlte Rechnungen'));
        self::assertSame(3, $this->zaehler($seite->body, 'Buchungen ohne Beleg'));
        self::assertSame(1, $this->zaehler($seite->body, 'Offene Erstattungen'));
        self::assertSame(1, $this->zaehler($seite->body, 'Überfällige wiederkehrende Belege'));
        foreach (['/app/posteingang', '/app/belege/pruefen', '/app/offen/rechnungen', '/app/offen/buchungen', '/app/offen/erstattungen', '/app/offen/erwartet'] as $pfad) {
            self::assertStringContainsString('href="' . $pfad . '"', $seite->body);
        }
    }

    public function testTheYearCanBeSwitchedAndOnlyAPlainPastOrCurrentYearIsTaken(): void
    {
        $konto = $this->bankkonto('Girokonto', '2024-01-01', '1000,00');
        $this->buchung($konto, '2024-06-01', -999);
        $this->buchung($konto, '2025-03-01', 50000);

        $seite = $this->get('/app', ['jahr' => '2024']);
        self::assertStringContainsString('Übersicht 2024', $seite->body);
        self::assertSame('0,00 €', $this->kennzahl($seite->body, 'Einnahmen'));
        self::assertSame('9,99 €', $this->kennzahl($seite->body, 'Ausgaben'));
        self::assertSame('-9,99 €', $this->kennzahl($seite->body, 'Saldo'));
        self::assertStringContainsString('Fehlbetrag', $seite->body);
        self::assertMatchesRegularExpression('#>Girokonto</a>.*?990,01 €#s', $seite->body);
        self::assertStringContainsString('href="/app?jahr=2023"', $seite->body);
        self::assertStringContainsString('href="/app?jahr=2025"', $seite->body);

        $aktuell = (int) date('Y');
        foreach (['abc', '2999', (string) ($aktuell + 1), '1999', '20251', ' 2025'] as $falsch) {
            $seite = $this->get('/app', ['jahr' => $falsch]);
            self::assertSame(200, $seite->status, $falsch);
            self::assertStringContainsString('Übersicht ' . $aktuell, $seite->body, $falsch);
        }
        // The current year offers no year after it.
        self::assertStringNotContainsString('href="/app?jahr=' . ($aktuell + 1) . '"', $this->get('/app')->body);
    }

    public function testWithoutAnUnlockedVaultOnlyTheCountsShow(): void
    {
        $konto = $this->bankkonto('Girokonto', '2024-01-01', '1000,00');
        $this->buchung($konto, '2025-04-01', -12000);
        $this->dokument(DocumentStatus::Eingegangen);

        $seite = $this->get('/app', ['jahr' => '2025'], entsperrt: false);

        self::assertSame(200, $seite->status);
        self::assertStringContainsString('nur mit entsperrtem Tresor lesbar', $seite->body);
        self::assertStringNotContainsString('kennzahl-wert">120,00', $seite->body);
        self::assertStringNotContainsString('Girokonto', $seite->body);
        self::assertStringNotContainsString('Stand am Ende', $seite->body);
        self::assertSame(1, $this->zaehler($seite->body, 'Posteingang offen'));
        self::assertSame(1, $this->zaehler($seite->body, 'Buchungen ohne Beleg'));
        self::assertArrayNotHasKey('auswertung_cache', $_SESSION);
    }

    public function testACostCenterScopeSeesOnlyItsCostCenterAndNoBalances(): void
    {
        $jugend = $this->kostenstelle('Jugend');
        $herren = $this->kostenstelle('Herren');
        $konto = $this->bankkonto('Girokonto', '2024-01-01', '1000,00');
        $this->zuordnen($this->beleg(3000, kostenstelle: $jugend), $this->buchung($konto, '2025-05-01', -3000), 3000);
        $this->zuordnen($this->beleg(7000, kostenstelle: $herren), $this->buchung($konto, '2025-05-02', -7000), 7000);
        $this->buchung($konto, '2025-05-03', -999);
        $this->beleg(1100, kostenstelle: $jugend);
        $this->beleg(2200, kostenstelle: $herren);
        $this->dokument(DocumentStatus::Eingegangen, $jugend);
        $this->dokument(DocumentStatus::Eingegangen, $herren);
        $this->dokument(DocumentStatus::Eingegangen);
        $this->serie(new \DateTimeImmutable('today')->modify('-30 days'));

        $this->rolle(SystemRole::Vereinsverantwortlicher);
        new UserAccessRepository($this->pdo())->setCostCenters($this->userId, [$jugend]);

        $seite = $this->get('/app', ['jahr' => '2025']);

        self::assertSame(200, $seite->status);
        self::assertSame('0,00 €', $this->kennzahl($seite->body, 'Einnahmen'));
        self::assertSame('30,00 €', $this->kennzahl($seite->body, 'Ausgaben'));
        self::assertStringNotContainsString('Kontostände', $seite->body);
        self::assertStringNotContainsString('Girokonto', $seite->body);
        self::assertSame(1, $this->zaehler($seite->body, 'Posteingang offen'));
        self::assertSame(1, $this->zaehler($seite->body, 'Unbezahlte Rechnungen'));
        // Without `document.edit` the count shows, without a link to the review page.
        self::assertSame(0, $this->zaehler($seite->body, 'Belege in Prüfung'));
        self::assertStringNotContainsString('href="/app/belege/pruefen"', $seite->body);
        self::assertStringNotContainsString('Buchungen ohne Beleg', $seite->body);
        self::assertStringNotContainsString('Überfällige wiederkehrende Belege', $seite->body);
    }

    public function testAPeriodScopeOpensInItsYearAndSeesOnlyTheReleasedPart(): void
    {
        $konto = $this->bankkonto('Girokonto', '2024-01-01', '1000,00');
        $this->buchung($konto, '2025-03-01', 50000);
        $this->buchung($konto, '2025-08-01', -12000);
        $this->buchung($konto, '2024-06-01', -999);

        $this->rolle(SystemRole::Kassenpruefer);
        new UserAccessRepository($this->pdo())->setScope($this->userId, new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2025-06-30'));

        $seite = $this->get('/app');

        self::assertSame(200, $seite->status);
        self::assertStringContainsString('Übersicht 2025', $seite->body);
        self::assertStringContainsString('vom 01.01.2025', $seite->body);
        self::assertStringContainsString('bis 30.06.2025', $seite->body);
        self::assertSame('500,00 €', $this->kennzahl($seite->body, 'Einnahmen'));
        self::assertSame('0,00 €', $this->kennzahl($seite->body, 'Ausgaben'));
        // The balance at the end of the released period, the booking before it in the carry-over.
        self::assertStringContainsString('Stand am Ende des 30.06.2025', $seite->body);
        self::assertMatchesRegularExpression('#>Girokonto</a>.*?1\.490,01 €#s', $seite->body);

        $vorher = $this->get('/app', ['jahr' => '2024']);
        self::assertStringContainsString('außerhalb des für Sie freigegebenen Zeitraums', $vorher->body);
        self::assertStringNotContainsString('9,99', $vorher->body);
    }

    public function testAChangeShowsAtOnceDespiteTheSessionCache(): void
    {
        $konto = $this->bankkonto('Girokonto', '2024-01-01', '1000,00');
        $this->buchung($konto, '2025-04-01', -12000);
        self::assertSame('120,00 €', $this->kennzahl($this->get('/app', ['jahr' => '2025'])->body, 'Ausgaben'));
        self::assertArrayHasKey('auswertung_cache', $_SESSION, 'the figures went through the session cache');

        // A new booking.
        $neu = $this->buchung($konto, '2025-05-01', -3000);
        $seite = $this->get('/app', ['jahr' => '2025']);
        self::assertSame('150,00 €', $this->kennzahl($seite->body, 'Ausgaben'));
        self::assertMatchesRegularExpression('#>Girokonto</a>.*?850,00 €#s', $seite->body);

        // An allocation moves the booking into a receipt's cost center: a
        // reader of that cost center sees it ...
        $jugend = $this->kostenstelle('Jugend');
        $beleg = $this->beleg(3000, kostenstelle: $jugend);
        $this->zuordnen($beleg, $neu, 3000);
        $this->rolle(SystemRole::Vereinsverantwortlicher);
        new UserAccessRepository($this->pdo())->setCostCenters($this->userId, [$jugend]);
        self::assertSame('30,00 €', $this->kennzahl($this->get('/app', ['jahr' => '2025'])->body, 'Ausgaben'));

        // ... and no longer once the allocation row alone is deleted - no
        // updated_at moves anywhere, the cached entry must not survive it.
        $allocation = new AllocationRepository($this->pdo())->forTransaction($neu)[0]->id;
        new AllocationRepository($this->pdo())->delete($allocation);
        self::assertSame('0,00 €', $this->kennzahl($this->get('/app', ['jahr' => '2025'])->body, 'Ausgaben'));

        // A receipt changing status (checked -> rejected) changes the receipt basis.
        $stand = new AuswertungsStandRepository($this->pdo());
        $vorher = $stand->fingerabdruck();
        $this->pdo()->prepare('UPDATE document SET status = ? WHERE id = ?')->execute([DocumentStatus::Abgelehnt->value, $this->dokumentVon($beleg)]);
        self::assertNotSame($vorher, $stand->fingerabdruck());
    }

    public function testTheFingerprintFollowsEveryReportTableAndNothingElse(): void
    {
        $stand = new AuswertungsStandRepository($this->pdo());
        $leer = $stand->fingerabdruck();
        self::assertNotNull($leer);
        self::assertSame($leer, $stand->fingerabdruck(), 'stable without a change');

        // A setting or an audit entry is no report input.
        new SettingRepository($this->pdo())->set('dashboard_test', '1');
        self::assertSame($leer, $stand->fingerabdruck());

        $konto = $this->bankkonto('Girokonto', '2024-01-01', '1000,00');
        $mitKonto = $stand->fingerabdruck();
        self::assertNotSame($leer, $mitKonto);

        $buchung = $this->buchung($konto, '2025-04-01', -12000);
        $mitBuchung = $stand->fingerabdruck();
        self::assertNotSame($mitKonto, $mitBuchung);

        $beleg = $this->beleg(12000);
        $mitBeleg = $stand->fingerabdruck();
        self::assertNotSame($mitBuchung, $mitBeleg);

        $this->zuordnen($beleg, $buchung, 12000);
        $zugeordnet = $stand->fingerabdruck();
        self::assertNotSame($mitBeleg, $zugeordnet);

        // A new ciphertext with the same timestamps (re-encryption).
        $this->pdo()->prepare('UPDATE invoice SET data_enc = CONCAT(data_enc, ?) WHERE id = ?')->execute(['x', $beleg]);
        self::assertNotSame($zugeordnet, $stand->fingerabdruck());
    }

    public function testAnAccountWithoutRightsSeesNoFigures(): void
    {
        new RoleRepository($this->pdo())->assignToUser($this->userId, []);
        $this->dokument(DocumentStatus::Eingegangen);

        $seite = $this->get('/app');

        self::assertSame(200, $seite->status);
        self::assertStringContainsString('keine Auswertungen freigeschaltet', $seite->body);
        self::assertStringNotContainsString('Posteingang offen', $seite->body);
        self::assertStringNotContainsString('Einnahmen', $seite->body);
    }

    // ------------------------------------------------------------ scaffolding

    /** The amount shown in the tile labelled $label. */
    private function kennzahl(string $html, string $label): string
    {
        self::assertSame(1, preg_match('#<dt>' . preg_quote($label, '#') . '</dt>\s*<dd class="kennzahl-wert">([^<]*)</dd>#', $html, $treffer), $label);

        return html_entity_decode($treffer[1]);
    }

    /** The count shown in the tile labelled $label. */
    private function zaehler(string $html, string $label): int
    {
        self::assertSame(1, preg_match('#<span class="kennzahl-wert">(\d+)</span>\s*<span>' . preg_quote($label, '#') . '</span>#', $html, $treffer), $label);

        return (int) $treffer[1];
    }

    private function rolle(SystemRole $rolle): void
    {
        $rollen = new RoleRepository($this->pdo());
        $id = $rollen->findSystem($rolle)?->id;
        assert($id !== null);
        $rollen->assignToUser($this->userId, [$id]);
    }

    private function kostenstelle(string $name): int
    {
        $this->pdo()->prepare('INSERT INTO cost_center (name) VALUES (?)')->execute([$name]);

        return (int) $this->pdo()->lastInsertId();
    }

    private function dokument(DocumentStatus $status, ?int $kostenstelle = null): int
    {
        ++$this->laufnummer;

        return new DocumentRepository($this->pdo())->insert(
            DocumentSource::Intern,
            null,
            [],
            $this->tresor->sealDataKey(DataKey::generate()),
            new \DateTimeImmutable()->modify('-' . $this->laufnummer . ' seconds'),
            $status,
            costCenterId: $kostenstelle,
            createdBy: $this->userId,
        );
    }

    private function beleg(int $brutto, Zahlungsweg $weg = Zahlungsweg::Konto, ?int $kostenstelle = null): int
    {
        $dokument = $this->dokument(DocumentStatus::Geprueft, $kostenstelle);
        $schluessel = DataKey::generate();
        $rechnungen = new InvoiceRepository($this->pdo());
        $id = $rechnungen->insert(
            $dokument,
            new InvoiceStructure(InvoiceType::Rechnung, InvoiceDirection::Ausgabe, null, new \DateTimeImmutable('2025-03-14'), null, null, null, null, $kostenstelle, $weg),
            $this->tresor->sealDataKey($schluessel),
            null,
            $this->userId,
            new \DateTimeImmutable(),
        );
        $daten = new InvoiceData('', $brutto, null, [], 'EUR', '', '');
        $rechnungen->setData($id, FieldCipher::encrypt($schluessel, json_encode($daten->toPayload(), JSON_THROW_ON_ERROR), new FieldContext('invoice', $id, 'data_enc')));

        return $id;
    }

    private function dokumentVon(int $invoiceId): int
    {
        return new InvoiceRepository($this->pdo())->find($invoiceId)?->documentId ?? self::fail('no invoice');
    }

    private function serie(\DateTimeImmutable $naechste): void
    {
        $lieferanten = new SupplierService($this->pdo(), new SupplierRepository($this->pdo()), new CategoryRepository($this->pdo()));
        $lieferant = $lieferanten->anlegen($this->tresor, ['name' => 'Lieferant ' . (++$this->laufnummer), 'rolle' => SupplierRole::Lieferant->value], new \DateTimeImmutable())->id;
        new RecurringSeriesRepository($this->pdo())->insert(
            $lieferant,
            InvoiceDirection::Ausgabe,
            SeriesPeriod::Monat,
            SeriesStatus::Bestaetigt,
            $naechste,
            5,
            $this->tresor->sealDataKey(DataKey::generate()),
            new \DateTimeImmutable(),
        );
    }

    private function bankkonto(string $name, string $eroeffnet, string $bestand): int
    {
        return new BankAccountService($this->pdo(), new BankAccountRepository($this->pdo()))->anlegen(
            $this->tresor,
            $name === 'Kasse' ? BankAccountKind::Kasse : BankAccountKind::Bank,
            ['name' => $name, 'iban' => '', 'bic' => '', 'bank' => '', 'opening_balance' => $bestand, 'opening_date' => $eroeffnet],
            new \DateTimeImmutable(),
        )->id;
    }

    private function buchung(int $konto, string $datum, int $cent): int
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
        $dashboard = static fn(): DashboardController => new DashboardController(
            $view,
            new Session(),
            new SessionVault(),
            static fn(): Dashboard => new Dashboard(
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
                new DocumentRepository($pdo),
                new InvoiceRepository($pdo),
                new BankTransactionRepository($pdo),
                new RecurringSeriesRepository($pdo),
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
            dashboard: $dashboard,
        );

        return new Kernel(
            router: $router,
            staticFiles: new StaticFileHandler($paths->publicDir(), longCache: false),
            view: $view,
            debug: true,
        );
    }
}
