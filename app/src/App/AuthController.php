<?php

declare(strict_types=1);

namespace App\App;

use App\Http\Cookie;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Service\Account\LoginFailure;
use App\Service\Account\LoginService;
use App\Service\Account\MfaService;
use App\Service\Account\PendingLogin;
use App\Service\Account\SessionVault;
use App\View\Area;
use App\View\View;

/**
 * Login and logout (M3-3/M3-4, issues #16/#17, docs/spec/01-sicherheit.md
 * section 3).
 *
 * The HTTP half of the login: App\Service\Account\LoginService does the
 * checking and the unlocking and knows nothing about requests; this class
 * turns the result into a session, a cookie and a redirect - or, since M3-4,
 * into a still-open door (App\Service\Account\PendingLogin) when the account
 * has a second factor and this request has not answered it yet.
 *
 * The two halves of the unlocked vault part ways here, and that is the whole
 * security property of the session (section 2): VK_priv goes into $_SESSION,
 * encrypted, and the key that opens it goes into the `__Host-vk` cookie and
 * nowhere else. The unlocked Vault object itself is not kept - after this
 * request it exists only as those two halves, and any later page that wants
 * to decrypt has to put them back together. While a second factor is open,
 * the same split holds for the *pending* login instead - see PendingLogin's
 * own docblock.
 *
 * The login page is the one public page with a session: it needs a CSRF
 * token, and it is where the session that follows begins. It renders in
 * Area::Oeffentlich all the same - somebody who is not logged in has no
 * business seeing the navigation of the area they cannot enter.
 */
final readonly class AuthController
{
    public function __construct(
        private View $view,
        private Session $session,
        private SessionVault $vaultSession,
        private PendingLogin $pendingLogin,
        private LoginCompleter $completer,
        private \Closure $login,
        /** @var \Closure(): MfaService built lazily, like $login - only a
         *  successful password check ever needs the trusted-device check. */
        private \Closure $mfa,
    ) {
    }

    public function form(Request $request): ResponseInterface
    {
        $this->session->start();

        if ($this->session->userId() !== null) {
            return Response::redirect('/app');
        }

        return $this->seite(
            hinweis: isset($request->query['abgelaufen'])
                ? 'Ihre Sitzung ist abgelaufen. Bitte melden Sie sich erneut an.'
                : null,
            weiter: self::weiter($request->query['weiter'] ?? null),
        );
    }

    public function submit(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            // Not the login failure message: this is not about the
            // credentials, and telling the user to try again is the only
            // thing that helps.
            return $this->seite(fehler: 'Die Sitzung ist abgelaufen – bitte erneut versuchen.');
        }

        $email = trim((string) ($request->post['email'] ?? ''));
        $weiter = self::weiter($request->post['weiter'] ?? null);

        /** @var LoginService $login */
        $login = ($this->login)();
        $ergebnis = $login->attempt($email, (string) ($request->post['passwort'] ?? ''), $request->ip);

        if (!$ergebnis->istErfolg()) {
            $fehler = $ergebnis->failure ?? LoginFailure::Zugangsdaten;

            // The address stays in the field so that a typo in the password
            // does not cost the whole form - it is what the user just typed,
            // not something the server looked up.
            return $this->seite(fehler: $fehler->meldung(), email: $email, weiter: $weiter);
        }

        assert($ergebnis->user !== null);
        $user = $ergebnis->user;

        // The second factor of M3-4 hooks in exactly here, between "password
        // correct" and "session opened" (docs/spec/01-sicherheit.md
        // section 3). Three ways it does not apply: no factor required at
        // all, `mfa_required` but nothing set up yet (the account is logged
        // in regardless - App\Http\LoginGuard then forces enrollment before
        // anything else), or this exact browser was told to skip it.
        if ($user->mfaRequired && $user->mfaEingerichtet() && !$this->deviceIsTrusted($request, $user->id)) {
            assert($user->mfaMethod !== null);
            $cookieValue = $this->pendingLogin->store(
                $user->id,
                $ergebnis->vault,
                $ergebnis->vaultAccess,
                $user->mfaMethod,
                $weiter,
                sessionEpoch: $user->sessionEpoch,
            );

            return Response::redirect('/anmelden/bestaetigen')->withCookie(
                Cookie::pendingLoginKey($cookieValue, Request::httpsFromGlobals()),
            );
        }

        /** @var LoginService $login */
        $login = ($this->login)();
        $login->registerSuccess($user->id);

        return $this->completer->complete($user->id, $ergebnis->vault, $ergebnis->vaultAccess, $weiter, $user->sessionEpoch);
    }

    private function deviceIsTrusted(Request $request, int $userId): bool
    {
        $token = Cookie::trustedDeviceFrom($request);
        if ($token === null) {
            return false;
        }

        /** @var MfaService $mfa */
        $mfa = ($this->mfa)();

        return $mfa->isDeviceTrusted($userId, $token);
    }

    public function logout(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return Response::redirect('/app');
        }

        // Both halves, in this order: the ciphertext goes with the session
        // anyway, and the cookie that would open it is deleted in the same
        // answer (docs/spec/01-sicherheit.md section 2, "Logout löscht
        // beides").
        $this->vaultSession->clear();
        $this->session->destroy();

        return Response::redirect('/anmelden')
            ->withCookie(Cookie::vaultKey('', Request::httpsFromGlobals())->expired());
    }

    private function seite(
        ?string $fehler = null,
        ?string $hinweis = null,
        string $email = '',
        ?string $weiter = null,
    ): ResponseInterface {
        return Response::html($this->view->render('anmelden', [
            'title' => 'Anmelden',
            'csrf' => $this->session->csrfToken(),
            'flash' => $this->session->pullFlash(),
            'fehler' => $fehler,
            'hinweis' => $hinweis,
            'email' => $email,
            'weiter' => $weiter,
        ], Area::Oeffentlich));
    }

    /**
     * The "where to afterwards" parameter, accepted only as a path on this
     * installation: it comes from the query string, so an open redirect is
     * one careless concatenation away. A value starting with "//" is a
     * foreign host to a browser, which is why it is rejected along with
     * everything that is not a plain absolute path.
     */
    private static function weiter(mixed $wert): ?string
    {
        if (!is_string($wert) || !str_starts_with($wert, '/') || str_starts_with($wert, '//')) {
            return null;
        }

        return preg_match('#^/[A-Za-z0-9\-._~/]*$#', $wert) === 1 ? $wert : null;
    }
}
