<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Service\Crypto\DataKey;

/**
 * Blob encryption: secretstream_xchacha20poly1305 under the blob's data key,
 * plaintext chunks of 64 KiB (docs/spec/01-sicherheit.md section 2).
 *
 * Stored form:
 *
 *     file_blob.header = version (1 byte) | secretstream header (24 bytes)
 *     stream           = ciphertext blocks of 65536 + 17 bytes,
 *                        the last one shorter and carrying TAG_FINAL
 *
 * Two properties matter more than the algorithm name:
 *
 *   - The chunk size is fixed, so a reader can tell the blocks apart without
 *     any framing of its own. That is what lets both storage backends keep a
 *     plain byte stream and stay interchangeable.
 *   - The last block carries TAG_FINAL, so a truncated file is an error and
 *     not a shorter receipt. secretstream also chains the blocks: reordering
 *     or dropping one in the middle fails as well.
 *
 * Neither side ever holds more than one chunk, which is what keeps a 20 MB
 * scan inside the memory limit of a shared host.
 */
final class BlobCipher
{
    public const int VERSION = 1;

    /** Plaintext bytes per block. */
    public const int CHUNK_BYTES = 65536;

    /** Ciphertext bytes of a full block. */
    public const int BLOCK_BYTES = self::CHUNK_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

    public const int HEADER_BYTES = 1 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;

    public static function writer(DataKey $key): BlobStreamWriter
    {
        return new BlobStreamWriter($key);
    }

    public static function reader(DataKey $key, string $header): BlobStreamReader
    {
        return new BlobStreamReader($key, $header);
    }
}
