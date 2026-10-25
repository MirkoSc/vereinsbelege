<?php

declare(strict_types=1);

namespace App\Service\Crypto;

/**
 * The anchor of the vault public key outside the database (security review
 * #78, finding N-01, issue #240; docs/spec/01-sicherheit.md section 2
 * "Anker des Tresor-Schlüssels").
 *
 * Sealing works with `vault.public_key` alone - that is what lets the public
 * submission encrypt without a secret, and also what would let anybody who
 * can write the database redirect every new receipt to a key of their own.
 * `vault.public_key_mac` binds the key to the server key in
 * shared/config.php: HMAC-SHA256 over version and public key, with a key
 * derived from the server key (never the server key itself, the same rule
 * as for the login index). The database alone cannot produce a valid MAC,
 * neither can a backup without config.php.
 */
final readonly class TresorAnker
{
    public const int BYTES = 32;

    private const string ZWECK = 'vault-anchor-v1';

    /**
     * @param string $schluessel 32 bytes, derived from the server key
     *        (ServerCrypto::tresorAnker())
     */
    public function __construct(#[\SensitiveParameter] private string $schluessel)
    {
        if (strlen($schluessel) !== self::BYTES) {
            throw new CryptoException(sprintf('The vault anchor key is %d bytes long.', self::BYTES));
        }
    }

    /**
     * The MAC of one generation. The public key has a fixed length, so
     * version and key cannot be shifted into each other.
     */
    public function fuer(int $version, string $publicKey): string
    {
        return hash_hmac('sha256', self::ZWECK . "\x00" . pack('N', $version) . $publicKey, $this->schluessel, true);
    }

    public function passt(int $version, string $publicKey, ?string $mac): bool
    {
        return $mac !== null && strlen($mac) === self::BYTES && hash_equals($this->fuer($version, $publicKey), $mac);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['schluessel' => '*** vault anchor key ***'];
    }
}
