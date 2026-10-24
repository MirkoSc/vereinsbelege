<?php

declare(strict_types=1);

namespace App\Service\Worker;

use App\Service\Crypto\CryptoException;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Storage\BlobCipher;

/**
 * The envelopes between web host and worker (docs/spec/07-worker.md
 * section 3, issue #52/M7b-2). Pure functions on bytes - no HTTP, no
 * database, no session - so the web host and bin/worker.php (issue #54)
 * share exactly this code, like App\Service\Worker\WorkerProtokoll.
 *
 * Two directions, both asymmetric, neither needs the vault private key:
 *
 *   - **to the worker**: a data key (worker_grant) or the small context
 *     envelope, `crypto_box_seal` to W_pub. Only W_priv - which never
 *     leaves the worker - opens it.
 *   - **from the worker**: results under fresh data keys, each sealed to
 *     VK_pub in the same stored format as every other `dek_sealed`
 *     (vault version byte | sealed box, App\Service\Crypto\Vault). Having
 *     sealed it, the worker cannot open its own result again.
 *
 * The web host can check the shape of what the worker uploads (lengths,
 * version bytes), never its content - it holds no key to it outside a
 * session. A worker that uploads garbage produces an artifact the session
 * later fails to open; it cannot read or overwrite anything else.
 */
final class WorkerKrypto
{
    /** crypto_box_seal of a 32-byte data key: 32 + 48. */
    public const int GRANT_BYTES = DataKey::BYTES + SODIUM_CRYPTO_BOX_SEALBYTES;

    /** A `dek_sealed` for the vault: version byte + sealed data key. */
    public const int DEK_SEALED_BYTES = 1 + self::GRANT_BYTES;

    /** Upper bound of the sealed context (migrations/041: VARBINARY(8192)). */
    public const int KONTEXT_MAX_BYTES = 8192;

    /**
     * Free text beyond this is cut for the worker (the public form allows
     * 1000 characters): at most 6 bytes per character once JSON-escaped,
     * so the envelope always fits KONTEXT_MAX_BYTES.
     */
    public const int KONTEXT_FREITEXT_ZEICHEN = 1000;

    private const int FELD_MIN_BYTES = 1 + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;

    /** Seals a data key to the worker (web host side, worker_grant). */
    public static function grantVersiegeln(DataKey $dek, string $workerPublicKey): string
    {
        self::pruefePublicKey($workerPublicKey);

        return sodium_crypto_box_seal($dek->raw(), $workerPublicKey);
    }

    /** Opens a grant with the worker's key pair (worker side). */
    public static function grantOeffnen(string $grant, string $workerKeyPair): DataKey
    {
        if (strlen($grant) !== self::GRANT_BYTES) {
            throw new CryptoException('A worker grant has the wrong length.');
        }
        $raw = sodium_crypto_box_seal_open($grant, $workerKeyPair);
        if ($raw === false) {
            throw new CryptoException('The worker grant could not be opened.');
        }

        return DataKey::fromRaw($raw);
    }

    /**
     * Seals the AI context to the worker: only free text and cost center
     * hint, by construction - this signature cannot carry a name, e-mail
     * address or IBAN (07 section 3 "Worker-Kontext").
     */
    public static function kontextVersiegeln(string $freitext, ?int $kostenstelleHinweis, string $workerPublicKey): string
    {
        self::pruefePublicKey($workerPublicKey);
        $json = json_encode(
            ['freitext' => mb_substr($freitext, 0, self::KONTEXT_FREITEXT_ZEICHEN, 'UTF-8'), 'kostenstelle_hinweis' => $kostenstelleHinweis],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        $sealed = sodium_crypto_box_seal($json, $workerPublicKey);
        sodium_memzero($json);
        if (strlen($sealed) > self::KONTEXT_MAX_BYTES) {
            throw new CryptoException('The worker context is too large.');
        }

        return $sealed;
    }

    /**
     * Opens the context on the worker.
     *
     * @return array{freitext: string, kostenstelle_hinweis: ?int}
     */
    public static function kontextOeffnen(string $sealed, string $workerKeyPair): array
    {
        $json = sodium_crypto_box_seal_open($sealed, $workerKeyPair);
        if ($json === false) {
            throw new CryptoException('The worker context could not be opened.');
        }
        $daten = json_decode($json, true);
        if (!is_array($daten)) {
            throw new CryptoException('The worker context is not readable.');
        }

        return [
            'freitext' => is_string($daten['freitext'] ?? null) ? $daten['freitext'] : '',
            'kostenstelle_hinweis' => is_int($daten['kostenstelle_hinweis'] ?? null) ? $daten['kostenstelle_hinweis'] : null,
        ];
    }

    /**
     * Seals a result's fresh data key to the vault (worker side): the same
     * stored format as App\Service\Crypto\Vault::sealDataKey(), without
     * needing a Vault object - the worker only knows VK_pub and its version.
     */
    public static function fuerTresorVersiegeln(DataKey $dek, string $vaultPublicKey, int $vaultVersion): string
    {
        if (strlen($vaultPublicKey) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new CryptoException('Not a vault public key.');
        }
        if ($vaultVersion < 1 || $vaultVersion > 255) {
            throw new CryptoException('Vault version out of range.');
        }

        return chr($vaultVersion) . sodium_crypto_box_seal($dek->raw(), $vaultPublicKey);
    }

    /** Shape check of an uploaded `dek_sealed` (web host side). */
    public static function istTresorDek(string $dekSealed, int $vaultVersion): bool
    {
        return strlen($dekSealed) === self::DEK_SEALED_BYTES && ord($dekSealed[0]) === $vaultVersion;
    }

    /** Shape check of an uploaded secretstream header (web host side). */
    public static function istBlobHeader(string $header): bool
    {
        return strlen($header) === BlobCipher::HEADER_BYTES && ord($header[0]) === BlobCipher::VERSION;
    }

    /** Shape check of an uploaded `data_enc` (web host side). */
    public static function istFeldChiffrat(string $daten): bool
    {
        return strlen($daten) >= self::FELD_MIN_BYTES && ord($daten[0]) === FieldCipher::VERSION;
    }

    /**
     * The plaintext length of a blob ciphertext in App\Service\Storage\
     * BlobCipher framing - full blocks of CHUNK_BYTES plus tag, then one
     * final block of 0..CHUNK_BYTES plus tag. Null when no such framing
     * can have this length.
     */
    public static function klartextGroesse(int $cipherBytes): ?int
    {
        $tag = BlobCipher::BLOCK_BYTES - BlobCipher::CHUNK_BYTES;
        if ($cipherBytes < $tag) {
            return null;
        }
        $bloecke = intdiv($cipherBytes - $tag, BlobCipher::BLOCK_BYTES) + 1;
        // The final block carries at most CHUNK_BYTES of plaintext.
        if ($cipherBytes - ($bloecke - 1) * BlobCipher::BLOCK_BYTES > BlobCipher::BLOCK_BYTES) {
            return null;
        }

        return $cipherBytes - $bloecke * $tag;
    }

    private static function pruefePublicKey(string $publicKey): void
    {
        if (strlen($publicKey) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new CryptoException('Not a worker public key.');
        }
    }
}
