<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Admin\CategoryController;
use App\Config\Paths;
use App\Domain\AuditAction;
use App\Domain\AuditEntry;
use App\Domain\Category;
use App\Domain\CategoryColor;
use App\Domain\CategoryDirection;
use App\Domain\SystemRole;
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
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\Service\Audit\AuditFilter;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\ServerCrypto;
use App\Service\MasterData\CategoryService;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;
use App\View\View;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The category pages end to end (M6-1, issue #35): the seed of
 * migrations/017_category.sql, the real route table, the real guard, the
 * real controller, the real schema.
 */
final class CategoryAdminFlowTest extends DatabaseTestCase
{
    /** docs/spec/02-datenmodell.md "Kategorien – Startbestand", in order. */
    private const array EINNAHMEN = [
        'Mitgliedsbeiträge',
        'Spenden',
        'Sponsoring & Bandenwerbung',
        'Zuschüsse (Verband, Gemeinde)',
        'Eintrittsgelder',
        'Verkauf Speisen & Getränke',
        'Vermietung Vereinsheim/Platz',
        'Veranstaltungserlöse',
        'Sonstige Einnahmen',
    ];

    private const array AUSGABEN = [
        'Verpflegung & Bewirtung',
        'Platzpflege & Grünanlagen',
        'Sportplatz-Instandhaltung',
        'Vereinsheim & Gebäude',
        'Energie & Wasser',
        'Sportausrüstung & Trikots',
        'Bälle & Trainingsmaterial',
        'Spielbetrieb & Schiedsrichter',
        'Verbandsbeiträge & Gebühren',
        'Versicherungen',
        'Veranstaltungen & Feste',
        'Fahrtkosten',
        'Jugendarbeit',
        'Übungsleiter & Ehrenamt',
        'Büro & Verwaltung',
        'IT & Software',
        'Bankgebühren',
        'Werbung & Sponsoring',
        'Ehrungen & Geschenke',
        'Sonstiges',
    ];

    private CategoryRepository $kategorien;

    private RoleRepository $rollen;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->kategorien = new CategoryRepository($this->pdo());
        $this->rollen = new RoleRepository($this->pdo());
        $this->userId = new UserRepository($this->pdo())->insert('enc', random_bytes(32), 'enc', 'hash', mfaRequired: false);
        $this->alsRolle(SystemRole::Admin);

        new Session()->start();
        $jetzt = time();
        $_SESSION = ['user_id' => $this->userId, 'login_at' => $jetzt, 'last_seen_at' => $jetzt, 'session_epoch' => 0];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testTheMigrationSeedsTheStartingSetOfTheSpec(): void
    {
        self::assertSame(self::EINNAHMEN, self::namen($this->kategorien->byDirection(CategoryDirection::Einnahme)));
        self::assertSame(self::AUSGABEN, self::namen($this->kategorien->byDirection(CategoryDirection::Ausgabe)));
        self::assertSame([], $this->kategorien->byDirection(CategoryDirection::Beide));

        foreach ($this->kategorien->all() as $kategorie) {
            self::assertTrue($kategorie->active, $kategorie->name);
            self::assertNotSame('', $kategorie->aiHint, $kategorie->name . ' has an AI hint');
            self::assertNotNull($kategorie->color, $kategorie->name . ' has a palette colour');
            self::assertNull($kategorie->parentId, $kategorie->name . ' is top-level');
        }

        $platzpflege = $this->eigene('Platzpflege & Grünanlagen');
        self::assertStringContainsString('Rasendünger, Mäharbeiten, Sand, Linierfarbe', $platzpflege->aiHint, 'the example of the spec');
    }

    /**
     * Acceptance criterion "Kategorie-ID bleibt Klartext (SQL kann
     * filtern)": no encrypted column and no row key in `category`, and the
     * id filters in plain SQL.
     */
    public function testTheCategoryTableIsPlaintext(): void
    {
        $stmt = $this->pdo()->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute(['category']);
        $spalten = array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

        self::assertContains('id', $spalten);
        self::assertSame([], array_values(array_filter($spalten, static fn(string $s): bool => str_ends_with($s, '_enc') || $s === 'dek_sealed')));

        $id = $this->eigene('Fahrtkosten')->id;
        $stmt = $this->pdo()->prepare('SELECT name FROM category WHERE id = ?');
        $stmt->execute([$id]);
        self::assertSame('Fahrtkosten', $stmt->fetchColumn());
    }

    public function testTheListShowsBothDirections(): void
    {
        $antwort = $this->dispatch(new Request(HttpMethod::Get, '/admin/kategorien'));

        self::assertSame(200, $antwort->status);
        self::assertStringContainsString('Neue Kategorie', $antwort->body);
        self::assertStringContainsString('<h3>Einnahmen</h3>', $antwort->body);
        self::assertStringContainsString('<h3>Ausgaben</h3>', $antwort->body);
        self::assertStringContainsString('Mitgliedsbeiträge', $antwort->body);
        self::assertStringContainsString('Platzpflege &amp; Grünanlagen', $antwort->body);
        self::assertStringContainsString('farbpunkt-gruen', $antwort->body);
    }

    public function testCreateChangeReorderAndDeleteACategory(): void
    {
        $a = $this->post('/admin/kategorien', ['name' => 'Trainingslager', 'richtung' => 'ausgabe', 'farbe' => 'blau', 'ki_hinweis' => 'Unterkunft, Verpflegung im Trainingslager']);
        self::assertSame(302, $a->status);
        self::assertSame('/admin/kategorien', $a->headers['Location'] ?? null);

        $neu = $this->eigene('Trainingslager');
        self::assertSame(CategoryDirection::Ausgabe, $neu->direction);
        self::assertSame(CategoryColor::Blau, $neu->color);
        self::assertSame('Unterkunft, Verpflegung im Trainingslager', $neu->aiHint);
        self::assertSame('Trainingslager', self::namen($this->kategorien->byDirection(CategoryDirection::Ausgabe))[count(self::AUSGABEN)], 'new ones sort last');

        $seite = $this->dispatch(new Request(HttpMethod::Get, '/admin/kategorien/' . $neu->id));
        self::assertSame(200, $seite->status);
        self::assertStringContainsString('value="Trainingslager"', $seite->body);
        self::assertStringContainsString('<option value="blau" selected>', $seite->body);
        self::assertStringContainsString('Kategorie löschen', $seite->body);

        // Reorder within the direction: one place up, ahead of "Sonstiges".
        $this->post('/admin/kategorien/' . $neu->id . '/nach-oben', []);
        $ausgaben = self::namen($this->kategorien->byDirection(CategoryDirection::Ausgabe));
        self::assertSame(['Trainingslager', 'Sonstiges'], array_slice($ausgaben, -2));
        self::assertSame(self::EINNAHMEN, self::namen($this->kategorien->byDirection(CategoryDirection::Einnahme)), 'the other direction is untouched');

        // A no-op at the top of its list.
        $erste = $this->eigene('Mitgliedsbeiträge');
        $this->post('/admin/kategorien/' . $erste->id . '/nach-oben', []);
        self::assertSame(self::EINNAHMEN, self::namen($this->kategorien->byDirection(CategoryDirection::Einnahme)));

        // Rename, change direction and colour, deactivate.
        $this->post('/admin/kategorien/' . $neu->id, ['name' => 'Trainingslager Jugend', 'richtung' => 'beide', 'farbe' => '', 'ki_hinweis' => '  Zeltlager  ', 'active' => '']);
        $geaendert = $this->kategorien->find($neu->id);
        self::assertNotNull($geaendert);
        self::assertSame('Trainingslager Jugend', $geaendert->name);
        self::assertSame(CategoryDirection::Beide, $geaendert->direction);
        self::assertNull($geaendert->color);
        self::assertSame('Zeltlager', $geaendert->aiHint);
        self::assertFalse($geaendert->active);
        self::assertArrayNotHasKey($neu->id, $this->kategorien->active(CategoryDirection::Ausgabe), 'inactive is not offered');

        $this->post('/admin/kategorien/' . $neu->id . '/loeschen', []);
        self::assertNull($this->kategorien->find($neu->id));

        // Each change is audited, with the category as its object (M3-8).
        $zeilen = new AuditLogRepository($this->pdo())->page(new AuditFilter(entity: 'category', entityId: $neu->id), null, 10);
        self::assertSame(
            [AuditAction::KategorieGeloescht->value, AuditAction::KategorieGeaendert->value, AuditAction::KategorieAngelegt->value],
            array_map(static fn(AuditEntry $e): string => $e->action, $zeilen),
        );
        self::assertSame($this->userId, $zeilen[0]->userId);
    }

    /** `beide` is offered for either direction, the other direction is not. */
    public function testActiveOffersTheDirectionPlusBoth(): void
    {
        $this->post('/admin/kategorien', ['name' => 'Durchlaufende Posten', 'richtung' => 'beide', 'farbe' => '', 'ki_hinweis' => '']);
        $beide = $this->eigene('Durchlaufende Posten')->id;

        $ausgabe = $this->kategorien->active(CategoryDirection::Ausgabe);
        $einnahme = $this->kategorien->active(CategoryDirection::Einnahme);

        self::assertContains('Fahrtkosten', $ausgabe);
        self::assertNotContains('Spenden', $ausgabe);
        self::assertContains('Spenden', $einnahme);
        self::assertArrayHasKey($beide, $ausgabe);
        self::assertArrayHasKey($beide, $einnahme);
    }

    public function testChangingTheDirectionMovesItToTheEndOfTheNewGroup(): void
    {
        $spenden = $this->eigene('Spenden');

        $this->post('/admin/kategorien/' . $spenden->id, ['name' => 'Spenden', 'richtung' => 'ausgabe', 'farbe' => 'gruen', 'ki_hinweis' => $spenden->aiHint, 'active' => '1']);

        $ausgaben = self::namen($this->kategorien->byDirection(CategoryDirection::Ausgabe));
        self::assertSame('Spenden', $ausgaben[count($ausgaben) - 1]);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function ungueltigeEingaben(): iterable
    {
        yield 'empty name' => [['name' => '   '], 'Namen angeben'];
        yield 'duplicate name' => [['name' => 'Fahrtkosten'], 'gibt es schon'];
        yield 'name too long' => [['name' => str_repeat('x', CategoryService::NAME_MAX + 1)], 'höchstens 100 Zeichen'];
        yield 'unknown direction' => [['richtung' => 'sphaere'], 'Richtung wählen'];
        yield 'unknown colour' => [['farbe' => '#ff0000'], 'Farbe gibt es nicht'];
        yield 'hint too long' => [['ki_hinweis' => str_repeat('x', CategoryService::AI_HINT_MAX + 1)], 'höchstens 500 Zeichen'];
    }

    /**
     * @param array<string, string> $abweichung
     */
    #[DataProvider('ungueltigeEingaben')]
    public function testAnInvalidInputKeepsTheForm(array $abweichung, string $meldung): void
    {
        $vorher = count($this->kategorien->all());

        $antwort = $this->post('/admin/kategorien', [...['name' => 'Neu', 'richtung' => 'ausgabe', 'farbe' => 'rot', 'ki_hinweis' => ''], ...$abweichung]);

        self::assertSame(422, $antwort->status);
        self::assertStringContainsString($meldung, $antwort->body);
        self::assertCount($vorher, $this->kategorien->all(), 'nothing created');
    }

    public function testAnInvalidChangeLeavesTheRowAlone(): void
    {
        $fahrt = $this->eigene('Fahrtkosten');

        $antwort = $this->post('/admin/kategorien/' . $fahrt->id, ['name' => 'Spenden', 'richtung' => 'ausgabe', 'farbe' => '', 'ki_hinweis' => '', 'active' => '']);

        self::assertSame(422, $antwort->status);
        self::assertEquals($fahrt, $this->kategorien->find($fahrt->id));
    }

    public function testWritesNeedTheCsrfToken(): void
    {
        $vorher = count($this->kategorien->all());
        $fahrt = $this->eigene('Fahrtkosten');

        $antwort = $this->dispatch(new Request(HttpMethod::Post, '/admin/kategorien', post: ['name' => 'Ohne Token', 'richtung' => 'ausgabe']));
        $this->dispatch(new Request(HttpMethod::Post, '/admin/kategorien/' . $fahrt->id . '/loeschen', post: []));

        self::assertSame(302, $antwort->status);
        self::assertCount($vorher, $this->kategorien->all());
        self::assertNotNull($this->kategorien->find($fahrt->id));
    }

    /**
     * "Deaktivieren statt Löschen bei Verwendung": a category something
     * points at (today: a sub-category) is refused, deactivating it works.
     */
    public function testACategoryInUseIsDeactivatedInsteadOfDeleted(): void
    {
        $eltern = $this->eigene('Jugendarbeit');
        $this->unterkategorie('Zeltlager', $eltern->id);

        $antwort = $this->post('/admin/kategorien/' . $eltern->id . '/loeschen', []);

        self::assertSame(302, $antwort->status);
        self::assertSame('/admin/kategorien/' . $eltern->id, $antwort->headers['Location'] ?? null);
        self::assertNotNull($this->kategorien->find($eltern->id), 'still there');

        $seite = $this->dispatch(new Request(HttpMethod::Get, '/admin/kategorien/' . $eltern->id));
        self::assertStringContainsString('nur noch deaktivieren', $seite->body);
        self::assertStringNotContainsString('>Kategorie löschen</button>', $seite->body);

        $this->post('/admin/kategorien/' . $eltern->id, ['name' => 'Jugendarbeit', 'richtung' => 'ausgabe', 'farbe' => 'orange', 'ki_hinweis' => $eltern->aiHint, 'active' => '']);
        self::assertFalse($this->kategorien->find($eltern->id)?->active);
        self::assertArrayNotHasKey($eltern->id, $this->kategorien->active(CategoryDirection::Ausgabe));
    }

    /**
     * The rule enforced in PHP (App\Service\MasterData\CategoryService) is
     * backed by the schema: a raw DELETE, bypassing the service, still
     * fails on the RESTRICT foreign key.
     */
    public function testTheForeignKeyAloneRefusesTheDelete(): void
    {
        $eltern = $this->eigene('Jugendarbeit');
        $this->unterkategorie('Zeltlager', $eltern->id);

        $this->expectException(\PDOException::class);
        $this->pdo()->prepare('DELETE FROM category WHERE id = ?')->execute([$eltern->id]);
    }

    public function testAnUnknownCategoryIs404(): void
    {
        self::assertSame(404, $this->dispatch(new Request(HttpMethod::Get, '/admin/kategorien/999999'))->status);
    }

    /** Vorstand holds admin.settings for nothing (only Admin does). */
    public function testWithoutAdminSettingsThePagesAreForbidden(): void
    {
        $this->alsRolle(SystemRole::Vorstand);
        $vorher = count($this->kategorien->all());

        self::assertSame(403, $this->dispatch(new Request(HttpMethod::Get, '/admin/kategorien'))->status);
        self::assertSame(403, $this->post('/admin/kategorien', ['name' => 'Heimlich', 'richtung' => 'ausgabe'])->status);
        self::assertCount($vorher, $this->kategorien->all());
    }

    // ----------------------------------------------------------- scaffolding

    /**
     * Sub-categories have no page yet (flat list since M6-1) - the row is
     * written directly.
     */
    private function unterkategorie(string $name, int $elternId): void
    {
        $this->pdo()->prepare("INSERT INTO category (name, direction, parent_id, sort, active, ai_hint) VALUES (?, 'ausgabe', ?, 999, 1, '')")
            ->execute([$name, $elternId]);
    }

    private function alsRolle(SystemRole $rolle): void
    {
        $id = $this->rollen->findSystem($rolle)?->id;
        assert($id !== null);
        $this->rollen->assignToUser($this->userId, [$id]);
    }

    private function eigene(string $name): Category
    {
        foreach ($this->kategorien->all() as $kategorie) {
            if ($kategorie->name === $name) {
                return $kategorie;
            }
        }

        self::fail('Category not found: ' . $name);
    }

    /**
     * @param list<Category> $kategorien
     *
     * @return list<string>
     */
    private static function namen(array $kategorien): array
    {
        return array_map(static fn(Category $c): string => $c->name, $kategorien);
    }

    /**
     * @param array<string, mixed> $felder
     */
    private function post(string $pfad, array $felder): Response
    {
        return $this->dispatch(new Request(HttpMethod::Post, $pfad, post: [...$felder, '_csrf' => new Session()->csrfToken()]));
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
        $kategorien = static fn(): CategoryController => new CategoryController(
            $view,
            new Session(),
            new CategoryRepository($pdo),
            new CategoryService(new CategoryRepository($pdo)),
            new AuditLog(new AuditLogRepository($pdo), new VaultRepository($pdo), new ServerCrypto(random_bytes(32))),
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
            $kategorien,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
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
