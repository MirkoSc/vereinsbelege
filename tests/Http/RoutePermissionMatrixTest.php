<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Berechtigungen;
use App\Domain\Permission;
use App\Domain\Role;
use App\Domain\SystemRole;
use App\Domain\User;
use App\Domain\UserStatus;
use App\Http\HttpMethod;
use App\Http\LoginGuard;
use App\Http\MatchType;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Route;
use App\Http\Router;
use App\Http\Session;
use App\Http\ZugriffArt;
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\View\View;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rights matrix over every route (docs/spec/01-sicherheit.md section 4,
 * "Pflicht-Tests", issue #19/M3-6).
 *
 * Built from the real app/src/routes.php with the real App\Http\LoginGuard,
 * so what is checked is what the application serves: every route, as
 * anonymous visitor and as each of the six shipped roles. The controllers
 * behind the routes are stand-ins that only say "reached" - whether the
 * guard let the request through is all this test is about.
 *
 * A route without a declaration cannot be registered at all (Router::add()
 * requires an App\Http\Zugriff); the structural tests below pin what the
 * declarations must look like, so a new route cannot quietly be public or
 * sit under /admin with a non-admin right.
 */
final class RoutePermissionMatrixTest extends TestCase
{
    public const string ERREICHT = 'ERREICHT';

    /**
     * Routes that may be reached without a login. Anything else that turns
     * up public fails testOnlyTheAllowlistIsPublic() - a deliberate new
     * public route is added here in the same change.
     */
    private const array OEFFENTLICH = [
        '/',
        '/anmelden',
        '/abmelden',
        '/anmelden/bestaetigen',
        '/anmelden/code-senden',
        '/anmelden/backup-code',
        '/anmelden/passwort-vergessen',
        '/anmelden/passwort-neu',
        '/anmelden/einladung',
        '/cron',
    ];

    private ?SessionUser $angemeldet = null;

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function routen(): iterable
    {
        foreach (self::routerMit(static fn(): ?SessionUser => null)->routes() as $route) {
            yield $route->method->value . ' ' . $route->pattern => [$route->method->value, $route->pattern];
        }
    }

    #[DataProvider('routen')]
    public function testEveryRoleGetsExactlyWhatTheDeclarationAllows(string $methode, string $muster): void
    {
        $router = $this->router();
        $route = self::finde($router, $methode, $muster);
        $pfad = self::beispielPfad($muster);

        // Anonymous.
        $this->angemeldet = null;
        $_SESSION = [];
        $antwort = $this->aufrufen($router, $route->method, $pfad);
        if ($route->zugriff->brauchtAnmeldung()) {
            self::assertTrue(
                $antwort->status === 401 || ($antwort->status === 302 && str_starts_with($antwort->headers['Location'] ?? '', '/anmelden')),
                $muster . ' must turn an anonymous visitor away',
            );
        } else {
            self::assertTrue($this->erreicht($antwort), $muster . ' is public and must be reachable');
        }

        foreach (SystemRole::cases() as $rolle) {
            $berechtigungen = self::berechtigungen($rolle);
            $this->anmelden($berechtigungen);

            $antwort = $this->aufrufen($router, $route->method, $pfad);
            $erwartet = self::erwartet($route, $pfad, $berechtigungen);

            self::assertSame(
                $erwartet,
                $this->erreicht($antwort),
                sprintf('%s %s as %s (declared: %s, answer %d)', $methode, $muster, $rolle->value, $route->zugriff->beschreibung(), $antwort->status),
            );
            if (!$erwartet && $route->zugriff->brauchtAnmeldung()) {
                self::assertSame(403, $antwort->status, $muster . ' must answer 403 to ' . $rolle->value);
            }
        }
    }

    public function testEveryRouteCarriesADeclaration(): void
    {
        $routen = $this->router()->routes();

        self::assertNotEmpty($routen);
        foreach ($routen as $route) {
            self::assertNotSame('', $route->zugriff->beschreibung(), $route->pattern);
        }
    }

    public function testOnlyTheAllowlistIsPublic(): void
    {
        foreach ($this->router()->routes() as $route) {
            if (!$route->zugriff->brauchtAnmeldung()) {
                self::assertContains($route->pattern, self::OEFFENTLICH, $route->pattern . ' is public but not on the allowlist');
            }
        }
    }

    /**
     * docs/spec/01-sicherheit.md section 4: "Adminseite /admin/* nur mit
     * mindestens einem admin.*-Recht erreichbar" - declared that way, not
     * only enforced by the guard's floor.
     */
    public function testEveryAdminRouteNeedsAnAdminRight(): void
    {
        foreach ($this->router()->routes() as $route) {
            if (!str_starts_with($route->pattern, '/admin')) {
                continue;
            }

            $zugriff = $route->zugriff;
            self::assertTrue(
                $zugriff->art === ZugriffArt::AdminBereich
                    || ($zugriff->art === ZugriffArt::Recht && $zugriff->recht?->istAdmin() === true),
                $route->pattern . ' is declared ' . $zugriff->beschreibung(),
            );
        }
    }

    public function testTheUploadAnswersInJson(): void
    {
        foreach ($this->router()->routes() as $route) {
            if (str_starts_with($route->pattern, '/api/')) {
                self::assertTrue($route->zugriff->api, $route->pattern);
                self::assertSame(Permission::DocumentSubmitInternal, $route->zugriff->recht, $route->pattern);
            }
        }
    }

    /**
     * Spot checks against the spec's table itself, so the matrix above
     * cannot pass merely because declaration and expectation share a bug.
     */
    public function testSpotChecksAgainstTheSpecTable(): void
    {
        $router = $this->router();
        $faelle = [
            [SystemRole::Admin, HttpMethod::Get, '/admin/update', true],
            [SystemRole::Admin, HttpMethod::Get, '/admin/rollen', true],
            [SystemRole::Admin, HttpMethod::Get, '/admin/benutzer', true],
            [SystemRole::Admin, HttpMethod::Post, '/admin/tresor/7/freigeben', true],
            [SystemRole::Vorstand, HttpMethod::Get, '/admin/benutzer', false],
            [SystemRole::Finanzen, HttpMethod::Post, '/admin/benutzer/7/sperren', false],
            [SystemRole::Kassenpruefer, HttpMethod::Get, '/admin/tresor', false],
            [SystemRole::Vorstand, HttpMethod::Get, '/admin/mail', false],
            [SystemRole::Vorstand, HttpMethod::Post, '/api/upload', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/api/upload', true],
            [SystemRole::Finanzen, HttpMethod::Get, '/admin/designsystem', false],
            [SystemRole::Kassenpruefer, HttpMethod::Post, '/api/upload', false],
            [SystemRole::Steuerberater, HttpMethod::Get, '/admin', false],
            [SystemRole::Vereinsverantwortlicher, HttpMethod::Post, '/api/upload', true],
            [SystemRole::Vereinsverantwortlicher, HttpMethod::Get, '/app', true],
        ];

        foreach ($faelle as [$rolle, $methode, $pfad, $darf]) {
            $this->anmelden(self::berechtigungen($rolle));
            self::assertSame($darf, $this->erreicht($this->aufrufen($router, $methode, $pfad)), $rolle->value . ' ' . $pfad);
        }
    }

    public function testAdminIsSentToTheFirstAdminPageItMayOpen(): void
    {
        $this->anmelden(self::berechtigungen(SystemRole::Admin));

        $antwort = $this->aufrufen($this->router(), HttpMethod::Get, '/admin');

        self::assertSame(302, $antwort->status);
        self::assertSame('/admin/update', $antwort->headers['Location'] ?? null);
    }

    public function testAnAccountWithoutAnyRoleMayOnlyUseTheLoginArea(): void
    {
        $router = $this->router();
        $this->anmelden(Berechtigungen::keine());

        self::assertTrue($this->erreicht($this->aufrufen($router, HttpMethod::Get, '/app')));
        self::assertTrue($this->erreicht($this->aufrufen($router, HttpMethod::Get, '/app/sicherheit')));
        self::assertSame(403, $this->aufrufen($router, HttpMethod::Get, '/admin/designsystem')->status);
        self::assertSame(403, $this->aufrufen($router, HttpMethod::Post, '/api/upload')->status);
    }

    // ----------------------------------------------------------- scaffolding

    private static function erwartet(Route $route, string $pfad, Berechtigungen $berechtigungen): bool
    {
        $zugriff = $route->zugriff;
        if (($pfad === '/admin' || str_starts_with($pfad, '/admin/')) && !$berechtigungen->darfAdminBereich()) {
            return false;
        }

        return match ($zugriff->art) {
            ZugriffArt::Oeffentlich, ZugriffArt::Cron, ZugriffArt::Angemeldet => true,
            ZugriffArt::AdminBereich => $berechtigungen->darfAdminBereich(),
            ZugriffArt::Recht => $zugriff->recht !== null && $berechtigungen->darf($zugriff->recht),
        };
    }

    private static function berechtigungen(SystemRole $rolle): Berechtigungen
    {
        return new Berechtigungen([new Role(1, $rolle->bezeichnung(), $rolle, $rolle->istExtern(), $rolle->standardRechte())]);
    }

    private function anmelden(Berechtigungen $berechtigungen): void
    {
        // Started first: session_start() would replace what is set here.
        new Session()->start();
        $jetzt = new \DateTimeImmutable();
        $this->angemeldet = new SessionUser(
            new User(7, 'x', 'x', 'x', UserStatus::Aktiv, null, false, null, $jetzt, null),
            'Test',
            $berechtigungen,
        );
        $_SESSION = [
            'user_id' => 7,
            'login_at' => $jetzt->getTimestamp(),
            'last_seen_at' => $jetzt->getTimestamp(),
            'session_epoch' => 0,
        ];
    }

    private function aufrufen(Router $router, HttpMethod $methode, string $pfad): ResponseInterface
    {
        $treffer = $router->match($methode, $pfad);
        self::assertSame(MatchType::Matched, $treffer->type, $methode->value . ' ' . $pfad);
        assert($treffer->handler !== null);

        return ($treffer->handler)(new Request($methode, $pfad), $treffer->params);
    }

    /**
     * Reached = the handler ran: either a stand-in answered, or one of the
     * few inline handlers in routes.php (start pages, the /admin redirect)
     * produced its page.
     */
    private function erreicht(ResponseInterface $antwort): bool
    {
        assert($antwort instanceof Response);
        if ($antwort->body === self::ERREICHT) {
            return true;
        }
        if ($antwort->status === 302) {
            $ziel = $antwort->headers['Location'] ?? '';

            return !str_starts_with($ziel, '/anmelden') && $ziel !== '/app/sicherheit/einrichten';
        }

        return $antwort->status === 200;
    }

    private static function finde(Router $router, string $methode, string $muster): Route
    {
        foreach ($router->routes() as $route) {
            if ($route->method->value === $methode && $route->pattern === $muster) {
                return $route;
            }
        }

        self::fail('Route not found: ' . $methode . ' ' . $muster);
    }

    /** A concrete path for a pattern: each placeholder gets a value its regex accepts. */
    private static function beispielPfad(string $muster): string
    {
        return (string) preg_replace_callback(
            '/\{([a-z_][a-zA-Z0-9_]*)(?::([^{}]+))?\}/',
            static function (array $m): string {
                $regex = ($m[2] ?? '') !== '' ? $m[2] : '[^/]+';
                foreach (['1', str_repeat('a', 32), 'abc'] as $kandidat) {
                    if (preg_match('#^(?:' . $regex . ')$#', $kandidat) === 1) {
                        return $kandidat;
                    }
                }

                self::fail('No sample value for placeholder ' . $m[0]);
            },
            $muster,
        );
    }

    /**
     * The real route table; every controller is a stand-in that answers
     * "reached" to whatever method is called on it.
     */
    private function router(): Router
    {
        return self::routerMit(fn(): ?SessionUser => $this->angemeldet);
    }

    /**
     * @param \Closure(): ?SessionUser $benutzer who the guard sees as logged in
     */
    private static function routerMit(\Closure $benutzer): Router
    {
        $view = new View(dirname(__DIR__, 2) . '/app/views', '0.0.0-test');
        $stellvertreter = static fn(): object => new class {
            /** @param array<mixed> $argumente */
            public function __call(string $name, array $argumente): Response
            {
                return Response::html(RoutePermissionMatrixTest::ERREICHT);
            }
        };

        $guard = static fn(): LoginGuard => new LoginGuard(
            new Session(),
            $view,
            static fn(): SessionTimeouts => new SessionTimeouts(),
            static fn(int $id): ?SessionUser => $benutzer(),
        );

        $router = new Router();
        (require dirname(__DIR__, 2) . '/app/src/routes.php')(
            $router,
            $view,
            $stellvertreter,
            $guard,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
        );

        return $router;
    }

}
