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
    /**
     * Runtime options for session_start(), set here instead of trusting the
     * host's php.ini (security review, issue #78):
     * - cache_limiter "nocache": every page with a session (all of /app and
     *   /admin, i.e. every page showing decrypted data) carries
     *   `Cache-Control: no-store, no-cache, must-revalidate`, so a shared
     *   computer's browser cache does not keep decrypted receipts. That is
     *   PHP's default, but only a default - pinned here so a host setting
     *   cannot turn it off.
     *
     * Deliberately NOT use_strict_mode: the id is regenerated with
     * session_regenerate_id(true) at login and on a password change, and a
     * background poll of another tab still in flight with the old id would
     * then be handed a fresh, empty session plus a Set-Cookie - which, if it
     * lands last, logs the user out of every tab. Fixation is closed by the
     * regeneration at login itself (login()).
     * use_only_cookies and use_trans_sid are not repeated: their safe values
     * are the defaults, and moving away from them is deprecated since PHP 8.4.
     */
    public const array OPTIONEN = [
        'cache_limiter' => 'nocache',
    ];

    /**
     * The session cookie's name over HTTPS (security review N-14, issue
     * #246). The `__Host-` prefix makes the browser refuse the cookie unless
     * it is Secure, has Path=/ and no Domain - so neither a sibling
     * subdomain nor a plain-HTTP response can plant or overwrite it (the
     * session-fixation route a bare PHPSESSID leaves open).
     */
    public const string NAME_HTTPS = '__Host-sid';

    /**
     * Over plain HTTP (only the docker dev setup) a `__Host-` cookie would
     * never be stored; PHP's default name stays, as before.
     */
    public const string NAME_HTTP = 'PHPSESSID';

    /** Keys of the re-authentication state (N-08, issue #246). */
    private const string NEUANMELDUNG = 'neuanmeldung_at';

    private const string TOTP_EINRICHTUNG = 'totp_einrichtung';

    public function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $https = Request::httpsFromGlobals();
            session_name($https ? self::NAME_HTTPS : self::NAME_HTTP);
            // The switch from PHPSESSID (N-14): a browser that still has
            // only the old cookie keeps its session - the same id moves over
            // to `__Host-sid` once, and the old cookie is deleted. Not
            // ignoring it is deliberate: the self-update switches to this
            // release between two of its steps, and an admin logged out
            // there would leave the site in maintenance mode (the login page
            // is closed while the flag is set). The id is kept, not
            // regenerated: a regeneration racing with the pollers' requests,
            // which carry the same old cookie, would log the tab out (the
            // reason use_strict_mode is off). It is no weaker than before -
            // it is the session this browser already had - and the next
            // login regenerates it anyway.
            $alt = $_COOKIE[self::NAME_HTTP] ?? null;
            if ($https && is_string($alt) && !isset($_COOKIE[self::NAME_HTTPS]) && preg_match('/^[A-Za-z0-9,-]{22,256}$/', $alt) === 1) {
                session_id($alt);
            }
            if ($https && $alt !== null && !headers_sent()) {
                setcookie(self::NAME_HTTP, '', [
                    'expires' => 1,
                    'path' => '/',
                    'secure' => true,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
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
                'secure' => $https,
                'samesite' => 'Lax',
                'path' => '/',
                // Pinned empty: `__Host-` forbids a Domain attribute, and a
                // host-wide session.cookie_domain must not turn the cookie
                // into one the browser throws away.
                'domain' => '',
            ]);
            session_start(self::OPTIONEN);
        }
    }

    /**
     * Writes the session and releases its lock - before a response that
     * takes long to send (the ZIP export, issue #76/M12-2), so the user's
     * other tabs need not wait for it. Changes after this are not saved.
     */
    public function schliessen(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
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

        unset($_SESSION['csrf'], $_SESSION[self::NEUANMELDUNG], $_SESSION[self::TOTP_EINRICHTUNG]);
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

    /**
     * Marks that this session just proved password and current second
     * factor again (security review N-08, issue #246, ASVS 3.7.1): changing
     * the second factor or its backup codes asks for that, not only a live
     * session. App\App\SecurityController reads it with
     * neuanmeldungGueltig() and drops it once the change is made.
     */
    public function bestaetigeNeuanmeldung(?\DateTimeImmutable $now = null): void
    {
        $_SESSION[self::NEUANMELDUNG] = ($now ?? new \DateTimeImmutable())->getTimestamp();
    }

    public function neuanmeldungGueltig(int $sekunden, ?\DateTimeImmutable $now = null): bool
    {
        $zeit = $_SESSION[self::NEUANMELDUNG] ?? null;
        if (!is_int($zeit)) {
            return false;
        }
        $alter = ($now ?? new \DateTimeImmutable())->getTimestamp() - $zeit;

        return $alter >= 0 && $alter < $sekunden;
    }

    public function verwirfNeuanmeldung(): void
    {
        unset($_SESSION[self::NEUANMELDUNG]);
    }

    /**
     * A TOTP secret that is being set up but not confirmed yet - server-key
     * ciphertext (App\Service\Crypto\ServerCrypto), the same protection the
     * stored secret has. It lives here instead of in `mfa_totp` so that a
     * setup started and then abandoned leaves the account's current
     * authenticator working (N-08, issue #246).
     */
    public function merkeTotpEinrichtung(string $secretEnc, ?\DateTimeImmutable $now = null): void
    {
        $_SESSION[self::TOTP_EINRICHTUNG] = [
            'enc' => $secretEnc,
            'at' => ($now ?? new \DateTimeImmutable())->getTimestamp(),
        ];
    }

    /**
     * The pending secret, if it was started less than $sekunden ago.
     */
    public function totpEinrichtung(int $sekunden, ?\DateTimeImmutable $now = null): ?string
    {
        $wert = $_SESSION[self::TOTP_EINRICHTUNG] ?? null;
        if (!is_array($wert) || !is_string($wert['enc'] ?? null) || $wert['enc'] === '' || !is_int($wert['at'] ?? null)) {
            return null;
        }
        $alter = ($now ?? new \DateTimeImmutable())->getTimestamp() - $wert['at'];

        return $alter >= 0 && $alter < $sekunden ? $wert['enc'] : null;
    }

    public function verwirfTotpEinrichtung(): void
    {
        unset($_SESSION[self::TOTP_EINRICHTUNG]);
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
