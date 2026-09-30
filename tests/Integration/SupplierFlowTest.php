<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\App\SupplierController;
use App\Config\Paths;
use App\Domain\AuditAction;
use App\Domain\AuditEntry;
use App\Domain\Category;
use App\Domain\CategoryDirection;
use App\Domain\SupplierData;
use App\Domain\SupplierKeyKind;
use App\Domain\SupplierRole;
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
use App\Repository\AuditLogRepository;
use App\Repository\CategoryRepository;
use App\Repository\RoleRepository;
use App\Repository\SupplierRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\Service\Account\SessionVault;
use App\Service\Audit\AuditFilter;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\MasterData\CategoryService;
use App\Service\MasterData\SupplierKeys;
use App\Service\MasterData\SupplierService;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;
use App\View\View;

/**
 * Suppliers and payers end to end (M6-2, issue #36): the real route table,
 * the real guard, the real controller, service, repositories and schema,
 * with a real vault.
 */
final class SupplierFlowTest extends DatabaseTestCase
{
    private const string IBAN_1 = 'DE89370400440532013000';
    private const string IBAN_2 = 'DE02120300000000202051';
    private const string IBAN_3 = 'DE02100500000054540402';

    private Vault $tresor;
    private AuditLog $audit;
    private SupplierRepository $lieferanten;
    private CategoryRepository $kategorien;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->tresor = Vault::create();
        new VaultRepository($this->pdo())->insert($this->tresor->publicKey());
        $this->audit = new AuditLog(new AuditLogRepository($this->pdo()), new VaultRepository($this->pdo()), new ServerCrypto(random_bytes(32)));
        $this->lieferanten = new SupplierRepository($this->pdo());
        $this->kategorien = new CategoryRepository($this->pdo());

        $this->userId = new UserRepository($this->pdo())->insert('enc', random_bytes(32), 'enc', 'hash', mfaRequired: false);
        $rollen = new RoleRepository($this->pdo());
        $finanzen = $rollen->findSystem(SystemRole::Finanzen)?->id;
        assert($finanzen !== null);
        $rollen->assignToUser($this->userId, [$finanzen]);

        new Session()->start();
        $jetzt = time();
        $_SESSION = ['user_id' => $this->userId, 'login_at' => $jetzt, 'last_seen_at' => $jetzt, 'session_epoch' => 0];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    // ------------------------------------------------------- acceptance

    /**
     * Acceptance criteria "Mehrere IBANs und Aliasse je Lieferant" and
     * "Name und IBANs verschlüsselt gespeichert".
     */
    public function testASupplierKeepsSeveralIbansAndAliasesAndStoresThemEncrypted(): void
    {
        $antwort = $this->post('/app/lieferanten', $this->felder([
            'name' => 'Getränke Müller GmbH',
            'aliases' => "Mueller Getraenkehandel\n\n  Getränkemarkt Müller  ",
            'ibans' => "DE89 3704 0044 0532 0130 00\nde02120300000000202051",
            'vat_id' => 'DE 123456789',
        ]));

        self::assertSame(302, $antwort->status);
        $id = $this->einzigerLieferant();
        self::assertSame('/app/lieferanten/' . $id, $antwort->headers['Location'] ?? null);

        $seite = $this->get('/app/lieferanten/' . $id);
        self::assertSame(200, $seite->status);
        self::assertStringContainsString('Getränke Müller GmbH', $seite->body);
        self::assertStringContainsString("Mueller Getraenkehandel\nGetränkemarkt Müller", $seite->body, 'aliases trimmed, empty lines dropped');
        self::assertStringContainsString("DE89 3704 0044 0532 0130 00\nDE02 1203 0000 0000 2020 51", $seite->body, 'both IBANs, normalised and grouped');
        self::assertStringContainsString('DE123456789', $seite->body);

        $roh = $this->rohTabelle('supplier') . $this->rohTabelle('supplier_key');
        foreach (['Getränke Müller', 'Mueller Getraenkehandel', 'Getränkemarkt', self::IBAN_1, self::IBAN_2, 'DE89 3704', 'DE123456789', 'getraenke mueller'] as $klartext) {
            self::assertStringNotContainsStringIgnoringCase($klartext, $roh, $klartext . ' is not stored in the clear');
        }
    }

    /**
     * Acceptance criterion "Blind-Index-Schlüssel supplier_key wird
     * gepflegt": exactly the keys of the saved data, recomputed with the
     * vault's blind index key, after creating and after every change.
     */
    public function testTheSupplierKeysFollowEverySave(): void
    {
        $this->post('/app/lieferanten', $this->felder([
            'name' => 'Muster GmbH',
            'aliases' => 'Muster Sportbedarf',
            'ibans' => self::IBAN_1 . "\n" . self::IBAN_2,
            'vat_id' => 'DE123456789',
            'tax_number' => '12/345/67890',
            'creditor_id' => 'DE98ZZZ09999999999',
            'mandate_refs' => 'M-1',
        ]));
        $id = $this->einzigerLieferant();

        self::assertSame($this->erwarteteKeys(new SupplierData(
            name: 'Muster GmbH',
            aliases: ['Muster Sportbedarf'],
            ibans: [self::IBAN_1, self::IBAN_2],
            vatId: 'DE123456789',
            taxNumber: '12/345/67890',
            creditorId: 'DE98ZZZ09999999999',
            mandateRefs: ['M-1'],
        )), $this->gespeicherteKeys($id));
        self::assertSame([$id], $this->lieferanten->idsWithKey(
            SupplierKeyKind::Name,
            $this->tresor->blindIndex()->forValue('supplier.name', SupplierKeys::name('MUSTER  gmbh & Co. KG')),
        ), 'another spelling of the name finds the supplier');

        // One IBAN, the alias and the VAT id removed, a new IBAN added.
        $this->post('/app/lieferanten/' . $id, $this->felder([
            'name' => 'Muster GmbH',
            'ibans' => self::IBAN_2 . "\n" . self::IBAN_3,
            'tax_number' => '12/345/67890',
            'creditor_id' => 'DE98ZZZ09999999999',
            'mandate_refs' => 'M-1',
        ]));

        self::assertSame($this->erwarteteKeys(new SupplierData(
            name: 'Muster GmbH',
            ibans: [self::IBAN_2, self::IBAN_3],
            taxNumber: '12/345/67890',
            creditorId: 'DE98ZZZ09999999999',
            mandateRefs: ['M-1'],
        )), $this->gespeicherteKeys($id));
        self::assertSame([], $this->lieferanten->idsWithKey(SupplierKeyKind::Iban, $this->tresor->blindIndex()->forValue('supplier.iban', self::IBAN_1)));
    }

    public function testCreateChangeDeleteAreAuditedWithFieldNamesOnly(): void
    {
        $this->post('/app/lieferanten', $this->felder(['name' => 'Anna Beispiel', 'rolle' => 'zahler', 'ibans' => self::IBAN_1]));
        $id = $this->einzigerLieferant();
        $this->post('/app/lieferanten/' . $id, $this->felder(['name' => 'Anna Beispiel', 'rolle' => 'zahler', 'ibans' => self::IBAN_2]));
        // Saving without a change writes no audit row.
        $this->post('/app/lieferanten/' . $id, $this->felder(['name' => 'Anna Beispiel', 'rolle' => 'zahler', 'ibans' => self::IBAN_2]));
        $this->post('/app/lieferanten/' . $id . '/loeschen', []);

        self::assertNull($this->lieferanten->find($id));
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM supplier_key')->fetchColumn(), 'the keys go with the supplier');

        $zeilen = new AuditLogRepository($this->pdo())->page(new AuditFilter(entity: 'supplier', entityId: $id), null, 10);
        self::assertSame(
            [AuditAction::LieferantGeloescht->value, AuditAction::LieferantGeaendert->value, AuditAction::LieferantAngelegt->value],
            array_map(static fn(AuditEntry $e): string => $e->action, $zeilen),
        );
        self::assertSame($this->userId, $zeilen[0]->userId);

        self::assertSame(['felder' => ['role', 'name', 'iban']], $this->audit->details($zeilen[2], $this->tresor));
        self::assertSame(['felder' => ['iban']], $this->audit->details($zeilen[1], $this->tresor));
        self::assertSame(['rolle' => 'zahler'], $this->audit->details($zeilen[0], $this->tresor));
    }

    // ------------------------------------------------------------ rules

    public function testAnInvalidIbanIsRefused(): void
    {
        $antwort = $this->post('/app/lieferanten', $this->felder(['name' => 'Muster', 'ibans' => self::IBAN_1 . "\nDE89370400440532013001"]));

        self::assertSame(422, $antwort->status);
        self::assertStringContainsString('Die 2. IBAN ist ungültig', $antwort->body);
        self::assertStringContainsString('DE89370400440532013001', $antwort->body, 'the form keeps what was typed');
        self::assertSame([], $this->lieferanten->all());
    }

    public function testAnIdentifierOfAnotherSupplierIsRefusedWithALinkToIt(): void
    {
        $this->post('/app/lieferanten', $this->felder(['name' => 'Erster Lieferant', 'ibans' => self::IBAN_1, 'vat_id' => 'DE123456789']));
        $erster = $this->einzigerLieferant();

        $antwort = $this->post('/app/lieferanten', $this->felder(['name' => 'Zweiter Lieferant', 'ibans' => 'de89 3704 0044 0532 0130 00']));
        self::assertSame(422, $antwort->status);
        self::assertStringContainsString('Diese IBAN ist bereits beim Lieferanten „Erster Lieferant“ hinterlegt', $antwort->body);
        self::assertStringContainsString('href="/app/lieferanten/' . $erster . '"', $antwort->body);

        $antwort = $this->post('/app/lieferanten', $this->felder(['name' => 'Zweiter Lieferant', 'vat_id' => 'de 123 456 789']));
        self::assertSame(422, $antwort->status);
        self::assertStringContainsString('Diese USt-ID ist bereits', $antwort->body);
        self::assertCount(1, $this->lieferanten->all());

        // Saving the first one again with its own IBAN is no conflict.
        $antwort = $this->post('/app/lieferanten/' . $erster, $this->felder(['name' => 'Erster Lieferant', 'ibans' => self::IBAN_1, 'vat_id' => 'DE123456789']));
        self::assertSame(302, $antwort->status);
    }

    public function testTheSameNameIsAllowedButHinted(): void
    {
        $this->post('/app/lieferanten', $this->felder(['name' => 'Muster GmbH']));
        $antwort = $this->post('/app/lieferanten', $this->felder(['name' => 'Anders', 'aliases' => 'MUSTER e.K.']));

        self::assertSame(302, $antwort->status);
        self::assertCount(2, $this->lieferanten->all());
        self::assertSame('warnung', $_SESSION['flash']['art'] ?? null);
        self::assertStringContainsString('denselben Namen oder Alias', (string) ($_SESSION['flash']['text'] ?? ''));
    }

    public function testTheDefaultCategoryMustFitTheRoleAndIsThenInUse(): void
    {
        $einnahme = $this->kategorie('Sponsoring & Bandenwerbung');
        $ausgabe = $this->kategorie('Platzpflege & Grünanlagen');

        $antwort = $this->post('/app/lieferanten', $this->felder(['name' => 'Rasen GmbH', 'rolle' => 'lieferant', 'kategorie' => (string) $einnahme->id]));
        self::assertSame(422, $antwort->status);
        self::assertStringContainsString('passt nicht zur Rolle', $antwort->body);

        $this->post('/app/lieferanten', $this->felder(['name' => 'Rasen GmbH', 'rolle' => 'lieferant', 'kategorie' => (string) $ausgabe->id]));
        $this->post('/app/lieferanten', $this->felder(['name' => 'Autohaus Sponsor', 'rolle' => 'zahler', 'kategorie' => (string) $einnahme->id]));
        self::assertCount(2, $this->lieferanten->all());

        // The category cannot be deleted while a supplier uses it.
        self::assertSame(1, $this->kategorien->usageCount($ausgabe->id));
        $this->expectException(\App\Service\MasterData\CategoryRuleViolation::class);
        new CategoryService($this->kategorien)->loeschen($ausgabe->id);
    }

    public function testAnInactiveCategoryIsRefusedForNewSuppliers(): void
    {
        $ausgabe = $this->kategorie('Platzpflege & Grünanlagen');
        $this->kategorien->update($ausgabe->id, $ausgabe->name, $ausgabe->direction, $ausgabe->color, $ausgabe->aiHint, false);

        $antwort = $this->post('/app/lieferanten', $this->felder(['name' => 'Rasen GmbH', 'kategorie' => (string) $ausgabe->id]));
        self::assertSame(422, $antwort->status);
        self::assertStringContainsString('deaktiviert', $antwort->body);
    }

    public function testANameIsRequired(): void
    {
        $antwort = $this->post('/app/lieferanten', $this->felder(['name' => '   ']));

        self::assertSame(422, $antwort->status);
        self::assertStringContainsString('Bitte einen Namen angeben.', $antwort->body);
    }

    // ------------------------------------------------------------- list

    public function testTheListIsSortedByNameAndFiltersByRoleAndSearch(): void
    {
        $this->post('/app/lieferanten', $this->felder(['name' => 'Zeltverleih Nord']));
        $this->post('/app/lieferanten', $this->felder(['name' => 'Autohaus Sponsor', 'rolle' => 'zahler', 'ibans' => self::IBAN_1]));
        $this->post('/app/lieferanten', $this->felder(['name' => 'Müller Getränke', 'rolle' => 'beide']));

        $alle = $this->get('/app/lieferanten')->body;
        $positionen = array_map(static fn(string $n): int|false => strpos($alle, $n), ['Autohaus Sponsor', 'Müller Getränke', 'Zeltverleih Nord']);
        self::assertNotContains(false, $positionen);
        $sortiert = $positionen;
        sort($sortiert);
        self::assertSame($sortiert, $positionen, 'sorted by name, "Müller" between A and Z');

        $zahler = $this->get('/app/lieferanten', ['rolle' => 'zahler'])->body;
        self::assertStringContainsString('Autohaus Sponsor', $zahler);
        self::assertStringNotContainsString('Zeltverleih Nord', $zahler);

        $suche = $this->get('/app/lieferanten', ['q' => 'mueller'])->body;
        self::assertStringContainsString('Müller Getränke', $suche);
        self::assertStringNotContainsString('Autohaus Sponsor', $suche);

        $ibanSuche = $this->get('/app/lieferanten', ['q' => 'de89 3704'])->body;
        self::assertStringContainsString('Autohaus Sponsor', $ibanSuche);
        self::assertStringNotContainsString('Müller Getränke', $ibanSuche);
    }

    // ------------------------------------------------------------ vault

    public function testWithoutTheVaultNothingShowsAndNothingIsWritten(): void
    {
        $this->post('/app/lieferanten', $this->felder(['name' => 'Geheim GmbH']));
        $id = $this->einzigerLieferant();

        $liste = $this->get('/app/lieferanten', entsperrt: false);
        self::assertSame(200, $liste->status);
        self::assertStringContainsString('nur mit entsperrtem Tresor lesbar', $liste->body);
        self::assertStringNotContainsString('Geheim GmbH', $liste->body);

        $formular = $this->get('/app/lieferanten/' . $id, entsperrt: false);
        self::assertSame(302, $formular->status);
        self::assertSame('/app/lieferanten', $formular->headers['Location'] ?? null);

        $antwort = $this->post('/app/lieferanten', $this->felder(['name' => 'Neu GmbH']), entsperrt: false);
        self::assertSame(302, $antwort->status);
        self::assertSame('fehler', $_SESSION['flash']['art'] ?? null);
        $this->post('/app/lieferanten/' . $id . '/loeschen', [], entsperrt: false);
        self::assertCount(1, $this->lieferanten->all(), 'neither created nor deleted');
    }

    public function testWritesNeedTheCsrfToken(): void
    {
        $antwort = $this->dispatch(new Request(
            HttpMethod::Post,
            '/app/lieferanten',
            cookies: $this->tresorCookie(),
            post: [...$this->felder(['name' => 'Muster']), '_csrf' => 'falsch'],
        ));

        self::assertSame(302, $antwort->status);
        self::assertSame([], $this->lieferanten->all());
    }

    public function testAnUnknownSupplierIsNotFound(): void
    {
        self::assertSame(404, $this->get('/app/lieferanten/999')->status);
    }

    /**
     * Only structure in plaintext: no column that could hold a name or an
     * IBAN outside `data_enc`, and `supplier_key` holds nothing but blind
     * indexes.
     */
    public function testTheTablesKeepOnlyStructureInPlaintext(): void
    {
        self::assertSame(
            ['id', 'role', 'dek_sealed', 'data_enc', 'default_category_id', 'default_sphere', 'created_via', 'needs_review', 'merged_into', 'created_at', 'updated_at'],
            $this->spalten('supplier'),
        );
        self::assertSame(['id', 'supplier_id', 'kind', 'value_bi'], $this->spalten('supplier_key'));
    }

    // ---------------------------------------------------------- helpers

    /**
     * @param array<string, string> $felder
     *
     * @return array<string, string>
     */
    private function felder(array $felder): array
    {
        return [...[
            'name' => '', 'rolle' => SupplierRole::Lieferant->value, 'kategorie' => '', 'aliases' => '', 'ibans' => '',
            'address' => '', 'bic' => '', 'vat_id' => '', 'tax_number' => '', 'creditor_id' => '', 'mandate_refs' => '',
            'email' => '', 'website' => '', 'customer_number' => '', 'notes' => '',
        ], ...$felder];
    }

    private function einzigerLieferant(): int
    {
        $alle = $this->lieferanten->all();
        self::assertCount(1, $alle);

        return $alle[0]->id;
    }

    /**
     * @return list<string> kind + hex of the blind index, sorted
     */
    private function erwarteteKeys(SupplierData $daten): array
    {
        $index = $this->tresor->blindIndex();
        $keys = array_map(
            static fn(array $k): string => $k[0]->value . ':' . bin2hex($index->forValue($k[0]->purpose(), $k[1])),
            SupplierKeys::fuer($daten),
        );
        sort($keys);

        return $keys;
    }

    /**
     * @return list<string>
     */
    private function gespeicherteKeys(int $id): array
    {
        $keys = array_map(static fn(array $k): string => $k[0]->value . ':' . bin2hex($k[1]), $this->lieferanten->keys($id));
        sort($keys);

        return $keys;
    }

    private function kategorie(string $name): Category
    {
        foreach ($this->kategorien->all() as $kategorie) {
            if ($kategorie->name === $name) {
                return $kategorie;
            }
        }

        self::fail('No category ' . $name);
    }

    /**
     * @return list<string>
     */
    private function spalten(string $tabelle): array
    {
        $stmt = $this->pdo()->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position');
        $stmt->execute([$tabelle]);

        return array_map(strval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function rohTabelle(string $tabelle): string
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
     * @return array<string, string>
     */
    private function tresorCookie(): array
    {
        return [Cookie::vaultKey('x', Request::httpsFromGlobals())->name => new SessionVault()->store($this->tresor)];
    }

    /**
     * @param array<string, string> $query
     */
    private function get(string $pfad, array $query = [], bool $entsperrt = true): Response
    {
        return $this->dispatch(new Request(HttpMethod::Get, $pfad, query: $query, cookies: $entsperrt ? $this->tresorCookie() : []));
    }

    /**
     * @param array<string, string> $felder
     */
    private function post(string $pfad, array $felder, bool $entsperrt = true): Response
    {
        return $this->dispatch(new Request(
            HttpMethod::Post,
            $pfad,
            cookies: $entsperrt ? $this->tresorCookie() : [],
            post: [...$felder, '_csrf' => new Session()->csrfToken()],
        ));
    }

    private function dispatch(Request $request): Response
    {
        $antwort = $this->kernel()->handle($request);
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
        $lieferanten = fn(): SupplierController => new SupplierController(
            $view,
            new Session(),
            new SessionVault(),
            new SupplierService($pdo, new SupplierRepository($pdo), new CategoryRepository($pdo)),
            new CategoryRepository($pdo),
            $this->audit,
        );
        $unerreichbar = static fn(): never => throw new \LogicException('Diese Route gehört nicht zu diesem Test.');

        $router = new Router();
        (require dirname(__DIR__, 2) . '/app/src/routes.php')(
            $router,
            $view,
            $unerreichbar,
            $guard,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $lieferanten,
            $unerreichbar,
        );

        return new Kernel(
            router: $router,
            staticFiles: new StaticFileHandler($paths->publicDir(), longCache: false),
            view: $view,
            debug: true,
        );
    }
}
