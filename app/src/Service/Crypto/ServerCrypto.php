<?php

declare(strict_types=1);

namespace App\Service\Crypto;

/**
 * The first key level (CLAUDE.md section 4, docs/spec/01-sicherheit.md
 * section 2): the server key from shared/config.php, secretbox, and strictly
 * for OPERATING data - mail addresses of users, the mail queue, API keys of
 * the AI providers, TOTP secrets.
 *
 * Everything it protects has to be readable without a logged-in user: the
 * cron sends mail, the login looks up an address. That is also its limit -
 * the key sits in a file on the same server, so business data (receipts,
 * amounts, suppliers, bank data) never goes through here but through the
 * vault, whose private key is nowhere on disk.
 *
 * Stored form: version (1 byte) | nonce (24 bytes) | ciphertext+tag.
 */
final readonly class ServerCrypto
{
    public const int KEY_BYTES = SODIUM_CRYPTO_SECRETBOX_KEYBYTES;

    public const int VERSION = 1;

    private const int NONCE_BYTES = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

    private const int MAC_BYTES = SODIUM_CRYPTO_SECRETBOX_MACBYTES;

    /**
     * @param string $key the raw server key, 32 bytes (Config::$serverKey)
     */
    public function __construct(private string $key)
    {
        if (strlen($key) !== self::KEY_BYTES) {
            throw new CryptoException(sprintf('The server key is %d bytes long.', self::KEY_BYTES));
        }
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);

        return chr(self::VERSION) . $nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->key);
    }

    /**
     * @throws CryptoException on a wrong key or a manipulated value
     */
    public function decrypt(string $stored): string
    {
        if (strlen($stored) < 1 + self::NONCE_BYTES + self::MAC_BYTES) {
            throw new CryptoException('Server ciphertext is too short.');
        }

        $version = ord($stored[0]);
        if ($version !== self::VERSION) {
            throw new CryptoException(sprintf('Server ciphertext has unknown format version %d.', $version));
        }

        $plaintext = sodium_crypto_secretbox_open(
            substr($stored, 1 + self::NONCE_BYTES),
            substr($stored, 1, self::NONCE_BYTES),
            $this->key,
        );

        if ($plaintext === false) {
            throw new CryptoException('Server ciphertext could not be decrypted.');
        }

        return $plaintext;
    }

    /**
     * The lookup index for operating data, `user.email_bi` above all: the
     * login has to find an account before anybody is logged in, so this one
     * index cannot hang off the vault.
     *
     * The HMAC key is derived from the server key instead of being the server
     * key, so that the same secret is not used for two different primitives.
     */
    public function blindIndex(): BlindIndex
    {
        return new BlindIndex(sodium_crypto_generichash('blind-index-v1', $this->key, BlindIndex::BYTES));
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['key' => '*** server key ***'];
    }
}
