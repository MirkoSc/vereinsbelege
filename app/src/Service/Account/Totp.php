<?php

declare(strict_types=1);

namespace App\Service\Account;

/**
 * Time-based one-time passwords, RFC 6238 on top of HOTP (RFC 4226), in pure
 * PHP - no dependency, the same reasoning CLAUDE.md section 8 already applies
 * to App\Service\Mail\SmtpTransport: the protocol is small (one HMAC, one
 * truncation, one modulo) and already has to be exact, so a library buys
 * nothing a hand-written, test-vector-checked implementation does not also
 * give.
 *
 * Secrets are raw bytes everywhere in this class; the Base32 text an
 * authenticator app expects (RFC 4648, no lower case, padded with `=`) is
 * produced only at the edges - the QR code and the "type it in by hand"
 * fallback (App\Support\QrCode, App\Service\Account\MfaEnrollment).
 *
 * SHA-1 is the production default: it is the one algorithm every
 * authenticator app actually implements, RFC 6238's own recommendation
 * notwithstanding. SHA-256/512 exist here only so the RFC's published test
 * vectors (Appendix B) can be verified directly instead of taken on faith.
 */
final class Totp
{
    /** 160 bits - RFC 4226's recommended secret length, and what every
     *  authenticator app expects a freshly generated secret to be. */
    public const int SECRET_BYTES = 20;

    public const int DEFAULT_DIGITS = 6;

    public const int DEFAULT_PERIOD = 30;

    public const string DEFAULT_ALGORITHM = 'sha1';

    private const string BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        return random_bytes(self::SECRET_BYTES);
    }

    /**
     * The `otpauth://` URI an authenticator app scans from the QR code
     * (Google Authenticator Key URI Format - the de facto standard every app
     * follows). Deliberately minimal: `algorithm`/`digits`/`period` are left
     * out because every app assumes SHA1/6/30 unless told otherwise, and the
     * separate `issuer` query parameter is left out too - the label already
     * carries it, and every app this project has been checked against reads
     * the issuer from there. Both omissions exist for the same reason:
     * App\Support\QrCode only covers versions 1-6 (up to 106 bytes at error
     * correction level M), and a real account name (an e-mail address) can
     * come close to that on its own.
     */
    public static function uri(#[\SensitiveParameter] string $secret, string $accountName, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($accountName);

        return sprintf('otpauth://totp/%s?secret=%s', $label, self::base32Encode($secret));
    }

    /**
     * The current code for display-free contexts (tests, and MfaService
     * comparing directly). Production verification goes through verify(),
     * which also accepts the neighbouring time steps.
     */
    public static function code(
        #[\SensitiveParameter] string $secret,
        \DateTimeImmutable $at,
        int $digits = self::DEFAULT_DIGITS,
        int $period = self::DEFAULT_PERIOD,
        string $algorithm = self::DEFAULT_ALGORITHM,
    ): string {
        return self::hotp($secret, self::counter($at, $period), $digits, $algorithm);
    }

    /**
     * Checks a code against the current time step and, to absorb clock drift
     * between server and phone, the `$window` steps to either side - one
     * step (±30 s) both ways is the usual recommendation and what this app
     * uses in production.
     */
    public static function verify(
        #[\SensitiveParameter] string $secret,
        string $code,
        ?\DateTimeImmutable $now = null,
        int $window = 1,
        int $digits = self::DEFAULT_DIGITS,
        int $period = self::DEFAULT_PERIOD,
        string $algorithm = self::DEFAULT_ALGORITHM,
    ): bool {
        $code = trim($code);
        if (!preg_match('/^\d{' . $digits . '}$/', $code)) {
            return false;
        }

        $counter = self::counter($now ?? new \DateTimeImmutable(), $period);
        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals(self::hotp($secret, $counter + $offset, $digits, $algorithm), $code)) {
                return true;
            }
        }

        return false;
    }

    public static function base32Encode(string $bytes): string
    {
        $encoded = '';
        $buffer = 0;
        $bits = 0;

        foreach (str_split($bytes) as $byte) {
            $buffer = ($buffer << 8) | ord($byte);
            $bits += 8;

            while ($bits >= 5) {
                $bits -= 5;
                $encoded .= self::BASE32_ALPHABET[($buffer >> $bits) & 31];
            }
        }

        if ($bits > 0) {
            $encoded .= self::BASE32_ALPHABET[($buffer << (5 - $bits)) & 31];
        }

        $padding = (8 - (strlen($encoded) % 8)) % 8;

        return $encoded . str_repeat('=', $padding);
    }

    public static function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $encoded) ?? '');

        $bytes = '';
        $buffer = 0;
        $bits = 0;

        foreach (str_split($encoded) as $character) {
            $value = strpos(self::BASE32_ALPHABET, $character);
            if ($value === false) {
                continue;
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

    private static function counter(\DateTimeImmutable $at, int $period): int
    {
        return intdiv($at->getTimestamp(), $period);
    }

    /**
     * RFC 4226 section 5.3: HMAC over the 8-byte big-endian counter, dynamic
     * truncation into a 31-bit integer, reduced mod 10^digits.
     */
    private static function hotp(#[\SensitiveParameter] string $secret, int $counter, int $digits, string $algorithm): string
    {
        $counterBytes = pack('J', $counter);
        $hash = hash_hmac($algorithm, $counterBytes, $secret, true);

        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        $otp = (string) ($binary % (10 ** $digits));

        return str_pad($otp, $digits, '0', STR_PAD_LEFT);
    }
}
