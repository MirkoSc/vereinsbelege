<?php

declare(strict_types=1);

namespace App\Service\Crypto;

/**
 * A data key (DEK): one random key per row or per blob
 * (docs/spec/01-sicherheit.md section 2).
 *
 * The key itself never reaches the database. What is stored there is the
 * sealed form produced by Vault::sealDataKey() - sealing needs the vault
 * public key only, which is why the public submission can encrypt without
 * holding any secret.
 */
final readonly class DataKey
{
    public const int BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES;

    private function __construct(private string $raw)
    {
    }

    public static function generate(): self
    {
        return new self(random_bytes(self::BYTES));
    }

    public static function fromRaw(string $raw): self
    {
        if (strlen($raw) !== self::BYTES) {
            throw new CryptoException(sprintf('A data key is %d bytes long, got %d.', self::BYTES, strlen($raw)));
        }

        return new self($raw);
    }

    public function raw(): string
    {
        return $this->raw;
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->raw, $other->raw);
    }

    /**
     * Keeps the key out of var_dump() output in a debug session.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['raw' => '*** data key ***'];
    }
}
