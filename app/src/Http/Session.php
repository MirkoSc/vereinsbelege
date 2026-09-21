<?php

declare(strict_types=1);

namespace App\Http;

/**
 * PHP session wrapper.
 *
 * At this milestone it carries only the CSRF token and flash messages; the
 * login state and the encrypted vault key follow with the user management
 * (milestone M3, docs/spec/01-sicherheit.md). The cookie flags are set here
 * once, because they have to hold for every later use.
 *
 * The public submission page (/einreichen) must NOT start a session: it
 * writes into the inbox without an account, and a session cookie on an
 * anonymous page is both pointless and a tracking surface.
 */
final class Session
{
    public function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'httponly' => true,
                // HTTPS is an installation requirement, so in production this
                // is always true and the session cookie is never sent over
                // plain HTTP - without it a single http:// request on a
                // hostile network leaks the session, and with it the key that
                // unwraps the vault.
                // Derived from the request instead of hardcoded because the
                // docker dev setup serves http://localhost:8080, where a
                // secure cookie would silently never be stored.
                'secure' => Request::httpsFromGlobals(),
                'samesite' => 'Lax',
                'path' => '/',
            ]);
            session_start();
        }
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            session_destroy();
        }
    }

    public function csrfToken(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    /**
     * Token from the _csrf form field or the X-CSRF-Token header (htmx).
     */
    public function checkCsrf(Request $request): bool
    {
        $token = $request->post['_csrf'] ?? $request->header('x-csrf-token') ?? '';
        $known = $_SESSION['csrf'] ?? '';

        return is_string($token) && $token !== '' && is_string($known) && $known !== ''
            && hash_equals($known, $token);
    }

    public function flash(string $message): void
    {
        $_SESSION['flash'] = $message;
    }

    public function pullFlash(): ?string
    {
        $message = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        return is_string($message) ? $message : null;
    }
}
