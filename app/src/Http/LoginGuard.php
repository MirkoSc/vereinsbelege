<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Berechtigungen;
use App\Domain\Permission;
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\Service\Account\SessionVault;
use App\View\Area;
use App\View\View;

/**
 * The gate in front of everything that is not public (M3-3, issue #16):
 * /app, /admin and the chunk upload.
 *
 * It answers two questions - may this request happen at all, and may THIS
 * account do what the route does? - server side, for the route, not by
 * hiding a link (CLAUDE.md section 4). The second question is the route's
 * access declaration (App\Http\Zugriff, issue #19/M3-6): app/src/routes.php
 * builds each protected route's wrapper from that declaration through
 * pruefe(), so what a route declares is what is checked. On top of any
 * declaration, every path under /admin needs at least one `admin.*` right
 * (docs/spec/01-sicherheit.md section 4).
 *
 * A logged-in account without the right gets 403, not the login form - it
 * is logged in; logging in again would not change anything.
 *
 * Expiry is part of the gate, because a session that has run out is not
 * logged in anymore (docs/spec/01-sicherheit.md section 2): the session is
 * destroyed and the vault cookie deleted in the same answer, so nothing
 * survives that a later request could pick up.
 */
final readonly class LoginGuard
{
    /**
     * Sent by requests the page makes on its own, without the user doing
     * anything; they leave the idle timeout where it is.
     */
    public const string HINTERGRUND_HEADER = 'x-hintergrund';

    /**
     * @param \Closure(): SessionTimeouts $timeouts built lazily: it reads
     *        the settings table, and a request that is turned away at the
     *        door should not pay for a database connection first.
     * @param \Closure(int): ?SessionUser $benutzer the account behind the
     *        session id, looked up on every request. That is what makes
     *        locking an account, letting an external account expire or
     *        deleting a user take effect at once instead of at the next
     *        login - a session is not a second, longer-lived permission.
     * @param (\Closure(): int)|null $ausstehendeFreigaben how many accounts
     *        wait for a vault grant - asked only for an account with
     *        `admin.vault_grant`, for the banner "N Freigaben ausstehend"
     *        (docs/spec/01-sicherheit.md section 2, issue #20/M3-7).
     * @param (\Closure(Berechtigungen): ?int)|null $offeneJobs how many jobs
     *        wait for this account's session-worker, null when it has no
     *        right for any job type - the header count (issue #29/M4-7,
     *        docs/spec/06-betrieb.md section 4, App\Service\Job\JobRunner::offen()).
     * @param (\Closure(): bool)|null $rotationLaeuft whether a vault key
     *        rotation runs (issue #90, App\Repository\VaultRepository::
     *        rotationInProgress()) - asked once the account may otherwise
     *        proceed.
     */
    public function __construct(
        private Session $session,
        private View $view,
        private \Closure $timeouts,
        private \Closure $benutzer,
        private ?\Closure $ausstehendeFreigaben = null,
        private ?\Closure $offeneJobs = null,
        private ?\Closure $rotationLaeuft = null,
    ) {
    }

    /**
     * Pages that stay open while a vault key rotation runs (issue #90): the
     * rotation itself and the account's own security settings. Everything
     * else in /app and /admin waits - a session that still holds only the
     * old key must not write data sealed to it, and reading half-rotated
     * data is not worth the edge cases. The public submission keeps working;
     * it seals to the newest generation.
     */
    public const string ROTATION_SEITE = '/admin/tresor/rotation';

    /**
     * Wraps a handler in the check its route declares. The wrapper is what
     * app/src/routes.php registers, built from the same Zugriff value the
     * route carries.
     *
     * @param \Closure(Request, array<string, string>): ResponseInterface $handler
     * @return \Closure(Request, array<string, string>): ResponseInterface
     */
    public function pruefe(Zugriff $zugriff, \Closure $handler): \Closure
    {
        return fn(Request $request, array $params = []): ResponseInterface => $this->check($request, $zugriff)
            ?? $handler($request, $params);
    }

    /**
     * @return ResponseInterface|null null when the request may proceed
     */
    private function check(Request $request, Zugriff $zugriff): ?ResponseInterface
    {
        // The JSON routes (the upload runs from fetch()) answer in JSON: a
        // redirect to the login form would arrive there as an HTML page where
        // JSON was expected.
        $api = $zugriff->api;

        $this->session->start();

        $userId = $this->session->userId();
        if ($userId === null) {
            return $this->abweisen($request, $api, abgelaufen: false);
        }

        $timeouts = ($this->timeouts)();
        $benutzer = ($this->benutzer)($userId);

        // Four ways a live session stops being one: it ran out, the account
        // behind it is gone, the account is no longer allowed in, or its
        // password was reset or changed elsewhere since this session logged
        // in (`user.session_epoch`, issue #18/M3-5). All four end the
        // session here and now.
        if (
            $this->session->isExpired($timeouts->idleSeconds, $timeouts->absoluteSeconds)
            || $benutzer === null
            || !$benutzer->user->mayLogIn(new \DateTimeImmutable())
            || $this->session->epoch() !== $benutzer->user->sessionEpoch
        ) {
            new SessionVault()->clear();
            $this->session->destroy();

            return $this->abweisen($request, $api, abgelaufen: true);
        }

        // A background poll (public/js/jobs.js, every 30 s for as long as a
        // tab is open) is no sign of a person at the screen: counting it
        // would keep an abandoned session - and its unlocked vault - alive
        // until the absolute limit (security review, issue #78). A request
        // can only opt OUT of extending its session this way, never in.
        if ($request->header(self::HINTERGRUND_HEADER) !== '1') {
            $this->session->touch();
        }

        // The chrome of every page behind the login: who is signed in, and
        // the token their logout form needs. Not for the JSON routes - they
        // render nothing, so name, token and banners stay unset.
        if (!$api) {
            $this->view->setAnmeldung(
                $benutzer->anzeigename,
                $this->session->csrfToken(),
                $benutzer->berechtigungen,
                $this->ausstehendeFreigaben !== null && $benutzer->berechtigungen->darf(Permission::AdminVaultGrant)
                    ? ($this->ausstehendeFreigaben)()
                    : 0,
                $this->offeneJobs !== null ? ($this->offeneJobs)($benutzer->berechtigungen) : null,
            );
        } else {
            // A JSON controller can still need this account's rights
            // (App\Api\JobController, issue #29/M4-7: which job TYPES a
            // session may touch is not something the route's single
            // App\Http\Zugriff could declare) - cheap, Berechtigungen is
            // already built above for darf() either way.
            $this->view->setBerechtigungen($benutzer->berechtigungen);
        }

        // M3-4 (issue #17): `mfa_required` without a configured factor is
        // not a normal, usable state (docs/spec/01-sicherheit.md section 3,
        // "Default: Pflicht für alle") - every page in `/app` and `/admin`
        // is closed until setup finishes, the same way an expired session
        // closes them, except the account stays logged in and the vault
        // stays whatever it already was. Exempt: the setup pages themselves
        // (or nobody could ever reach them).
        // Since issue #246 (security review N-10) the JSON routes are closed
        // as well - before, an account that never set a factor up could
        // still upload and run job steps from fetch(). They answer 403 with
        // where to go instead of a redirect; the pollers (public/js/jobs.js,
        // rasterung.js) treat a 403 as "stop", so the setup page they also
        // run on is not disturbed, and the next page after setup starts them
        // again.
        if ($benutzer->user->mfaRequired && !$benutzer->user->mfaEingerichtet() && !self::istEinrichtung($request->path)) {
            return $api
                ? Response::json([
                    'fehler' => 'Bitte richten Sie zuerst die Zwei-Faktor-Anmeldung ein.',
                    'weiter' => self::EINRICHTUNG . '/einrichten',
                ], 403)
                : Response::redirect(self::EINRICHTUNG . '/einrichten');
        }

        if (!$this->darf($request, $zugriff, $benutzer->berechtigungen)) {
            return $this->verweigern($api);
        }

        if ($this->rotationLaeuft !== null && !self::waehrendRotationOffen($request->path) && ($this->rotationLaeuft)()) {
            return $this->rotationSperre($api, $benutzer->berechtigungen);
        }

        return null;
    }

    /**
     * Besides the rotation and the account's own security pages, the ways
     * out stay open: recovery with a paper key (the only way back after a
     * password reset), update and maintenance (the way to fix a broken
     * release without FTP), backups (ciphertext only).
     */
    private const array WAEHREND_ROTATION_OFFEN = [
        self::ROTATION_SEITE,
        '/app/sicherheit',
        '/admin/wiederherstellen',
        '/admin/update',
        '/admin/wartung',
        '/admin/backups',
    ];

    /**
     * The account's own security pages - the one place an account without
     * a second factor can still reach (setup, and everything it needs).
     */
    public const string EINRICHTUNG = '/app/sicherheit';

    private static function istEinrichtung(string $pfad): bool
    {
        return $pfad === self::EINRICHTUNG || str_starts_with($pfad, self::EINRICHTUNG . '/');
    }

    private static function waehrendRotationOffen(string $pfad): bool
    {
        foreach (self::WAEHREND_ROTATION_OFFEN as $offen) {
            if ($pfad === $offen || str_starts_with($pfad, $offen . '/')) {
                return true;
            }
        }

        return false;
    }

    private function rotationSperre(bool $api, Berechtigungen $berechtigungen): ResponseInterface
    {
        if ($api) {
            return Response::json(['fehler' => 'Der Tresor-Schlüssel wird gerade gewechselt. Bitte später erneut versuchen.'], 409);
        }
        if ($berechtigungen->darf(Permission::AdminVaultGrant)) {
            return Response::redirect(self::ROTATION_SEITE);
        }

        return Response::html($this->view->render('error', [
            'title' => 'Schlüsselwechsel läuft',
            'message' => 'Der Tresor-Schlüssel des Vereins wird gerade gewechselt. Bis das abgeschlossen ist, '
                . 'sind Belege und Einstellungen gesperrt. Danach bitte neu anmelden.',
            'startseite' => '/',
        ], Area::App), 503);
    }

    private function darf(Request $request, Zugriff $zugriff, Berechtigungen $berechtigungen): bool
    {
        $pfad = $request->path;
        if (($pfad === '/admin' || str_starts_with($pfad, '/admin/')) && !$berechtigungen->darfAdminBereich()) {
            return false;
        }

        return match ($zugriff->art) {
            ZugriffArt::Oeffentlich, ZugriffArt::Cron, ZugriffArt::Worker, ZugriffArt::Angemeldet => true,
            ZugriffArt::AdminBereich => $berechtigungen->darfAdminBereich(),
            ZugriffArt::Recht => $zugriff->recht !== null && $berechtigungen->darf($zugriff->recht),
        };
    }

    private function verweigern(bool $api): ResponseInterface
    {
        if ($api) {
            return Response::json(['fehler' => 'Keine Berechtigung.'], 403);
        }

        return Response::html($this->view->render('error', [
            'title' => 'Keine Berechtigung',
            'message' => 'Für diese Seite fehlt Ihrem Zugang das nötige Recht. Wenden Sie sich an die Vereinsverwaltung, wenn Sie es brauchen.',
            'startseite' => '/app',
        ], Area::App), 403);
    }

    private function abweisen(Request $request, bool $api, bool $abgelaufen): ResponseInterface
    {
        if ($api) {
            return Response::json(['fehler' => 'Nicht angemeldet.'], 401);
        }

        $antwort = Response::redirect('/anmelden' . self::weiter($request, $abgelaufen));

        // Only after an expiry: deleting the cookie on every anonymous
        // request would mean a stray link could log somebody out of the
        // session they still have in another tab.
        return $abgelaufen
            ? $antwort->withCookie(Cookie::vaultKey('', Request::httpsFromGlobals())->expired())
            : $antwort;
    }

    /**
     * Where to return to after the login. Only GET requests are worth
     * remembering - replaying a POST after a login would repeat a write the
     * user has long forgotten about.
     */
    private static function weiter(Request $request, bool $abgelaufen): string
    {
        $parameter = [];
        if ($request->method === HttpMethod::Get && $request->path !== '/app') {
            $parameter['weiter'] = $request->path;
        }
        if ($abgelaufen) {
            $parameter['abgelaufen'] = '1';
        }

        return $parameter === [] ? '' : '?' . http_build_query($parameter);
    }
}
