<?php

declare(strict_types=1);

namespace App\Service\Crypto;

/**
 * A `user_key` row (docs/spec/02-datenmodell.md): the user's public key plus
 * their private key wrapped with a KEK derived from their password
 * (docs/spec/01-sicherheit.md section 2).
 *
 * Wrapped form of `wrapped_private_key`:
 *
 *     version (1 byte) | nonce (24 bytes) | secretbox(U_priv, KEK)
 *
 * KEK = Argon2id(password, kdf_salt, kdf_ops, kdf_mem) - the KDF parameters
 * travel in their own columns (KdfParameters).
 *
 * This is deliberately not where the login password hash lives: the password
 * hash and the KEK salt are separate (docs/spec/01-sicherheit.md section 3),
 * so that the stored hash cannot be used to attack the wrapping and vice
 * versa. Verifying a password at login is M3-3's job.
 */
final readonly class UserKey
{
    public const int VERSION = 1;

    private const int NONCE_BYTES = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

    private const int MAC_BYTES = SODIUM_CRYPTO_SECRETBOX_MACBYTES;

    private function __construct(
        private string $publicKey,
        private string $wrappedPrivateKey,
        private KdfParameters $kdf,
    ) {
        if (strlen($publicKey) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new CryptoException('Not a user public key.');
        }
    }

    /**
     * @param KdfParameters|null $kdf the cost factors to use; null takes the
     *                                current defaults with a fresh salt
     */
    public static function wrap(
        UserKeyPair $pair,
        #[\SensitiveParameter] string $password,
        ?KdfParameters $kdf = null,
    ): self {
        $secretKey = $pair->secretKey();
        $kdf ??= KdfParameters::defaults();
        $nonce = random_bytes(self::NONCE_BYTES);
        $kek = $kdf->deriveKey($password);

        $wrapped = chr(self::VERSION) . $nonce . sodium_crypto_secretbox($secretKey, $nonce, $kek);
        sodium_memzero($kek);

        return new self($pair->publicKey(), $wrapped, $kdf);
    }

    /**
     * The row as read back from the database.
     */
    public static function fromStorage(string $publicKey, string $wrappedPrivateKey, KdfParameters $kdf): self
    {
        return new self($publicKey, $wrappedPrivateKey, $kdf);
    }

    public function publicKey(): string
    {
        return $this->publicKey;
    }

    /**
     * The `wrapped_private_key` column.
     */
    public function wrappedPrivateKey(): string
    {
        return $this->wrappedPrivateKey;
    }

    /**
     * The `kdf_salt`, `kdf_ops` and `kdf_mem` columns.
     */
    public function kdf(): KdfParameters
    {
        return $this->kdf;
    }

    /**
     * @throws CryptoException on a wrong password, a manipulated value or an
     *                         unknown format version
     */
    public function unwrap(#[\SensitiveParameter] string $password): UserKeyPair
    {
        if (strlen($this->wrappedPrivateKey) < 1 + self::NONCE_BYTES + self::MAC_BYTES) {
            throw new CryptoException('The wrapped user key is too short.');
        }

        $version = ord($this->wrappedPrivateKey[0]);
        if ($version !== self::VERSION) {
            throw new CryptoException(sprintf('The wrapped user key has unknown format version %d.', $version));
        }

        $kek = $this->kdf->deriveKey($password);
        $secretKey = sodium_crypto_secretbox_open(
            substr($this->wrappedPrivateKey, 1 + self::NONCE_BYTES),
            substr($this->wrappedPrivateKey, 1, self::NONCE_BYTES),
            $kek,
        );
        sodium_memzero($kek);

        if ($secretKey === false) {
            throw new CryptoException('The user key could not be unwrapped.');
        }

        return UserKeyPair::unlocked($this->publicKey, $secretKey);
    }

    /**
     * Password change with the old password known: the private key is wrapped
     * again, nothing else changes (docs/spec/01-sicherheit.md section 2,
     * "Benutzer-Lebenszyklus"). The key pair stays the same, so existing vault
     * grants keep working - no admin has to approve anything again.
     *
     * The new wrapping gets a fresh salt, and picks up raised defaults if the
     * old row still carries weaker ones.
     */
    public function rewrap(
        #[\SensitiveParameter] string $oldPassword,
        #[\SensitiveParameter] string $newPassword,
    ): self {
        $defaults = KdfParameters::defaults();
        $kdf = $this->kdf->opsLimit >= $defaults->opsLimit && $this->kdf->memLimit >= $defaults->memLimit
            ? $this->kdf->withNewSalt()
            : $defaults;

        return self::wrap($this->unwrap($oldPassword), $newPassword, $kdf);
    }

    /**
     * @return array<string, string|array<string, string|int>>
     */
    public function __debugInfo(): array
    {
        return [
            'publicKey' => bin2hex($this->publicKey),
            'wrappedPrivateKey' => '*** wrapped user secret key ***',
            'kdf' => $this->kdf->__debugInfo(),
        ];
    }
}
