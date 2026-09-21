<?php

declare(strict_types=1);

namespace App\Service\Crypto;

/**
 * A user's key pair (docs/spec/01-sicherheit.md section 2): created when the
 * invited user sets their password, and the only thing a vault grant is
 * sealed to.
 *
 * Two states, like the vault itself:
 *   - locked()   - public key only, read from `user_key.public_key`. Enough
 *                  for an admin to seal a grant to this user; an admin never
 *                  needs anything of that user's but this key.
 *   - unlocked() - private key present. Only reachable through
 *                  UserKey::unwrap() with the user's own password, which is
 *                  why nobody but the user can open their grant.
 */
final readonly class UserKeyPair
{
    private function __construct(
        private string $publicKey,
        private ?string $secretKey,
    ) {
        if (strlen($publicKey) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new CryptoException('Not a user public key.');
        }

        if ($secretKey !== null) {
            if (strlen($secretKey) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
                throw new CryptoException('Not a user secret key.');
            }

            if (!hash_equals($publicKey, sodium_crypto_box_publickey_from_secretkey($secretKey))) {
                throw new CryptoException('User public and secret key do not belong together.');
            }
        }
    }

    /**
     * A fresh pair, unlocked. Persisting it means wrapping it first
     * (UserKey::wrap()) - the private key has no storable plaintext form.
     */
    public static function create(): self
    {
        $pair = sodium_crypto_box_keypair();

        return new self(
            sodium_crypto_box_publickey($pair),
            sodium_crypto_box_secretkey($pair),
        );
    }

    public static function locked(string $publicKey): self
    {
        return new self($publicKey, null);
    }

    public static function unlocked(string $publicKey, #[\SensitiveParameter] string $secretKey): self
    {
        return new self($publicKey, $secretKey);
    }

    public function isUnlocked(): bool
    {
        return $this->secretKey !== null;
    }

    public function publicKey(): string
    {
        return $this->publicKey;
    }

    /**
     * For UserKey, which wraps it, and for VaultGrant, which opens a sealed
     * vault key with it. Nothing else has a reason to touch it.
     */
    public function secretKey(): string
    {
        return $this->secretKey ?? throw new CryptoException('The user key pair is locked.');
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'publicKey' => bin2hex($this->publicKey),
            'secretKey' => $this->secretKey === null ? 'null (locked)' : '*** user secret key ***',
        ];
    }
}
