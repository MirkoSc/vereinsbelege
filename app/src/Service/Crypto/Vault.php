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
 *
 * Generations (issue #90, "Schlüsselrotation"): an unlocked vault may carry
 * the secret keys of OLDER generations next to its own. Sealing always uses
 * the newest key; opening looks at the version byte of the sealed key and
 * picks the generation it names. That is what lets a session read rows that
 * are still sealed to the previous key while the rotation chain re-seals
 * them - and what lets the chain itself open with the old key and seal with
 * the new one. A locked vault never has previous generations: there is
 * nothing to open with.
 */
final readonly class Vault
{
    /**
     * Sealed data keys carry the vault version they belong to, so that a key
     * rotation (new vault, re-sealing by step chain - issue #90,
     * docs/spec/01-sicherheit.md section 2) can tell the generations apart
     * instead of failing with a plain authentication error.
     */
    public const int FIRST_VERSION = 1;

    public const int MAX_VERSION = 255;

    /**
     * @param array<int, string> $previous secret keys of older generations,
     *        version => VK_priv; empty for a locked vault
     */
    private function __construct(
        private string $publicKey,
        private ?string $secretKey,
        public int $version,
        private array $previous = [],
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
        } elseif ($previous !== []) {
            throw new CryptoException('A locked vault cannot carry previous generations.');
        }

        foreach ($previous as $olderVersion => $olderSecretKey) {
            if (!is_int($olderVersion) || $olderVersion < self::FIRST_VERSION || $olderVersion >= $version) {
                throw new CryptoException('A previous generation must be older than the vault itself.');
            }
            if (strlen($olderSecretKey) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
                throw new CryptoException('Not a vault secret key (previous generation).');
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

    /**
     * @param array<int, string> $previous secret keys of older generations
     *        (version => VK_priv) this session may open as well
     */
    public static function unlocked(string $publicKey, string $secretKey, int $version = self::FIRST_VERSION, array $previous = []): self
    {
        return new self($publicKey, $secretKey, $version, $previous);
    }

    /**
     * This vault plus an older, unlocked generation it may open as well -
     * what the login builds when a user holds grants of two generations
     * during a rotation.
     *
     * @throws CryptoException when either side is locked or $older is not
     *                         actually older
     */
    public function withPrevious(self $older): self
    {
        if ($older->version >= $this->version) {
            throw new CryptoException(sprintf(
                'Vault version %d is not older than version %d.',
                $older->version,
                $this->version,
            ));
        }

        $previous = $this->previous;
        $previous[$older->version] = $older->secretKey();
        foreach ($older->previous as $version => $secretKey) {
            $previous[$version] = $secretKey;
        }

        return new self($this->publicKey, $this->secretKey(), $this->version, $previous);
    }

    /**
     * The same vault without any previous generation - what a session keeps
     * once a rotation has finished and the old key must not be used anymore.
     */
    public function withoutPrevious(): self
    {
        return new self($this->publicKey, $this->secretKey, $this->version);
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
     * Versions of the older generations this vault can open, ascending.
     *
     * @return list<int>
     */
    public function previousVersions(): array
    {
        $versions = array_keys($this->previous);
        sort($versions);

        return $versions;
    }

    /**
     * Whether a sealed key of $version can be opened here: the own
     * generation or one of the previous ones.
     */
    public function canOpen(int $version): bool
    {
        return $this->secretKey !== null && ($version === $this->version || isset($this->previous[$version]));
    }

    /**
     * One older generation as an unlocked vault of its own (no previous
     * generations of its own): what the rotation chain uses to recompute a
     * blind index under the OLD key, and what SessionVault stores per
     * generation.
     *
     * @throws CryptoException when this vault cannot open that generation
     */
    public function generation(int $version): self
    {
        if ($version === $this->version) {
            return $this->withoutPrevious();
        }

        $secretKey = $this->previous[$version]
            ?? throw new CryptoException(sprintf('This vault cannot open generation %d.', $version));

        return new self(sodium_crypto_box_publickey_from_secretkey($secretKey), $secretKey, $version);
    }

    /**
     * The stored `dek_sealed`: version (1 byte) | sealed box. Always the
     * newest generation - during a rotation new rows are born re-sealed.
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
        if ($this->secretKey === null) {
            throw new CryptoException('The vault is locked, no data key can be opened.');
        }

        if (strlen($sealed) <= 1) {
            throw new CryptoException('Sealed data key is too short.');
        }

        $version = ord($sealed[0]);
        if ($version === $this->version) {
            $secretKey = $this->secretKey;
            $publicKey = $this->publicKey;
        } elseif (isset($this->previous[$version])) {
            $secretKey = $this->previous[$version];
            $publicKey = sodium_crypto_box_publickey_from_secretkey($secretKey);
        } else {
            throw new CryptoException(sprintf(
                'Sealed data key belongs to vault version %d, this vault is version %d.',
                $version,
                $this->version,
            ));
        }

        $raw = sodium_crypto_box_seal_open(
            substr($sealed, 1),
            sodium_crypto_box_keypair_from_secretkey_and_publickey($secretKey, $publicKey),
        );

        if ($raw === false) {
            throw new CryptoException('Sealed data key could not be opened.');
        }

        return DataKey::fromRaw($raw);
    }

    /**
     * The generation byte of a stored `dek_sealed`, without opening it -
     * for the rotation chain, which has to tell "still old" from "done".
     *
     * @throws CryptoException when the value is too short to carry one
     */
    public static function sealedVersion(string $sealed): int
    {
        if (strlen($sealed) <= 1) {
            throw new CryptoException('Sealed data key is too short.');
        }

        return ord($sealed[0]);
    }

    /**
     * BIK = KDF(VK_priv, "blind-index-v1"): computable only while the vault is
     * unlocked, which is what keeps blind indexes out of reach of anyone who
     * merely reads the database. Always the newest generation's key - a
     * rotation recomputes every stored index under it (issue #90).
     */
    public function blindIndex(): BlindIndex
    {
        $secretKey = $this->secretKey ?? throw new CryptoException('The vault is locked, no blind index can be computed.');

        return self::blindIndexFromSecret($secretKey);
    }

    /**
     * The blind index key of an older generation - the rotation chain needs
     * it to recognise which running number a stored duplicate key carried.
     *
     * @throws CryptoException when this vault cannot open that generation
     */
    public function blindIndexFor(int $version): BlindIndex
    {
        return self::blindIndexFromSecret($this->generation($version)->secretKey());
    }

    private static function blindIndexFromSecret(#[\SensitiveParameter] string $secretKey): BlindIndex
    {
        return new BlindIndex(sodium_crypto_generichash('blind-index-v1', $secretKey, BlindIndex::BYTES));
    }

    /**
     * @return array<string, string|int|list<int>>
     */
    public function __debugInfo(): array
    {
        return [
            'version' => $this->version,
            'publicKey' => bin2hex($this->publicKey),
            'secretKey' => $this->secretKey === null ? 'null (locked)' : '*** vault secret key ***',
            'previous' => $this->previousVersions(),
        ];
    }
}
