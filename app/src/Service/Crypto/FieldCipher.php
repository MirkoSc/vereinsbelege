<?php

declare(strict_types=1);

namespace App\Service\Crypto;

/**
 * Field encryption: XChaCha20-Poly1305-IETF under a row's data key, with the
 * field's place as associated data (docs/spec/01-sicherheit.md section 2).
 *
 * Stored form of a `*_enc` column (VARBINARY/BLOB, docs/spec/02-datenmodell.md):
 *
 *     version (1 byte) | nonce (24 bytes) | ciphertext+tag
 *
 * The version byte is what makes a later format change possible at all: a
 * reader that meets an unknown one says so instead of returning rubbish.
 */
final class FieldCipher
{
    public const int VERSION = 1;

    private const int NONCE_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

    private const int TAG_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;

    public static function encrypt(DataKey $key, string $plaintext, FieldContext $context): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);

        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $context->aad(),
            $nonce,
            $key->raw(),
        );

        return chr(self::VERSION) . $nonce . $ciphertext;
    }

    /**
     * @throws CryptoException on a wrong key, a wrong context or a manipulated value
     */
    public static function decrypt(DataKey $key, string $stored, FieldContext $context): string
    {
        $minimum = 1 + self::NONCE_BYTES + self::TAG_BYTES;
        if (strlen($stored) < $minimum) {
            throw new CryptoException(sprintf('Field %s is too short to be a ciphertext.', $context->aad()));
        }

        $version = ord($stored[0]);
        if ($version !== self::VERSION) {
            throw new CryptoException(sprintf('Field %s has unknown format version %d.', $context->aad(), $version));
        }

        $nonce = substr($stored, 1, self::NONCE_BYTES);
        $ciphertext = substr($stored, 1 + self::NONCE_BYTES);

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            $context->aad(),
            $nonce,
            $key->raw(),
        );

        if ($plaintext === false) {
            throw new CryptoException(sprintf('Field %s could not be decrypted.', $context->aad()));
        }

        return $plaintext;
    }
}
