<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Service\Crypto\CryptoException;
use App\Service\Crypto\DataKey;

/**
 * Encrypting side of a blob stream (format: {@see BlobCipher}).
 *
 * Takes plaintext in pieces of any size and hands back ciphertext blocks.
 * finish() closes the stream with TAG_FINAL - without it the result is a
 * truncated file that no reader will accept, which is deliberate.
 */
final class BlobStreamWriter
{
    private string $header;

    private string $state;

    private string $buffer = '';

    private bool $finished = false;

    public function __construct(DataKey $key)
    {
        [$this->state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key->raw());
        $this->header = chr(BlobCipher::VERSION) . $header;
    }

    /** Belongs in `file_blob.header`. */
    public function header(): string
    {
        return $this->header;
    }

    /**
     * Encrypts what has become a full block; the remainder waits for the next
     * call. Strictly more than one chunk, so that the last block always still
     * carries data and TAG_FINAL together.
     */
    public function write(string $plaintext): string
    {
        if ($this->finished) {
            throw new CryptoException('The blob stream is already finished.');
        }

        $this->buffer .= $plaintext;
        $ciphertext = '';
        while (strlen($this->buffer) > BlobCipher::CHUNK_BYTES) {
            $chunk = substr($this->buffer, 0, BlobCipher::CHUNK_BYTES);
            $this->buffer = substr($this->buffer, BlobCipher::CHUNK_BYTES);
            $ciphertext .= sodium_crypto_secretstream_xchacha20poly1305_push($this->state, $chunk);
        }

        return $ciphertext;
    }

    /**
     * The last block. An empty blob gets one too - a stream without a final
     * tag is not a stream.
     */
    public function finish(): string
    {
        if ($this->finished) {
            throw new CryptoException('The blob stream is already finished.');
        }

        $this->finished = true;
        $last = sodium_crypto_secretstream_xchacha20poly1305_push(
            $this->state,
            $this->buffer,
            '',
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL,
        );
        $this->buffer = '';

        return $last;
    }
}
