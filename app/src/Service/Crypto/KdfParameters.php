<?php

declare(strict_types=1);

namespace App\Service\Crypto;

/**
 * The Argon2id parameters that turn a password into the key encryption key
 * (KEK) wrapping a user's private key (docs/spec/01-sicherheit.md section 2).
 *
 * They are stored per user, in plain text, as `user_key.kdf_salt`,
 * `user_key.kdf_ops`, `user_key.kdf_mem` and `user_key.kdf_alg`: the salt is
 * not a secret, and keeping the cost factors and the algorithm next to the
 * wrapped key is what allows the defaults to be raised later without locking
 * out existing users - an old row is still opened with the parameters it was
 * written with, and the login lifts it to the current profile (issue #100,
 * App\Service\Account\LoginService).
 */
final readonly class KdfParameters
{
    public const int SALT_BYTES = SODIUM_CRYPTO_PWHASH_SALTBYTES;

    /**
     * The KEK is a secretbox key, which is what wraps the private key.
     */
    public const int KEY_BYTES = SODIUM_CRYPTO_SECRETBOX_KEYBYTES;

    /**
     * libsodium's lower bounds for Argon2id. ext-sodium does not expose them
     * as constants, so they are named here - a stored value below them would
     * fail deep inside the extension with "internal error" instead of saying
     * what is wrong.
     */
    public const int OPSLIMIT_MIN = 1;

    public const int MEMLIMIT_MIN = 8192;

    /**
     * The only algorithm this code derives with: libsodium's identifier for
     * Argon2id in version 1.3 (0x13). The identifier names algorithm and
     * Argon2 version together - libsodium has no Argon2id of another version
     * - so `user_key.kdf_alg` is the one column for both. Every row written
     * before migration 033 used it, which is why the column defaults to it.
     */
    public const int ALGORITHM = SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13;

    /**
     * The current profile for new wrappings: MODERATE, 256 MiB and 3 passes
     * (issue #100, docs/spec/01-sicherheit.md section 2). The hosting check
     * measured 309-321 ms for it on the target host; the wrapping is derived
     * once per login, so that is affordable, and it costs an attacker with a
     * stolen database dump about six times as much as INTERACTIVE did.
     */
    public const int DEFAULT_OPSLIMIT = SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE;

    public const int DEFAULT_MEMLIMIT = SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE;

    private function __construct(
        public string $salt,
        public int $opsLimit,
        public int $memLimit,
        public int $algorithm = self::ALGORITHM,
    ) {
        if ($algorithm !== self::ALGORITHM) {
            // Saying so beats handing an unknown identifier to libsodium:
            // ARGON2I13 would derive a different key and simply look like a
            // wrong password.
            throw new CryptoException(sprintf('Unknown KDF algorithm %d.', $algorithm));
        }

        if (strlen($salt) !== self::SALT_BYTES) {
            throw new CryptoException(sprintf('A KDF salt is %d bytes long, got %d.', self::SALT_BYTES, strlen($salt)));
        }

        if ($opsLimit < self::OPSLIMIT_MIN) {
            throw new CryptoException(sprintf('KDF ops limit %d is below the libsodium minimum.', $opsLimit));
        }

        if ($memLimit < self::MEMLIMIT_MIN) {
            throw new CryptoException(sprintf('KDF memory limit %d is below the libsodium minimum.', $memLimit));
        }
    }

    /**
     * The current profile (DEFAULT_OPSLIMIT/DEFAULT_MEMLIMIT) with a fresh
     * salt - what every new wrapping is written with.
     */
    public static function defaults(): self
    {
        return new self(random_bytes(self::SALT_BYTES), self::DEFAULT_OPSLIMIT, self::DEFAULT_MEMLIMIT);
    }

    /**
     * The KDF columns of a `user_key` row, as read back from the database.
     */
    public static function fromStorage(
        string $salt,
        int $opsLimit,
        int $memLimit,
        int $algorithm = self::ALGORITHM,
    ): self {
        return new self($salt, $opsLimit, $memLimit, $algorithm);
    }

    /**
     * Fresh salt, and each cost factor at least the current profile's: what a
     * new wrapping of an existing key gets (login upgrade, password change).
     * Taken per factor, so that a row stronger in one factor and weaker in
     * the other is raised where it lacks and kept where it has more.
     */
    public function raisedToDefaults(): self
    {
        return new self(
            random_bytes(self::SALT_BYTES),
            max($this->opsLimit, self::DEFAULT_OPSLIMIT),
            max($this->memLimit, self::DEFAULT_MEMLIMIT),
            self::ALGORITHM,
        );
    }

    /**
     * Whether a wrapping written with these parameters is cheaper to attack
     * than one written with the current profile in at least one factor - then
     * the next moment the password is in hand (login, password change) wraps
     * it again, with raisedToDefaults(). Higher stored values are never
     * lowered: lowering the profile in code must not quietly weaken accounts
     * that already have more.
     */
    public function isWeakerThanDefaults(): bool
    {
        // The algorithm needs no comparison while ALGORITHM is the only one
        // the constructor accepts; a second one would have to be added here.
        return $this->opsLimit < self::DEFAULT_OPSLIMIT || $this->memLimit < self::DEFAULT_MEMLIMIT;
    }

    /**
     * @return string the raw 32 byte KEK; never store it anywhere
     *
     * libsodium allocates the Argon2 memory itself, outside PHP's memory
     * manager: `memory_limit` does not count it and does not stop it, the
     * operating system does. Running out of it surfaces as libsodium's bare
     * "internal error".
     *
     * @throws CryptoException when the host cannot spend the requested memory
     */
    public function deriveKey(#[\SensitiveParameter] string $password): string
    {
        try {
            return sodium_crypto_pwhash(
                self::KEY_BYTES,
                $password,
                $this->salt,
                $this->opsLimit,
                $this->memLimit,
                $this->algorithm,
            );
        } catch (\SodiumException $e) {
            throw new CryptoException('The key could not be derived from the password.', previous: $e);
        }
    }

    /**
     * @return array<string, string|int>
     */
    public function __debugInfo(): array
    {
        return [
            'salt' => bin2hex($this->salt),
            'opsLimit' => $this->opsLimit,
            'memLimit' => $this->memLimit,
            'algorithm' => $this->algorithm,
        ];
    }
}
