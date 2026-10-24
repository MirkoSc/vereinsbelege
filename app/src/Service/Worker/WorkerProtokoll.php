<?php

declare(strict_types=1);

namespace App\Service\Worker;

/**
 * The wire contract between the web host and the optional worker
 * (docs/spec/07-worker.md sections 4 and 5, issue #51/M7b-1).
 *
 * Pure functions only - no request, session or database - so the worker's
 * own client (bin/worker.php, issue #54) signs with exactly the code the
 * web host verifies with.
 *
 * A signed request carries:
 *   Authorization: Worker <id>        (fallback header X-Worker-Authorization,
 *                                      for hosts whose FastCGI drops
 *                                      Authorization)
 *   X-Worker-Timestamp: <unix seconds>
 *   X-Worker-Nonce: <16-64 chars [A-Za-z0-9_-]>
 *   X-Worker-Signature: hex(HMAC-SHA256(access key, canonical string))
 * with the canonical string
 *   METHOD | PATH | TIMESTAMP | NONCE | hex(SHA-256(body))
 * PATH is the request path as sent, without query string - worker routes
 * refuse a query string, so nothing a route reads goes unsigned.
 */
final class WorkerProtokoll
{
    /** The protocol this release speaks. */
    public const int VERSION = 1;

    /** Protocol versions this release accepts from a worker. */
    public const array UNTERSTUETZT = [1];

    public const int ZEITFENSTER_SEKUNDEN = 300;

    public const int SCHLUESSEL_BYTES = 32;

    public const string HEADER_AUTH = 'authorization';
    public const string HEADER_AUTH_FALLBACK = 'x-worker-authorization';
    public const string HEADER_ZEIT = 'x-worker-timestamp';
    public const string HEADER_NONCE = 'x-worker-nonce';
    public const string HEADER_SIGNATUR = 'x-worker-signature';

    public const string NONCE_MUSTER = '/^[A-Za-z0-9_-]{16,64}$/';

    private function __construct()
    {
    }

    public static function unterstuetzt(int $version): bool
    {
        return in_array($version, self::UNTERSTUETZT, true);
    }

    public static function kanonisch(string $methode, string $pfad, int $zeit, string $nonce, string $body): string
    {
        return implode('|', [strtoupper($methode), $pfad, (string) $zeit, $nonce, hash('sha256', $body)]);
    }

    public static function signatur(string $schluessel, string $methode, string $pfad, int $zeit, string $nonce, string $body): string
    {
        return hash_hmac('sha256', self::kanonisch($methode, $pfad, $zeit, $nonce, $body), $schluessel);
    }

    /**
     * The headers a worker sends - for the worker client and the tests.
     *
     * @return array<string, string> lowercase names, as App\Http\Request keeps them
     */
    public static function header(int $workerId, string $schluessel, string $methode, string $pfad, string $body, int $zeit, string $nonce): array
    {
        return [
            self::HEADER_AUTH => 'Worker ' . $workerId,
            self::HEADER_ZEIT => (string) $zeit,
            self::HEADER_NONCE => $nonce,
            self::HEADER_SIGNATUR => self::signatur($schluessel, $methode, $pfad, $zeit, $nonce, $body),
        ];
    }

    public static function neueNonce(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }

    /**
     * Fingerprint of W_pub that the admin compares with the worker console
     * (section 4 step 3): SHA-256 of the raw key, hex, uppercase.
     */
    public static function fingerabdruck(string $publicKey): string
    {
        return strtoupper(hash('sha256', $publicKey));
    }

    /**
     * The first 128 bits in groups of four for display, e.g.
     * "A1B2 C3D4 ..." - what both sides show to be compared by eye.
     */
    public static function fingerabdruckAnzeige(string $fingerabdruck): string
    {
        return implode(' ', str_split(substr($fingerabdruck, 0, 32), 4));
    }
}
