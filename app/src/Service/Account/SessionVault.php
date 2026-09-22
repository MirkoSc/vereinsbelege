<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;

/**
 * The unlocked vault of a logged-in session (docs/spec/01-sicherheit.md
 * section 2, "Session-Entsperrung").
 *
 * The rule this class exists for: VK_priv is never written down in a form
 * that one stolen place can read. After a successful login it is encrypted
 * with a fresh random session key K_s and put into $_SESSION - and K_s goes
 * nowhere but into the cookie `__Host-vk`. Whoever gets hold of the server's
 * session files alone holds ciphertext; whoever gets the cookie alone holds
 * a key to something they do not have.
 *
 *     $_SESSION['vault'] = version(1) | nonce(24) | secretbox(VK_priv, K_s)
 *     Cookie __Host-vk   = K_s, base64url
 *
 * The stored value carries a leading version byte like every other stored
 * format in this application (docs/spec/01-sicherheit.md, "Speicherformate"):
 * a session that survives a release switch and then turns out to be written
 * in a format this release does not know says so instead of returning
 * nonsense.
 *
 * The public key and the generation travel next to the ciphertext. Both are
 * public by design (`vault.public_key` sits in the database in the clear),
 * and keeping them here is what lets this class unlock without a database
 * connection - the guard on a page that never decrypts pays for nothing.
 *
 * No state of its own: everything lives in $_SESSION, so a fresh instance
 * per request sees what the last request left behind.
 */
final class SessionVault
{
    public const int VERSION = 1;

    /** The name over HTTPS. Over plain HTTP see App\Http\Cookie::vaultKey(). */
    public const string COOKIE = '__Host-vk';

    private const string SESSION_KEY = 'vault';

    private const int KEY_BYTES = SODIUM_CRYPTO_SECRETBOX_KEYBYTES;

    private const int NONCE_BYTES = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

    private const int MAC_BYTES = SODIUM_CRYPTO_SECRETBOX_MACBYTES;

    /**
     * Locks the unlocked vault into the session and hands back K_s for the
     * cookie. The caller is the only one who ever sees K_s; it is not kept
     * anywhere on the server.
     *
     * @return string K_s, already encoded for a cookie value
     */
    public function store(Vault $vault): string
    {
        $secretKey = $vault->secretKey();
        $sessionKey = random_bytes(self::KEY_BYTES);
        $nonce = random_bytes(self::NONCE_BYTES);

        $_SESSION[self::SESSION_KEY] = [
            'cipher' => chr(self::VERSION) . $nonce . sodium_crypto_secretbox($secretKey, $nonce, $sessionKey),
            'public_key' => $vault->publicKey(),
            'version' => $vault->version,
        ];

        $cookieValue = sodium_bin2base64($sessionKey, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        sodium_memzero($sessionKey);

        return $cookieValue;
    }

    /**
     * Whether this session has a vault stored at all - true after a login,
     * regardless of whether the cookie that opens it is present. Used to
     * tell "not logged in" apart from "logged in, cookie gone".
     */
    public function isStored(): bool
    {
        return is_array($_SESSION[self::SESSION_KEY] ?? null);
    }

    /**
     * The unlocked vault, or null when this session cannot produce one:
     * nothing stored, no cookie, or a cookie that does not open the box.
     * All three are the same answer on purpose - a caller must not be able
     * to tell a missing cookie from a wrong one, and either way the only
     * thing to do is log in again.
     *
     * @throws CryptoException when the stored value is there but unreadable -
     *                         a format version this release does not know.
     *                         That is a broken deployment, not a failed
     *                         login, and it must not look like one.
     */
    public function unlock(#[\SensitiveParameter] ?string $cookieValue): ?Vault
    {
        $stored = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($stored) || $cookieValue === null || $cookieValue === '') {
            return null;
        }

        $cipher = (string) ($stored['cipher'] ?? '');
        if (strlen($cipher) < 1 + self::NONCE_BYTES + self::MAC_BYTES) {
            throw new CryptoException('The stored session vault is too short.');
        }

        $version = ord($cipher[0]);
        if ($version !== self::VERSION) {
            throw new CryptoException(sprintf('The session vault has unknown format version %d.', $version));
        }

        // A cookie value is whatever the client sent: not base64 at all is a
        // wrong key like any other, not an error worth a stack trace.
        try {
            $sessionKey = sodium_base642bin($cookieValue, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
        } catch (\SodiumException) {
            return null;
        }

        if (strlen($sessionKey) !== self::KEY_BYTES) {
            return null;
        }

        $secretKey = sodium_crypto_secretbox_open(
            substr($cipher, 1 + self::NONCE_BYTES),
            substr($cipher, 1, self::NONCE_BYTES),
            $sessionKey,
        );
        sodium_memzero($sessionKey);

        if ($secretKey === false) {
            return null;
        }

        return Vault::unlocked((string) $stored['public_key'], $secretKey, (int) $stored['version']);
    }

    /**
     * Forgets the stored vault - logout, and every expiry that ends a
     * session. The cookie is cleared by the caller, which owns the response
     * (App\App\AuthController).
     */
    public function clear(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }
}
