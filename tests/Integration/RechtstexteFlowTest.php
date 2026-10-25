<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Admin\RechtstexteController;
use App\Config\Paths;
use App\Domain\AuditAction;
use App\Domain\SystemRole;
use App\Http\HttpMethod;
use App\Http\Kernel;
use App\Http\LoginGuard;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Http\Session;
use App\Http\StaticFileHandler;
use App\PublicPages\RechtstextController;
use App\Repository\AiProviderRepository;
use App\Repository\AuditLogRepository;
use App\Repository\RoleRepository;
use App\Repository\SettingRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\Service\Audit\AuditFilter;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\ServerCrypto;
use App\Service\Migration\Migrator;
use App\Service\Rechtstexte\Rechtstext;
use App\Service\Rechtstexte\Rechtstexte;
use App\Tests\Support\DatabaseTestCase;
use App\View\View;

/**
 * Impressum, Datenschutzerklärung and their admin page end to end (issue
 * #80/M13-3, E-46): the real route table, the real guard, the real
 * controllers, the real schema.
 */
final class RechtstexteFlowTest extends DatabaseTestCase
{
    private RoleRepository $rollen;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        // An earlier test in this process may have left one open; the public
        // pages are checked for not starting one.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->rollen = new RoleRepository($this->pdo());
        $this->userId = new UserRepository($this->pdo())->insert('enc', random_bytes(32), 'enc', 'hash', mfaRequired: false);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public function testThePublicPagesShowTheTemplatesWithoutASession(): void
    {
        foreach (['/impressum' => 'Impressum', '/datenschutz' => 'Datenschutzerklärung'] as $pfad => $titel) {
            $seite = $this->dispatch(new Request(HttpMethod::Get, $pfad));

            self::assertSame(200, $seite->status, $pfad);
            self::assertStringContainsString('<h2>' . $titel . '</h2>', $seite->body);
            self::assertStringContainsString('noch eine Vorlage', $seite->body, $pfad);
            self::assertArrayNotHasKey('Set-Cookie', $seite->headers, $pfad);
            self::assertNotSame(PHP_SESSION_ACTIVE, session_status(), $pfad);
            // Public area: no CSRF token travels with htmx.
            self::assertStringNotContainsString('X-CSRF-Token', $seite->body);
        }

        $datenschutz = $this->dispatch(new Request(HttpMethod::Get, '/datenschutz'))->body;
        self::assertStringContainsString('Auftragsverarbeitung', $datenschutz);
        self::assertStringContainsString('Aufbewahrung und Löschung', $datenschutz);
    }

    public function testEveryPageLinksToImpressumAndDatenschutz(): void
    {
        $start = $this->dispatch(new Request(HttpMethod::Get, '/'))->body;

        self::assertStringContainsString('href="/impressum"', $start);
        self::assertStringContainsString('href="/datenschutz"', $start);
    }

    public function testTheShippedTemplatesAreValidAndFullOfPlaceholders(): void
    {
        $rechtstexte = $this->rechtstexte();
        foreach (Rechtstext::cases() as $art) {
            $vorlage = $rechtstexte->lesen($art);

            self::assertTrue($vorlage->istVorlage, $art->value);
            self::assertNotSame('', trim($vorlage->text), $art->value);
            self::assertNotSame([], $vorlage->platzhalter(), $art->value);
            self::assertLessThan(Rechtstext::MAX_BYTES, strlen($vorlage->text));
        }
    }

    public function testAdminSavesAndThePublicPageShowsTheEscapedText(): void
    {
        $this->anmeldenAls(SystemRole::Admin);

        $seite = $this->dispatch(new Request(HttpMethod::Get, '/admin/rechtstexte'));
        self::assertSame(200, $seite->status);
        self::assertStringContainsString('keine Rechtsberatung', $seite->body);
        self::assertStringContainsString('[Name des Vereins]', $seite->body);

        $antwort = $this->post('/admin/rechtstexte', [
            'impressum' => "## Anschrift\n\nTestverein e. V.\n<script>alert(1)</script>\n[x](javascript:alert)",
            'datenschutz' => $this->rechtstexte()->vorlage(Rechtstext::Datenschutz),
            'einreichung_hinweis' => '',
        ]);
        self::assertSame(302, $antwort->status);
        self::assertSame('/admin/rechtstexte', $antwort->headers['Location'] ?? null);

        $_SESSION = [];
        $impressum = $this->dispatch(new Request(HttpMethod::Get, '/impressum'))->body;
        self::assertStringContainsString('<h3>Anschrift</h3>', $impressum);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $impressum);
        self::assertStringNotContainsString('<script>alert', $impressum);
        self::assertStringNotContainsString('href="javascript', $impressum);
        self::assertStringNotContainsString('noch eine Vorlage', $impressum);

        // The untouched template was not frozen as the club's own text.
        self::assertTrue($this->rechtstexte()->lesen(Rechtstext::Datenschutz)->istVorlage);
        self::assertSame('', new SettingRepository($this->pdo())->get(Rechtstext::Datenschutz->settingName()));

        // One entry; its details (names of the changed texts) are sealed
        // to the vault like every audit detail.
        $eintraege = new AuditLogRepository($this->pdo())->page(new AuditFilter(aktion: AuditAction::EinstellungRechtstexte), null, 10);
        self::assertCount(1, $eintraege);

        // Saving the same again changes nothing and writes no entry.
        $this->anmeldenAls(SystemRole::Admin);
        $this->post('/admin/rechtstexte', [
            'impressum' => "## Anschrift\n\nTestverein e. V.\n<script>alert(1)</script>\n[x](javascript:alert)",
            'datenschutz' => '',
            'einreichung_hinweis' => '',
        ]);
        self::assertCount(1, new AuditLogRepository($this->pdo())->page(new AuditFilter(aktion: AuditAction::EinstellungRechtstexte), null, 10));
    }

    public function testAnEmptyFieldRestoresTheTemplate(): void
    {
        $this->rechtstexte()->speichern(Rechtstext::Impressum, 'Eigener Text');
        $this->anmeldenAls(SystemRole::Admin);

        $this->post('/admin/rechtstexte', ['impressum' => "  \n ", 'datenschutz' => '', 'einreichung_hinweis' => '']);

        self::assertTrue($this->rechtstexte()->lesen(Rechtstext::Impressum)->istVorlage);
    }

    public function testATooLongTextIsRejectedAndNothingIsSaved(): void
    {
        $this->anmeldenAls(SystemRole::Admin);

        $antwort = $this->post('/admin/rechtstexte', [
            'impressum' => 'Neu',
            'datenschutz' => str_repeat('a', Rechtstext::MAX_BYTES + 1),
            'einreichung_hinweis' => '',
        ]);

        self::assertSame(422, $antwort->status);
        self::assertStringContainsString('zu lang', $antwort->body);
        self::assertTrue($this->rechtstexte()->lesen(Rechtstext::Impressum)->istVorlage);
    }

    public function testWithoutCsrfNothingIsSaved(): void
    {
        $this->anmeldenAls(SystemRole::Admin);

        $antwort = $this->dispatch(new Request(HttpMethod::Post, '/admin/rechtstexte', post: ['impressum' => 'Fremd']));

        self::assertSame(302, $antwort->status);
        self::assertTrue($this->rechtstexte()->lesen(Rechtstext::Impressum)->istVorlage);
    }

    /** Vorstand holds admin.settings for nothing (only Admin does). */
    public function testWithoutAdminSettingsThePageIsForbidden(): void
    {
        $this->anmeldenAls(SystemRole::Vorstand);

        self::assertSame(403, $this->dispatch(new Request(HttpMethod::Get, '/admin/rechtstexte'))->status);
        self::assertSame(403, $this->post('/admin/rechtstexte', ['impressum' => 'Fremd'])->status);
        self::assertTrue($this->rechtstexte()->lesen(Rechtstext::Impressum)->istVorlage);
    }

    public function testTheAdminPageNamesTheActiveAiProviders(): void
    {
        $this->anmeldenAls(SystemRole::Admin);

        // Migration 024 ships the active profile "OpenAI".
        $seite = $this->dispatch(new Request(HttpMethod::Get, '/admin/rechtstexte'));

        self::assertStringContainsString('Aktive KI-Anbieter', $seite->body);
        self::assertStringContainsString('OpenAI', $seite->body);
        self::assertStringContainsString('api.openai.com', $seite->body);
    }

    // ----------------------------------------------------------- scaffolding

    private function rechtstexte(): Rechtstexte
    {
        return new Rechtstexte(new SettingRepository($this->pdo()), new Paths(dirname(__DIR__, 2))->dataDir());
    }

    private function anmeldenAls(SystemRole $rolle): void
    {
        $id = $this->rollen->findSystem($rolle)?->id;
        assert($id !== null);
        $this->rollen->assignToUser($this->userId, [$id]);

        new Session()->start();
        $jetzt = time();
        $_SESSION = ['user_id' => $this->userId, 'login_at' => $jetzt, 'last_seen_at' => $jetzt, 'session_epoch' => 0];
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
        $serverCrypto = new ServerCrypto(random_bytes(32));
        $rechtstexte = $this->rechtstexte();

        $guard = static fn(): LoginGuard => new LoginGuard(
            new Session(),
            $view,
            static fn(): SessionTimeouts => new SessionTimeouts(),
            static function (int $id) use ($pdo): ?SessionUser {
                $user = new UserRepository($pdo)->findById($id);

                return $user === null ? null : new SessionUser($user, 'Test', new UserAccessRepository($pdo)->berechtigungen($id));
            },
        );
        $unerreichbar = static fn(): never => throw new \LogicException('Diese Route gehört nicht zu diesem Test.');

        $router = new Router();
        (require dirname(__DIR__, 2) . '/app/src/routes.php')(
            $router,
            $view,
            $unerreichbar,
            $guard,
            ...array_fill(0, 36, $unerreichbar),
            rechtstexte: static fn(): RechtstextController => new RechtstextController($view, $rechtstexte),
            rechtstexteAdmin: static fn(): RechtstexteController => new RechtstexteController(
                $view,
                new Session(),
                $rechtstexte,
                new AuditLog(new AuditLogRepository($pdo), new VaultRepository($pdo), $serverCrypto),
                new AiProviderRepository($pdo, $serverCrypto),
            ),
        );

        return new Kernel(
            router: $router,
            staticFiles: new StaticFileHandler($paths->publicDir(), longCache: false),
            view: $view,
            debug: true,
        );
    }
}
