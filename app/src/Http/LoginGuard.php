<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\Service\Account\SessionVault;
use App\View\View;

/**
 * The gate in front of everything that is not public (M3-3, issue #16):
 * /app, /admin and the chunk upload.
 *
 * It answers one question - may this request happen at all? - and it answers
 * it server side, for the route, not by hiding a link
 * (CLAUDE.md section 4). What it does NOT do is decide what a logged-in user
 * may then do: rights are the `Permission` enum of M3-6, and the wrappers in
 * app/src/routes.php are where those will be declared. Until then "logged
 * in" is the whole check, and every protected route carries it visibly.
 *
 * Expiry is part of the gate, because a session that has run out is not
 * logged in anymore (docs/spec/01-sicherheit.md section 2): the session is
 * destroyed and the vault cookie deleted in the same answer, so nothing
 * survives that a later request could pick up.
 */
final readonly class LoginGuard
{
    /**
     * @param \Closure(): SessionTimeouts $timeouts built lazily: it reads
     *        the settings table, and a request that is turned away at the
     *        door should not pay for a database connection first.
     * @param \Closure(int): ?SessionUser $benutzer the account behind the
     *        session id, looked up on every request. That is what makes
     *        locking an account, letting an external account expire or
     *        deleting a user take effect at once instead of at the next
     *        login - a session is not a second, longer-lived permission.
     */
    public function __construct(
        private Session $session,
        private View $view,
        private \Closure $timeouts,
        private \Closure $benutzer,
    ) {
    }

    /**
     * Wraps a page handler. The wrapper is what app/src/routes.php
     * registers, so the protection is visible on the route itself.
     *
     * @param \Closure(Request, array<string, string>): ResponseInterface $handler
     * @return \Closure(Request, array<string, string>): ResponseInterface
     */
    public function page(\Closure $handler): \Closure
    {
        return fn(Request $request, array $params = []): ResponseInterface => $this->check($request)
            ?? $handler($request, $params);
    }

    /**
     * Wraps a JSON handler. Same check, different answer: the upload runs
     * from fetch(), and a redirect to the login form would arrive there as
     * an HTML page where JSON was expected.
     *
     * @param \Closure(Request, array<string, string>): ResponseInterface $handler
     * @return \Closure(Request, array<string, string>): ResponseInterface
     */
    public function api(\Closure $handler): \Closure
    {
        return fn(Request $request, array $params = []): ResponseInterface => $this->check($request, api: true)
            ?? $handler($request, $params);
    }

    /**
     * @return ResponseInterface|null null when the request may proceed
     */
    private function check(Request $request, bool $api = false): ?ResponseInterface
    {
        $this->session->start();

        $userId = $this->session->userId();
        if ($userId === null) {
            return $this->abweisen($request, $api, abgelaufen: false);
        }

        $timeouts = ($this->timeouts)();
        $benutzer = ($this->benutzer)($userId);

        // Three ways a live session stops being one: it ran out, the account
        // behind it is gone, or the account is no longer allowed in. All
        // three end the session here and now.
        if (
            $this->session->isExpired($timeouts->idleSeconds, $timeouts->absoluteSeconds)
            || $benutzer === null
            || !$benutzer->user->mayLogIn(new \DateTimeImmutable())
        ) {
            new SessionVault()->clear();
            $this->session->destroy();

            return $this->abweisen($request, $api, abgelaufen: true);
        }

        $this->session->touch();

        // The chrome of every page behind the login: who is signed in, and
        // the token their logout form needs. Not for the JSON routes - they
        // render nothing.
        if (!$api) {
            $this->view->setAnmeldung($benutzer->anzeigename, $this->session->csrfToken());
        }

        return null;
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
