<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;

/**
 * The gap between "password correct" and "second factor confirmed"
 * (docs/spec/01-sicherheit.md section 3, issue #17/M3-4: "M3-4 hängt den
 * Schritt zwischen 'Passwort stimmt' und 'Tresor entsperrt' ein").
 *
 * Built the same way App\Service\Account\SessionVault is, and for the same
 * reason: whatever crosses this gap must not be recoverable from the session
 * file alone. The vault - when the password already opened one - is
 * encrypted with a fresh session key that goes nowhere but the cookie
 * `__Host-2fa`; a session that has this entry but not the cookie is mid-login
 * and cannot be finished by guessing.
 *
 *     $_SESSION['mfa_pending'] = [
 *         'user_id' => int, 'vault_access' => string (VaultAccess::name),
 *         'weiter' => ?string, 'created_at' => int (unix time),
 *         'session_epoch' => int (`user.session_epoch` at the password check),
 *         'vault' => null | version(1) | nonce(24) | secretbox(VK_priv, K_s) + public_key + version,
 *     ]
 *     Cookie __Host-2fa = K_s, base64url
 *
 * Ten minutes (docs/spec/01-sicherheit.md section 3's own precedent: the
 * e-mail code is "10 min gültig") is deliberately short - this is a door
 * standing open between a correct password and a finished login, not a
 * session in its own right. App\Http\LoginGuard never sees this state at
 * all: a pending login has no `user_id` in App\Http\Session, so every
 * protected route already refuses it the same way it refuses an anonymous
 * visitor.
 */
final class PendingLogin
{
    public const int VERSION = 1;

    public const string COOKIE = '__Host-2fa';

    /** Development-only name, mirroring App\Http\Cookie::INSECURE_NAME. */
    public const string INSECURE_COOKIE = '2fa';

    public const int TTL_SECONDS = 600;

    private const string SESSION_KEY = 'mfa_pending';

    private const int KEY_BYTES = SODIUM_CRYPTO_SECRETBOX_KEYBYTES;

    private const int NONCE_BYTES = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

    private const int MAC_BYTES = SODIUM_CRYPTO_SECRETBOX_MACBYTES;

    /**
     * @return string K_s, already encoded for a cookie value
     */
    public function store(
        int $userId,
        ?Vault $vault,
        VaultAccess $vaultAccess,
        MfaMethod $mfaMethod,
        ?string $weiter,
        ?\DateTimeImmutable $now = null,
        int $sessionEpoch = 0,
    ): string {
        $sessionKey = random_bytes(self::KEY_BYTES);

        $vaultEntry = null;
        if ($vault !== null && $vault->isUnlocked()) {
            $nonce = random_bytes(self::NONCE_BYTES);
            $vaultEntry = [
                'cipher' => chr(self::VERSION) . $nonce . sodium_crypto_secretbox($vault->secretKey(), $nonce, $sessionKey),
                'public_key' => $vault->publicKey(),
                'version' => $vault->version,
            ];
        }

        $_SESSION[self::SESSION_KEY] = [
            'user_id' => $userId,
            'vault_access' => $vaultAccess->name,
            'mfa_method' => $mfaMethod->value,
            'weiter' => $weiter,
            'created_at' => ($now ?? new \DateTimeImmutable())->getTimestamp(),
            'vault' => $vaultEntry,
            'session_epoch' => $sessionEpoch,
        ];

        $cookieValue = sodium_bin2base64($sessionKey, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        sodium_memzero($sessionKey);

        return $cookieValue;
    }

    public function isPending(): bool
    {
        return is_array($_SESSION[self::SESSION_KEY] ?? null);
    }

    /**
     * The account whose password already checked out, or null when there is
     * nothing to resume: no pending entry, it expired, or the cookie is
     * missing or wrong. All of those are the same answer on purpose, just
     * like App\Service\Account\SessionVault::unlock() - the only thing any
     * of them leads to is starting the login over.
     *
     * @throws CryptoException when a stored entry is unreadable - an unknown
     *                         format version, the same "broken deployment,
     *                         not a failed login" distinction SessionVault
     *                         makes.
     */
    public function open(#[\SensitiveParameter] ?string $cookieValue, ?\DateTimeImmutable $now = null): ?PendingLoginData
    {
        $stored = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($stored) || $cookieValue === null || $cookieValue === '') {
            return null;
        }

        $now ??= new \DateTimeImmutable();
        $createdAt = $stored['created_at'] ?? null;
        if (!is_int($createdAt) || $now->getTimestamp() - $createdAt >= self::TTL_SECONDS) {
            return null;
        }

        $userId = $stored['user_id'] ?? null;
        if (!is_int($userId) || $userId <= 0) {
            return null;
        }

        $vaultAccess = self::vaultAccessFromName((string) ($stored['vault_access'] ?? ''));
        $mfaMethod = MfaMethod::tryFrom((string) ($stored['mfa_method'] ?? ''));
        if ($mfaMethod === null) {
            return null;
        }
        $weiter = is_string($stored['weiter'] ?? null) ? $stored['weiter'] : null;

        $vault = null;
        $vaultEntry = $stored['vault'] ?? null;
        if (is_array($vaultEntry)) {
            $vault = $this->unlockVault($vaultEntry, $cookieValue);
            if ($vault === null) {
                // A vault entry is there but the cookie key does not open
                // it - the same "wrong or missing key" answer as an absent
                // one, not a crash.
                return null;
            }
        }

        $sessionEpoch = is_int($stored['session_epoch'] ?? null) ? $stored['session_epoch'] : 0;

        return new PendingLoginData($userId, $vault, $vaultAccess, $mfaMethod, $weiter, $sessionEpoch);
    }

    public function clear(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function unlockVault(array $entry, string $cookieValue): ?Vault
    {
        $cipher = (string) ($entry['cipher'] ?? '');
        if (strlen($cipher) < 1 + self::NONCE_BYTES + self::MAC_BYTES) {
            throw new CryptoException('The stored pending vault is too short.');
        }

        $version = ord($cipher[0]);
        if ($version !== self::VERSION) {
            throw new CryptoException(sprintf('The pending vault has unknown format version %d.', $version));
        }

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

        return Vault::unlocked((string) $entry['public_key'], $secretKey, (int) $entry['version']);
    }

    /**
     * VaultAccess is an unbacked enum (App\Service\Account\VaultAccess), so
     * its name is stored as plain text and matched back by hand here - an
     * unknown value fails closed to the strictest case rather than assuming
     * the vault opened.
     */
    private static function vaultAccessFromName(string $name): VaultAccess
    {
        foreach (VaultAccess::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }

        return VaultAccess::Fehlgeschlagen;
    }
}
