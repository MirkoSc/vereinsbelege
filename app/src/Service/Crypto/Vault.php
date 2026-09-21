<?php

declare(strict_types=1);

namespace App\Service\Crypto;

/**
 * The vault key pair (docs/spec/01-sicherheit.md section 2): the second key
 * level, the one that covers all business data.
 *
 * Two states, and the difference is the whole point of the design:
 *   - locked()   - public key only. Can seal data keys, cannot open any.
 *                  This is what the public submission runs with: it encrypts
 *                  without holding a secret.
 *   - unlocked() - private key present. Only a logged-in session gets here
 *                  (via the user's key pair and the vault grant, M2-2), which
 *                  is why reading receipts is bound to a session and the cron
 *                  can never decrypt.
 *
 * Wrapping, grants and the recovery key are M2-2; this class owns the two
 * operations the rest of the application needs from the vault itself:
 * sealing/opening a data key and deriving the blind index key.
 */
final readonly class Vault
{
    /**
     * Sealed data keys carry the vault version they belong to, so that a key
     * rotation (new vault, re-sealing by step chain - backlog in
     * docs/spec/01-sicherheit.md section 2) can tell the generations apart
     * instead of failing with a plain authentication error.
     */
    public const int FIRST_VERSION = 1;

    private const int MAX_VERSION = 255;

    private function __construct(
        private string $publicKey,
        private ?string $secretKey,
        public int $version,
    ) {
        if ($version < self::FIRST_VERSION || $version > self::MAX_VERSION) {
            throw new CryptoException(sprintf('Vault version out of range: %d.', $version));
        }

        if (strlen($publicKey) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new CryptoException('Not a vault public key.');
        }

        if ($secretKey !== null) {
            if (strlen($secretKey) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
                throw new CryptoException('Not a vault secret key.');
            }

            if (!hash_equals($publicKey, sodium_crypto_box_publickey_from_secretkey($secretKey))) {
                throw new CryptoException('Vault public and secret key do not belong together.');
            }
        }
    }

    /**
     * A fresh vault, unlocked. Persisting it (public key into `vault`, private
     * key wrapped into `vault_grant`) is M2-2's job.
     */
    public static function create(int $version = self::FIRST_VERSION): self
    {
        $pair = sodium_crypto_box_keypair();

        return new self(
            sodium_crypto_box_publickey($pair),
            sodium_crypto_box_secretkey($pair),
            $version,
        );
    }

    public static function locked(string $publicKey, int $version = self::FIRST_VERSION): self
    {
        return new self($publicKey, null, $version);
    }

    public static function unlocked(string $publicKey, string $secretKey, int $version = self::FIRST_VERSION): self
    {
        return new self($publicKey, $secretKey, $version);
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
     * For M2-2, which wraps it for a user or the recovery key. Nothing else
     * has a reason to touch it.
     */
    public function secretKey(): string
    {
        return $this->secretKey ?? throw new CryptoException('The vault is locked.');
    }

    /**
     * The stored `dek_sealed`: version (1 byte) | sealed box.
     */
    public function sealDataKey(DataKey $key): string
    {
        return chr($this->version) . sodium_crypto_box_seal($key->raw(), $this->publicKey);
    }

    /**
     * @throws CryptoException when the vault is locked, belongs to another
     *                         generation, or did not seal this key
     */
    public function openDataKey(string $sealed): DataKey
    {
        $secretKey = $this->secretKey ?? throw new CryptoException('The vault is locked, no data key can be opened.');

        if (strlen($sealed) <= 1) {
            throw new CryptoException('Sealed data key is too short.');
        }

        $version = ord($sealed[0]);
        if ($version !== $this->version) {
            throw new CryptoException(sprintf(
                'Sealed data key belongs to vault version %d, this vault is version %d.',
                $version,
                $this->version,
            ));
        }

        $raw = sodium_crypto_box_seal_open(
            substr($sealed, 1),
            sodium_crypto_box_keypair_from_secretkey_and_publickey($secretKey, $this->publicKey),
        );

        if ($raw === false) {
            throw new CryptoException('Sealed data key could not be opened.');
        }

        return DataKey::fromRaw($raw);
    }

    /**
     * BIK = KDF(VK_priv, "blind-index-v1"): computable only while the vault is
     * unlocked, which is what keeps blind indexes out of reach of anyone who
     * merely reads the database.
     */
    public function blindIndex(): BlindIndex
    {
        $secretKey = $this->secretKey ?? throw new CryptoException('The vault is locked, no blind index can be computed.');

        return new BlindIndex(sodium_crypto_generichash('blind-index-v1', $secretKey, BlindIndex::BYTES));
    }

    /**
     * @return array<string, string|int>
     */
    public function __debugInfo(): array
    {
        return [
            'version' => $this->version,
            'publicKey' => bin2hex($this->publicKey),
            'secretKey' => $this->secretKey === null ? 'null (locked)' : '*** vault secret key ***',
        ];
    }
}
