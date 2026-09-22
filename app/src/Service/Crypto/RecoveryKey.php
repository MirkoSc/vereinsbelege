<?php

declare(strict_types=1);

namespace App\Service\Crypto;

/**
 * The recovery key (docs/spec/01-sicherheit.md section 2, E-01): the vault
 * private key in a form a human can write down, shown once during
 * installation and kept on paper in the club's safe.
 *
 * It is the last way in when every admin has forgotten their password, so it
 * *contains* the key rather than pointing at something stored on the server -
 * nothing about it is persisted anywhere. Losing the paper and all admin
 * passwords at the same time means the data is gone; that is the price of a
 * vault whose private key is never on the server in the clear.
 *
 * Payload, 35 bytes:
 *
 *     version (1 byte) | VK_priv (32 bytes) | checksum (2 bytes)
 *
 * encoded as 56 Crockford-Base32 characters, printed in seven groups of
 * eight. Crockford's alphabet leaves out I, L, O and U, and reading accepts
 * O as 0 and I/L as 1, so the characters that get confused when copying by
 * hand cannot go wrong in the first place; the checksum catches the rest.
 *
 * The leading byte is the version of *this encoding*, not the vault
 * generation (unlike `dek_sealed` and `vault_grant`): a printed key has to
 * stay readable for an installation that has since changed the format. Which
 * vault a key belongs to is decided by comparing it against
 * `vault.public_key` in openVault().
 */
final readonly class RecoveryKey
{
    public const int VERSION = 1;

    public const int GROUP_LENGTH = 8;

    private const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const int CHECKSUM_BYTES = 2;

    /** 35 payload bytes are exactly 56 characters - no padding, seven full groups. */
    private const int ENCODED_LENGTH = 56;

    private function __construct(private string $secretKey)
    {
        if (strlen($secretKey) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
            throw new CryptoException('Not a vault secret key.');
        }
    }

    /**
     * @throws CryptoException when the vault is locked
     */
    public static function forVault(Vault $vault): self
    {
        return new self($vault->secretKey());
    }

    /**
     * Reads a key as it was typed in: upper or lower case, with or without
     * the group separators.
     *
     * @throws CryptoException when the key is malformed, mistyped or of an
     *                         unknown encoding version
     */
    public static function parse(#[\SensitiveParameter] string $input): self
    {
        $normalized = self::normalizeGroup($input);

        if (strlen($normalized) !== self::ENCODED_LENGTH) {
            throw new CryptoException(sprintf(
                'A recovery key has %d characters, got %d.',
                self::ENCODED_LENGTH,
                strlen($normalized),
            ));
        }

        $payload = self::decode($normalized);

        $version = ord($payload[0]);
        if ($version !== self::VERSION) {
            throw new CryptoException(sprintf('The recovery key has unknown format version %d.', $version));
        }

        $body = substr($payload, 0, -self::CHECKSUM_BYTES);
        if (!hash_equals(self::checksum($body), substr($payload, -self::CHECKSUM_BYTES))) {
            throw new CryptoException('The recovery key is mistyped (checksum does not match).');
        }

        return new self(substr($body, 1));
    }

    /**
     * The same forgiving normalisation parse() applies to the whole key,
     * usable on a single group: upper/lower case and separators are dropped,
     * `O` reads as `0`, `I`/`L` as `1` (Crockford Base32, see the class
     * docblock). The installer's confirmation step
     * (docs/spec/01-sicherheit.md section 2, "Wiederherstellungsschlüssel")
     * uses it to compare only the last group without ever storing the
     * recovery key itself - the caller hashes the result and compares
     * hashes.
     */
    public static function normalizeGroup(#[\SensitiveParameter] string $input): string
    {
        return strtr(strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $input) ?? ''), [
            'O' => '0',
            'I' => '1',
            'L' => '1',
        ]);
    }

    /**
     * The printable form: seven groups of eight, separated by spaces.
     */
    public function formatted(): string
    {
        return implode(' ', $this->groups());
    }

    /**
     * @return list<string>
     */
    public function groups(): array
    {
        $body = chr(self::VERSION) . $this->secretKey;

        return str_split(self::encode($body . self::checksum($body)), self::GROUP_LENGTH);
    }

    /**
     * The installer asks for the last group before it lets the installation
     * finish - proof that the key was actually written down
     * (docs/spec/01-sicherheit.md section 2).
     */
    public function lastGroup(): string
    {
        $groups = $this->groups();

        return $groups[array_key_last($groups)];
    }

    /**
     * @param string $vaultPublicKey `vault.public_key` of this installation
     *
     * @throws CryptoException when the key belongs to another installation
     */
    public function openVault(string $vaultPublicKey, int $vaultVersion = Vault::FIRST_VERSION): Vault
    {
        if (!hash_equals($vaultPublicKey, sodium_crypto_box_publickey_from_secretkey($this->secretKey))) {
            throw new CryptoException('The recovery key belongs to another vault.');
        }

        return Vault::unlocked($vaultPublicKey, $this->secretKey, $vaultVersion);
    }

    private static function checksum(string $body): string
    {
        return substr(sodium_crypto_generichash($body), 0, self::CHECKSUM_BYTES);
    }

    private static function encode(string $bytes): string
    {
        $encoded = '';
        $buffer = 0;
        $bits = 0;

        foreach (str_split($bytes) as $byte) {
            $buffer = ($buffer << 8) | ord($byte);
            $bits += 8;

            while ($bits >= 5) {
                $bits -= 5;
                $encoded .= self::ALPHABET[($buffer >> $bits) & 31];
            }
        }

        if ($bits > 0) {
            $encoded .= self::ALPHABET[($buffer << (5 - $bits)) & 31];
        }

        return $encoded;
    }

    private static function decode(string $encoded): string
    {
        $bytes = '';
        $buffer = 0;
        $bits = 0;

        foreach (str_split($encoded) as $character) {
            $value = strpos(self::ALPHABET, $character);
            if ($value === false) {
                throw new CryptoException(sprintf('"%s" is not a recovery key character.', $character));
            }

            $buffer = ($buffer << 5) | $value;
            $bits += 5;

            if ($bits >= 8) {
                $bits -= 8;
                $bytes .= chr(($buffer >> $bits) & 255);
            }
        }

        return $bytes;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['secretKey' => '*** vault secret key ***'];
    }
}
