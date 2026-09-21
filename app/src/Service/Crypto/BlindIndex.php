<?php

declare(strict_types=1);

namespace App\Service\Crypto;

/**
 * Blind indexes: HMAC-SHA256 over a normalised value, so that SQL can find
 * and compare values it cannot read (docs/spec/01-sicherheit.md section 2,
 * CLAUDE.md section 5). Stored in the `*_bi` columns, BINARY(32).
 *
 * The key comes from Vault::blindIndex() (derived from the vault private key,
 * so only an unlocked session can compute one) or from ServerCrypto for the
 * login lookup on `user.email_bi`.
 *
 * Every index carries a purpose ("supplier.iban", "user.email"). The same
 * value in two different columns therefore yields two different indexes,
 * which keeps a database reader from correlating them.
 */
final readonly class BlindIndex
{
    public const int BYTES = 32;

    private const string PURPOSE = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/';

    public function __construct(private string $key)
    {
        if (strlen($key) !== self::BYTES) {
            throw new CryptoException(sprintf('A blind index key is %d bytes long.', self::BYTES));
        }
    }

    /**
     * @return string raw 32 bytes for a BINARY(32) column
     */
    public function forValue(string $purpose, string $value): string
    {
        if (preg_match(self::PURPOSE, $purpose) !== 1) {
            throw new CryptoException(sprintf('Not a usable blind index purpose: "%s".', $purpose));
        }

        return hash_hmac('sha256', $purpose . "\0" . self::normalize($value), $this->key, true);
    }

    /**
     * Case, surrounding and repeated whitespace must not decide whether two
     * values are found as equal: " Muster  GmbH " and "muster gmbh" are the
     * same supplier. Anything beyond that (an IBAN without its groups, a
     * normalised invoice number) is the caller's business - it knows what
     * kind of value it indexes.
     */
    public static function normalize(string $value): string
    {
        $collapsed = preg_replace('/\s+/', ' ', $value) ?? $value;

        return mb_strtolower(trim($collapsed), 'UTF-8');
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['key' => '*** blind index key ***'];
    }
}
