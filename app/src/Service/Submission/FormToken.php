<?php

declare(strict_types=1);

namespace App\Service\Submission;

/**
 * The public submission's stateless credential (docs/spec/03-erfassung-und-ki.md
 * section 1, docs/spec/01-sicherheit.md section 5, issue #24/M4-2).
 *
 * `/einreichen` has no session (App\Http\Session's class docblock) and
 * therefore no CSRF token in the usual sense - but its upload routes and its
 * final submit still need a credential that proves "this request belongs to
 * a page GET /einreichen actually served", the same job CSRF does elsewhere.
 * A signed, self-contained token does that without a server-side store: it
 * carries its own issue time and is verified by recomputing its MAC under a
 * key derived from the server key (level 1, CLAUDE.md section 4) - nothing
 * about it is looked up.
 *
 * Stored form (before base64url): version (1) | issued at, uint32 BE (4) |
 * nonce (16) | HMAC-SHA256 (32) = 53 bytes.
 *
 * The nonce is not secret; its only job is to give every issued token a
 * distinct hash (FormTokenData::hash()) so that uploads and the final
 * submission can be tied to the same page load. Real protection against a
 * script replaying a token or opening it in bulk is the rate limit and proof
 * of work of issue #25/M4-3, which reuses the same issued-at timestamp for
 * its minimum fill time.
 */
final readonly class FormToken
{
    public const int VERSION = 1;

    /** A generous window: also the upper bound M4-3's fill-time check works within. */
    public const int TTL_SECONDS = 24 * 60 * 60;

    private const int SERVER_KEY_BYTES = 32;
    private const int NONCE_BYTES = 16;
    private const int MAC_BYTES = 32;

    /** version + issued-at + nonce. */
    private const int PAYLOAD_BYTES = 1 + 4 + self::NONCE_BYTES;

    /** A little slack for clock differences - not for anything else. */
    private const int CLOCK_SKEW_SECONDS = 60;

    private string $macKey;

    /**
     * @param string $serverKey raw 32 bytes (Config::$serverKey); a
     *        dedicated context tag derives a key of its own so the server
     *        key is never used directly for two different primitives (the
     *        same reasoning as App\Service\Crypto\Vault::blindIndex()).
     */
    public function __construct(string $serverKey)
    {
        if (strlen($serverKey) !== self::SERVER_KEY_BYTES) {
            throw new \InvalidArgumentException(sprintf('The server key is %d bytes long.', self::SERVER_KEY_BYTES));
        }

        $this->macKey = sodium_crypto_generichash('einreichung-formular-v1', $serverKey, self::MAC_BYTES);
    }

    public function ausstellen(?\DateTimeImmutable $now = null): string
    {
        $ausgestellt = ($now ?? new \DateTimeImmutable())->getTimestamp();
        $payload = chr(self::VERSION) . pack('N', $ausgestellt) . random_bytes(self::NONCE_BYTES);

        return self::base64UrlEncode($payload . hash_hmac('sha256', $payload, $this->macKey, true));
    }

    /**
     * Null for anything wrong with the token: unreadable, manipulated,
     * unknown version, issued in the future or older than TTL_SECONDS.
     * Deliberately one outcome for all of these - which one it was is not
     * information a visitor needs, and every case gets the same "please
     * reload" message.
     */
    public function pruefen(string $token, ?\DateTimeImmutable $now = null): ?FormTokenData
    {
        $roh = self::base64UrlDecode($token);
        if ($roh === null || strlen($roh) !== self::PAYLOAD_BYTES + self::MAC_BYTES) {
            return null;
        }

        $payload = substr($roh, 0, self::PAYLOAD_BYTES);
        if (!hash_equals(hash_hmac('sha256', $payload, $this->macKey, true), substr($roh, self::PAYLOAD_BYTES))) {
            return null;
        }

        if (ord($payload[0]) !== self::VERSION) {
            return null;
        }

        $unpacked = unpack('N', substr($payload, 1, 4));
        $ausgestellt = $unpacked === false ? 0 : $unpacked[1];
        $alter = ($now ?? new \DateTimeImmutable())->getTimestamp() - $ausgestellt;
        if ($alter < -self::CLOCK_SKEW_SECONDS || $alter > self::TTL_SECONDS) {
            return null;
        }

        return new FormTokenData(substr($payload, 5), new \DateTimeImmutable('@' . $ausgestellt));
    }

    private static function base64UrlEncode(string $wert): string
    {
        return rtrim(strtr(base64_encode($wert), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $wert): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $wert) !== 1) {
            return null;
        }

        $normalisiert = strtr($wert, '-_', '+/') . str_repeat('=', (4 - strlen($wert) % 4) % 4);
        $decoded = base64_decode($normalisiert, true);

        return $decoded === false ? null : $decoded;
    }
}
