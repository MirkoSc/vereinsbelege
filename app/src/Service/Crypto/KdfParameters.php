<?php

declare(strict_types=1);

namespace App\Service\Crypto;

/**
 * The Argon2id parameters that turn a password into the key encryption key
 * (KEK) wrapping a user's private key (docs/spec/01-sicherheit.md section 2).
 *
 * They are stored per user, in plain text, as `user_key.kdf_salt`,
 * `user_key.kdf_ops` and `user_key.kdf_mem`: the salt is not a secret, and
 * keeping the cost factors next to the wrapped key is what allows the
 * defaults to be raised later without locking out existing users - an old row
 * is still opened with the parameters it was written with.
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

    private function __construct(
        public string $salt,
        public int $opsLimit,
        public int $memLimit,
    ) {
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
     * INTERACTIVE (64 MiB) as required by docs/spec/01-sicherheit.md section 2.
     * The hosting check measured 52-56 ms for it on the target host, well
     * inside a login request, and its memory_limit (384 MiB) leaves room.
     */
    public static function defaults(): self
    {
        return new self(
            random_bytes(self::SALT_BYTES),
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
        );
    }

    /**
     * The three columns of a `user_key` row, as read back from the database.
     */
    public static function fromStorage(string $salt, int $opsLimit, int $memLimit): self
    {
        return new self($salt, $opsLimit, $memLimit);
    }

    /**
     * A fresh salt at the same cost - what a password change needs, so that
     * two wrapped keys of the same user never share a salt.
     */
    public function withNewSalt(): self
    {
        return new self(random_bytes(self::SALT_BYTES), $this->opsLimit, $this->memLimit);
    }

    /**
     * @return string the raw 32 byte KEK; never store it anywhere
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
                SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
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
        ];
    }
}
