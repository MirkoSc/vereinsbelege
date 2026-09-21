<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Service\Crypto\CryptoException;
use App\Service\Crypto\DataKey;

/**
 * Decrypting side of a blob stream (format: {@see BlobCipher}).
 *
 * Takes ciphertext in pieces of any size - the storage backends cut the
 * stream differently - and hands back plaintext once a whole block has
 * arrived. finish() insists on having seen TAG_FINAL, so a file that was cut
 * short (a broken download, a half-written backend) is an error instead of a
 * shorter receipt.
 */
final class BlobStreamReader
{
    private string $state;

    private string $buffer = '';

    private bool $final = false;

    public function __construct(DataKey $key, string $header)
    {
        if (strlen($header) !== BlobCipher::HEADER_BYTES) {
            throw new CryptoException('Blob header has the wrong length.');
        }

        $version = ord($header[0]);
        if ($version !== BlobCipher::VERSION) {
            throw new CryptoException(sprintf('Blob has unknown format version %d.', $version));
        }

        $this->state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(substr($header, 1), $key->raw());
    }

    public function read(string $ciphertext): string
    {
        $this->buffer .= $ciphertext;
        $plaintext = '';
        while (!$this->final && strlen($this->buffer) >= BlobCipher::BLOCK_BYTES) {
            $block = substr($this->buffer, 0, BlobCipher::BLOCK_BYTES);
            $this->buffer = substr($this->buffer, BlobCipher::BLOCK_BYTES);
            $plaintext .= $this->pull($block);
        }

        if ($this->final && $this->buffer !== '') {
            throw new CryptoException('Blob stream continues after its final block.');
        }

        return $plaintext;
    }

    /** The shorter last block, plus the check that the stream is whole. */
    public function finish(): string
    {
        if ($this->final) {
            return '';
        }

        if ($this->buffer === '') {
            throw new CryptoException('Blob stream ends without its final block.');
        }

        $plaintext = $this->pull($this->buffer);
        $this->buffer = '';

        if (!$this->final) {
            throw new CryptoException('Last blob block does not carry the final tag.');
        }

        return $plaintext;
    }

    private function pull(string $block): string
    {
        $result = sodium_crypto_secretstream_xchacha20poly1305_pull($this->state, $block);
        if ($result === false) {
            throw new CryptoException('Blob block could not be decrypted.');
        }

        [$plaintext, $tag] = $result;
        if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
            $this->final = true;
        }

        return $plaintext;
    }
}
