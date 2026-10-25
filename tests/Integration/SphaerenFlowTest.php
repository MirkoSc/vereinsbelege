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
use App\Domain\Sphere;
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
use App\Service\MasterData\Sphaereneinstellung;
use App\Service\MasterData\SupplierService;
use App\Service\Migration\Migrator;
use App\Service\Report\AuswertungsCache;
use App\Service\Report\Auswertungsdaten;
use App\Service\Report\Geldfluss;
use App\Service\Report\Sphaerenbericht;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\ZuordnungFabrik;
use App\View\View;

/**
 * The tax spheres in the reports (issue #84, E-57) through the real route
 * table, guard, services, schema, vault and session cache: switched off
 * (the default, E-15) the view does not exist and nothing shows; switched
 * on, income and expenses per sphere on both bases - the receipt's own
 * sphere first, else its category's default, else "ohne Sphäre" - and no
 * stale figure after a default, a receipt or the switch changes. Synthetic
 * data; past years only.
 */
final class SphaerenFlowTest extends DatabaseTestCase
{
    private Vault $tresor;
    private AuditLog $audit;
    private int $userId;
    private int $laufnummer = 0;

    private int $platz;
    private int $beitrag;
    private int $kiosk;
    private int $belegOhne;

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
        $rollen = new RoleRepository($this->pdo());
        $rollen->assignToUser($this->userId, [$rollen->findSystem(SystemRole::Finanzen)->id ?? self::fail()]);

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

    /** E-15: switched off, the view is 404 and no page links it. */
    public function testSwitchedOffThereIsNoSphereView(): void
    {
        self::assertSame(404, $this->get('/app/auswertung/sphaeren', ['art' => 'jahr', 'jahr' => '2025'])->status);

        $geldfluss = $this->get('/app/auswertung/geldfluss', ['art' => 'jahr', 'jahr' => '2025']);
        self::assertSame(200, $geldfluss->status);
        self::assertStringNotContainsString('/app/auswertung/sphaeren', $geldfluss->body);
        self::assertStringNotContainsString('Sphäre', $geldfluss->body);
    }

    public function testOnTheReceiptBasisPerSphere(): void
    {
        $this->schalten(true);

        $seite = $this->get('/app/auswertung/sphaeren', ['art' => 'jahr', 'jahr' => '2025', 'grundlage' => 'belege']);

        self::assertSame(200, $seite->status);
        self::assertSame('no-store, private', $seite->headers['Cache-Control'] ?? null);
        self::assertStringContainsString('Steuerberatung', $seite->body);
        self::assertStringContainsString('aria-current="page">Sphären</a>', $seite->body);
        // Own sphere beats the category's default (Beitrag -> VV, not ideell);
        // the Kiosk receipt takes its category's; the Platz receipt has none.
        self::assertSame([
            ['Ideeller Bereich', '0,00 €', '0,00 €', '0,00 €', '0'],
            ['Vermögensverwaltung', '200,00 €', '0,00 €', '200,00 €', '1'],
            ['Zweckbetrieb', '0,00 €', '300,00 €', '-300,00 €', '1'],
            ['Wirtschaftlicher Geschäftsbetrieb', '500,00 €', '0,00 €', '500,00 €', '1'],
            ['ohne Sphäre', '0,00 €', '50,00 €', '-50,00 €', '1'],
        ], $this->tabelle($seite->body));
        self::assertMatchesRegularExpression('#Summe</th>\s*<td class="zahl" data-label="Einnahmen"><strong>700,00 €</strong></td>\s*<td class="zahl" data-label="Ausgaben"><strong>350,00 €</strong></td>\s*<td class="zahl" data-label="Saldo"><strong>350,00 €</strong>#', $seite->body);
        self::assertStringContainsString('1 Posten ohne eigene Sphäre übernimmt die Vorgabe ihrer Kategorie.', $seite->body);
        self::assertStringContainsString('Posten „ohne Sphäre“', $seite->body);
    }

    public function testOnTheBookingBasisABookingWithoutReceiptTakesItsCategorysDefault(): void
    {
        $this->schalten(true);

        $seite = $this->get('/app/auswertung/sphaeren', ['art' => 'jahr', 'jahr' => '2025']);

        self::assertSame([
            ['Ideeller Bereich', '1.000,00 €', '0,00 €', '1.000,00 €', '1'],
            ['Vermögensverwaltung', '0,00 €', '0,00 €', '0,00 €', '0'],
            ['Zweckbetrieb', '0,00 €', '300,00 €', '-300,00 €', '1'],
            ['Wirtschaftlicher Geschäftsbetrieb', '0,00 €', '0,00 €', '0,00 €', '0'],
        ], $this->tabelle($seite->body), 'the allocated receipt brings its own sphere, nothing is without one');
        self::assertStringNotContainsString('Posten „ohne Sphäre“', $seite->body);
    }

    /**
     * The session cache (E-48) keeps the stored receipt sphere only: a new
     * category default shows on the next view, a changed receipt rebuilds
     * the cache, and the switch takes effect at once.
     */
    public function testNoStaleFigureAfterADefaultAReceiptOrTheSwitchChanges(): void
    {
        $this->schalten(true);
        $query = ['art' => 'jahr', 'jahr' => '2025', 'grundlage' => 'belege'];
        self::assertSame(['ohne Sphäre', '0,00 €', '50,00 €', '-50,00 €', '1'], $this->tabelle($this->get('/app/auswertung/sphaeren', $query)->body)[4]);
        self::assertNotSame([], $_SESSION['auswertung_cache']['eintraege'] ?? [], 'the input is cached');

        new CategoryRepository($this->pdo())->setDefaultSphere($this->platz, Sphere::Ideell);
        $zeilen = $this->tabelle($this->get('/app/auswertung/sphaeren', $query)->body);
        self::assertSame(['Ideeller Bereich', '0,00 €', '50,00 €', '-50,00 €', '1'], $zeilen[0]);
        self::assertCount(4, $zeilen, 'nothing is without a sphere any more');

        $this->pdo()->prepare('UPDATE invoice SET sphere = ? WHERE id = ?')->execute([Sphere::Wirtschaftlich->value, $this->belegOhne]);
        $zeilen = $this->tabelle($this->get('/app/auswertung/sphaeren', $query)->body);
        self::assertSame(['Ideeller Bereich', '0,00 €', '0,00 €', '0,00 €', '0'], $zeilen[0]);
        self::assertSame(['Wirtschaftlicher Geschäftsbetrieb', '500,00 €', '50,00 €', '450,00 €', '2'], $zeilen[3]);

        $this->schalten(false);
        self::assertSame(404, $this->get('/app/auswertung/sphaeren', $query)->status);
        self::assertStringNotContainsString('/app/auswertung/sphaeren', $this->get('/app/auswertung/geldfluss', $query)->body);
        $this->schalten(true);
        self::assertSame(200, $this->get('/app/auswertung/sphaeren', $query)->status);
        self::assertStringContainsString('href="/app/auswertung/sphaeren"', $this->get('/app/auswertung/geldfluss', $query)->body);
    }

    public function testWithoutAnUnlockedVaultNoFigures(): void
    {
        $this->schalten(true);

        $seite = $this->get('/app/auswertung/sphaeren', ['art' => 'jahr', 'jahr' => '2025'], entsperrt: false);

        self::assertSame(200, $seite->status);
        self::assertStringContainsString('nur mit entsperrtem Tresor lesbar', $seite->body);
        self::assertSame([], $this->tabelle($seite->body));
    }

    // ----------------------------------------------------------- scaffolding

    private function schalten(bool $an): void
    {
        new Sphaereneinstellung(new SettingRepository($this->pdo()))->setzen($an);
    }

    private function beispieldaten(): void
    {
        $kategorien = new CategoryRepository($this->pdo());
        $this->platz = $kategorien->create('Testkat Platzpflege', CategoryDirection::Ausgabe, null, '');
        $this->beitrag = $kategorien->create('Testkat Beiträge', CategoryDirection::Einnahme, null, '');
        $kategorien->setDefaultSphere($this->beitrag, Sphere::Ideell);
        $this->kiosk = $kategorien->create('Testkat Kiosk', CategoryDirection::Einnahme, null, '');
        $kategorien->setDefaultSphere($this->kiosk, Sphere::Wirtschaftlich);

        $konto = $this->bankkonto();
        $rasen = $this->beleg(30000, '2025-03-14', InvoiceDirection::Ausgabe, $this->platz, Sphere::Zweckbetrieb);
        $this->zuordnen($rasen, $this->buchung($konto, '2025-03-20', -30000), 30000);
        $this->beleg(50000, '2025-04-01', InvoiceDirection::Einnahme, $this->kiosk, null);
        $this->belegOhne = $this->beleg(5000, '2025-05-01', InvoiceDirection::Ausgabe, $this->platz, null);
        $this->beleg(20000, '2025-06-01', InvoiceDirection::Einnahme, $this->beitrag, Sphere::Vermoegensverwaltung);
        // Income without receipt: its category's default (ideell).
        $this->buchung($konto, '2025-07-01', 100000, $this->beitrag);
        // Outside the period.
        $this->beleg(999, '2024-06-01', InvoiceDirection::Ausgabe, $this->platz, null);
    }

    /**
     * Rows of the sphere table: name, income, expenses, balance, count.
     *
     * @return list<list<string>>
     */
    private function tabelle(string $html): array
    {
        if (preg_match('#<section aria-labelledby="sphaeren-tabelle">(.*?)</tbody>#s', $html, $abschnitt) !== 1) {
            return [];
        }
        preg_match_all('#<tr>\s*<th scope="row">([^<]*)</th>\s*<td class="zahl" data-label="Einnahmen">([^<]*)</td>\s*<td class="zahl" data-label="Ausgaben">([^<]*)</td>\s*<td class="zahl" data-label="Saldo">([^<]*)</td>\s*<td class="zahl" data-label="Posten">(\d+)</td>#s', $abschnitt[1], $treffer, PREG_SET_ORDER);

        return array_map(static fn(array $t): array => array_map(html_entity_decode(...), array_slice($t, 1)), $treffer);
    }

    private function beleg(int $brutto, string $datum, InvoiceDirection $richtung, ?int $kategorie, ?Sphere $sphaere): int
    {
        ++$this->laufnummer;
        $dokument = new DocumentRepository($this->pdo())->insert(
            DocumentSource::Intern,
            null,
            [],
            $this->tresor->sealDataKey(DataKey::generate()),
            new \DateTimeImmutable()->modify('-' . $this->laufnummer . ' seconds'),
            DocumentStatus::Geprueft,
            createdBy: $this->userId,
        );
        $schluessel = DataKey::generate();
        $rechnungen = new InvoiceRepository($this->pdo());
        $id = $rechnungen->insert(
            $dokument,
            new InvoiceStructure(InvoiceType::Rechnung, $richtung, null, new \DateTimeImmutable($datum), null, null, null, $kategorie, null, sphere: $sphaere),
            $this->tresor->sealDataKey($schluessel),
            null,
            $this->userId,
            new \DateTimeImmutable(),
        );
        $daten = new InvoiceData('', $brutto, null, [], 'EUR', '', '');
        $rechnungen->setData($id, FieldCipher::encrypt($schluessel, json_encode($daten->toPayload(), JSON_THROW_ON_ERROR), new FieldContext('invoice', $id, 'data_enc')));

        return $id;
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
        ZuordnungFabrik::bauen($this->pdo(), $this->audit)->zuordnen($this->tresor, $beleg, $buchung, $betrag, ZuordnungMethode::Manuell, null, null, $this->userId, '192.0.2.10', new \DateTimeImmutable());
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
        $quelle = static fn(): AuswertungsCache => new AuswertungsCache(
            new Auswertungsdaten(
                new InvoiceRepository($pdo),
                new BankTransactionRepository($pdo),
                ZuordnungFabrik::bauen($pdo, $audit),
                new BankAccountService($pdo, new BankAccountRepository($pdo)),
                new SettingRepository($pdo),
            ),
            new AuswertungsStandRepository($pdo),
            $userId,
        );
        $geldfluss = static fn(): AuswertungController => new AuswertungController(
            $view,
            new Session(),
            new SessionVault(),
            static fn(): Geldfluss => new Geldfluss(
                $quelle(),
                new CategoryRepository($pdo),
                new CostCenterRepository($pdo),
                new InvoiceRepository($pdo),
                new BankTransactionRepository($pdo),
                new SupplierService($pdo, new SupplierRepository($pdo), new CategoryRepository($pdo)),
            ),
            static fn(): Sphaereneinstellung => new Sphaereneinstellung(new SettingRepository($pdo)),
            static fn(): Sphaerenbericht => new Sphaerenbericht($quelle(), new CategoryRepository($pdo)),
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
