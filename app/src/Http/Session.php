<?php

declare(strict_types=1);

namespace App\Http;

use App\View\Flash;
use App\View\FlashArt;

/**
 * PHP session wrapper.
 *
 * It carries the CSRF token, flash messages and, since M3-3, who is logged
 * in: the user id and the two timestamps the timeouts are measured against
 * (docs/spec/01-sicherheit.md section 2). Nothing else about the account -
 * no name, no address; those are read per request from the database where
 * they are needed. The encrypted vault key sits next to this state and is
 * handled by App\Service\Account\SessionVault.
 *
 * The cookie flags are set here once, because they have to hold for every
 * later use.
 *
 * The public submission page (/einreichen) must NOT start a session: it
 * writes into the inbox without an account, and a session cookie on an
 * anonymous page is both pointless and a tracking surface. The login page
 * is the one public page that does have one - it needs a CSRF token, and it
 * is where the session that follows begins.
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

    /**
     * Marks this session as logged in (docs/spec/01-sicherheit.md section 3:
     * "Session-ID-Regeneration bei Login").
     *
     * Regenerating first and keeping the data means a session id somebody
     * planted before the login is not the id that carries the account
     * afterwards - session fixation ends here. The CSRF token is dropped
     * with it, for the same reason: the token of the anonymous form must not
     * stay valid for the logged-in one.
     *
     * $epoch is the account's `user.session_epoch` as it was when the
     * password was checked (issue #18/M3-5): App\Http\LoginGuard ends the
     * session once the account's value moves on.
     */
    public function login(int $userId, ?\DateTimeImmutable $now = null, int $epoch = 0): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        unset($_SESSION['csrf']);
        $zeit = ($now ?? new \DateTimeImmutable())->getTimestamp();
        $_SESSION['user_id'] = $userId;
        $_SESSION['login_at'] = $zeit;
        $_SESSION['last_seen_at'] = $zeit;
        $_SESSION['session_epoch'] = $epoch;
    }

    /**
     * The `user.session_epoch` this session logged in with. A session from
     * before migration 009 has none and reads as 0 - the column's default,
     * so it survives the update (issue #18/M3-5).
     */
    public function epoch(): int
    {
        $epoch = $_SESSION['session_epoch'] ?? 0;

        return is_int($epoch) ? $epoch : -1;
    }

    /**
     * Keeps THIS session alive across a change that ends all others (the
     * password change, issue #18/M3-5): it takes over the account's new
     * epoch. The id is regenerated as well - a credential change is a
     * "Rechtewechsel" in the sense of docs/spec/01-sicherheit.md section 3.
     */
    public function adoptEpoch(int $epoch): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION['session_epoch'] = $epoch;
    }

    /**
     * The logged-in user's id, or null. Says nothing about the vault: a
     * session can be logged in and still unable to decrypt, which is exactly
     * what happens when the `__Host-vk` cookie is gone.
     */
    public function userId(): ?int
    {
        $id = $_SESSION['user_id'] ?? null;

        return is_int($id) && $id > 0 ? $id : null;
    }

    /**
     * Pushes the idle timeout out by one request.
     */
    public function touch(?\DateTimeImmutable $now = null): void
    {
        $_SESSION['last_seen_at'] = ($now ?? new \DateTimeImmutable())->getTimestamp();
    }

    /**
     * Whether the session has run out: idle for too long, or simply too old
     * (docs/spec/01-sicherheit.md section 2 - 30 minutes and 12 hours by
     * default). A session without the timestamps is treated as expired; it
     * cannot be proven fresh, and the only cost of being wrong is one login.
     */
    public function isExpired(int $idleSeconds, int $absoluteSeconds, ?\DateTimeImmutable $now = null): bool
    {
        $loginAt = $_SESSION['login_at'] ?? null;
        $lastSeen = $_SESSION['last_seen_at'] ?? null;
        if (!is_int($loginAt) || !is_int($lastSeen)) {
            return true;
        }

        $jetzt = ($now ?? new \DateTimeImmutable())->getTimestamp();

        return $jetzt - $lastSeen >= $idleSeconds || $jetzt - $loginAt >= $absoluteSeconds;
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

    /**
     * Never fachliche Klartextdaten - see App\View\Flash.
     */
    public function flash(string $message, FlashArt $art = FlashArt::Ok): void
    {
        $_SESSION['flash'] = ['text' => $message, 'art' => $art->value];
    }

    public function pullFlash(): ?Flash
    {
        $gespeichert = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        // A release switch does not end the running sessions, so the value
        // in the store can still be the plain string an older release wrote
        // (and, after a downgrade, the array a newer one wrote). Both stay
        // readable rather than throwing on the first page after an update.
        if (is_string($gespeichert)) {
            return new Flash($gespeichert);
        }
        if (!is_array($gespeichert) || !is_string($gespeichert['text'] ?? null)) {
            return null;
        }

        $art = is_string($gespeichert['art'] ?? null)
            ? FlashArt::tryFrom($gespeichert['art'])
            : null;

        return new Flash($gespeichert['text'], $art ?? FlashArt::Ok);
    }
}
