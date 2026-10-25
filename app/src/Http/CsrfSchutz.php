<?php

declare(strict_types=1);

namespace App\Http;

use App\View\Area;
use App\View\View;

/**
 * The CSRF check of every writing route, in one place (security review
 * N-15, issue #246; CLAUDE.md section 4: "CSRF-Token auf allen
 * Schreibrouten").
 *
 * app/src/routes.php wraps every POST handler in pruefe() - except the
 * routes listed in AUSNAHMEN, which carry no session at all and are
 * protected another way. The controllers keep their own checkCsrf() calls
 * (and the friendlier answers that go with them); this is the floor that a
 * new route, or a controller that forgets the check, cannot fall through.
 * tests/Http/RoutePermissionMatrixTest.php runs every POST route without a
 * token against it.
 *
 * Order: behind App\Http\LoginGuard for protected routes - an anonymous
 * request still gets the login (or 401), a logged-in one without a valid
 * token 403.
 */
final readonly class CsrfSchutz
{
    /**
     * Route pattern prefixes without a session CSRF token, and why. A route
     * matches an entry when its pattern equals it or continues it with "/".
     */
    public const array AUSNAHMEN = [
        // The public submission: deliberately no session (CLAUDE.md
        // section 3); a signed form token plus proof of work instead
        // (docs/spec/01-sicherheit.md section 5).
        '/einreichen' => 'Formular-Token (keine Session)',
        // The worker API: no browser, no cookie - every request is signed
        // with the worker's key (HMAC, docs/spec/07-worker.md); the pairing
        // code is the credential of /api/worker/pair.
        '/api/worker' => 'HMAC-Signatur (keine Session)',
    ];

    public function __construct(
        private Session $session,
        private View $view,
    ) {
    }

    public static function ausgenommen(string $muster): bool
    {
        foreach (array_keys(self::AUSNAHMEN) as $praefix) {
            if ($muster === $praefix || str_starts_with($muster, $praefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param \Closure(Request, array<string, string>): ResponseInterface $handler
     * @return \Closure(Request, array<string, string>): ResponseInterface
     */
    public function pruefe(bool $api, \Closure $handler): \Closure
    {
        return function (Request $request, array $params = []) use ($api, $handler): ResponseInterface {
            $this->session->start();

            return $this->session->checkCsrf($request)
                ? $handler($request, $params)
                : $this->abweisen($request, $api);
        };
    }

    private function abweisen(Request $request, bool $api): ResponseInterface
    {
        if ($api) {
            return Response::json(['fehler' => 'Das Sicherheits-Token fehlt oder ist abgelaufen. Bitte laden Sie die Seite neu.'], 403);
        }

        $pfad = $request->path;
        [$bereich, $startseite] = match (true) {
            $pfad === '/admin' || str_starts_with($pfad, '/admin/') => [Area::Admin, '/admin'],
            $pfad === '/app' || str_starts_with($pfad, '/app/') => [Area::App, '/app'],
            default => [Area::Oeffentlich, '/anmelden'],
        };

        return Response::html($this->view->render('error', [
            'title' => 'Formular abgelaufen',
            'message' => 'Das Formular war nicht mehr gültig – etwa weil Sie sich zwischendurch neu angemeldet haben. '
                . 'Bitte laden Sie die Seite neu und versuchen Sie es noch einmal.',
            'startseite' => $startseite,
        ], $bereich), 403);
    }
}
