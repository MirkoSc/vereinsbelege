<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Repository\MfaBackupCodeRepository;
use App\Repository\MfaEmailCodeRepository;
use App\Repository\MfaTotpRepository;
use App\Repository\SettingRepository;
use App\Repository\TrustedDeviceRepository;
use App\Service\Crypto\ServerCrypto;
use App\Service\RateLimiter;

/**
 * The second factor itself (docs/spec/01-sicherheit.md section 3, issue
 * #17/M3-4): checking a TOTP code, issuing and checking an e-mail code,
 * spending a backup code, and remembering a device. No HTTP, no session -
 * the same split App\Service\Account\LoginService keeps between the check
 * and what a controller does with the result.
 *
 * Every stored secret is a hash, never the value itself (docs/spec/
 * 01-sicherheit.md section 3): codes and device tokens go through the same
 * blind-index HMAC `user.email_bi` already uses
 * (App\Service\Crypto\ServerCrypto::blindIndex()), namespaced by purpose and
 * salted with the user id so the same six digits for two different accounts
 * never hash the same. Only the TOTP secret is reversible ciphertext
 * (`ServerCrypto::encrypt()`) - it has to be, a TOTP code cannot be verified
 * from a hash of the secret.
 */
final readonly class MfaService
{
    /** Namespaces of the two counters in `rate_limit`, alongside login.ip/login.account. */
    private const string LIMIT_IP = 'mfa.ip';

    private const string LIMIT_ACCOUNT = 'mfa.account';

    /**
     * Deliberately tighter than the password limits
     * (App\Service\RateLimiter::LOGIN_LIMIT_PER_IP/_ACCOUNT): a second
     * factor is exactly the thing rate limiting exists to make brute-forcing
     * impractical, and a genuine user very rarely needs more than a handful
     * of attempts (a mistyped code, a stale one, one accidental submit).
     */
    public const int LIMIT_PER_IP = 20;

    public const int LIMIT_PER_ACCOUNT = 8;

    public const int WINDOW_SECONDS = 900;

    public const int EMAIL_CODE_DIGITS = 6;

    public const int EMAIL_CODE_TTL_SECONDS = 600;

    /** ±1 time step (±30 s), the usual tolerance for clock drift between server and phone. */
    public const int TOTP_WINDOW_STEPS = 1;

    public const string SETTING_REMEMBER_DAYS = 'mfa_geraet_merken_tage';

    public const int REMEMBER_DAYS_DEFAULT = 30;

    public function __construct(
        private MfaTotpRepository $totp,
        private MfaEmailCodeRepository $emailCodes,
        private MfaBackupCodeRepository $backupCodes,
        private TrustedDeviceRepository $devices,
        private ServerCrypto $crypto,
        private RateLimiter $limits,
    ) {
    }

    // ------------------------------------------------------- rate limiting

    public function isBlocked(string $ip, int $userId, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();

        return $this->limits->isBlocked(self::LIMIT_IP, $ip, self::LIMIT_PER_IP, $now)
            || $this->limits->isBlocked(self::LIMIT_ACCOUNT, (string) $userId, self::LIMIT_PER_ACCOUNT, $now);
    }

    public function registerFailure(string $ip, int $userId, ?\DateTimeImmutable $now = null): void
    {
        $this->limits->registerFailure(self::LIMIT_IP, $ip, $now);
        $this->limits->registerFailure(self::LIMIT_ACCOUNT, (string) $userId, $now);
    }

    public function resetLimit(string $ip, int $userId): void
    {
        $this->limits->reset(self::LIMIT_IP, $ip);
        $this->limits->reset(self::LIMIT_ACCOUNT, (string) $userId);
    }

    /**
     * How long "Dieses Gerät merken" lasts, same fallback rule as
     * App\Service\Account\SessionTimeouts::fromSettings(): an unusable value
     * falls back to the default instead of producing a token that never
     * expires.
     */
    public static function rememberDaysFromSettings(SettingRepository $settings): int
    {
        $days = filter_var($settings->get(self::SETTING_REMEMBER_DAYS), FILTER_VALIDATE_INT);

        return is_int($days) && $days > 0 ? $days : self::REMEMBER_DAYS_DEFAULT;
    }

    // -------------------------------------------------------------- TOTP

    public function verifyTotp(int $userId, string $code, ?\DateTimeImmutable $now = null): bool
    {
        $secret = $this->totp->forUser($userId);
        if ($secret === null || !$secret->isConfirmed()) {
            return false;
        }

        return Totp::verify(
            $this->crypto->decrypt($secret->secretEnc),
            $code,
            $now,
            window: self::TOTP_WINDOW_STEPS,
        );
    }

    // --------------------------------------------------------- E-Mail-Code

    /**
     * @return string the plaintext code - the caller mails it
     *                (App\Service\Mail\Mailer::sendeMfaCode()), MfaService
     *                itself has no mail dependency
     */
    public function requestEmailCode(int $userId, ?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable();
        $code = str_pad((string) random_int(0, 10 ** self::EMAIL_CODE_DIGITS - 1), self::EMAIL_CODE_DIGITS, '0', STR_PAD_LEFT);

        $this->emailCodes->put(
            $userId,
            $this->hash('mfa.email_code', $userId, $code),
            $now->modify(sprintf('+%d seconds', self::EMAIL_CODE_TTL_SECONDS)),
            $now,
        );

        return $code;
    }

    public function verifyEmailCode(int $userId, string $code, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();
        $stored = $this->emailCodes->forUser($userId);
        if ($stored === null || $stored->isExpired($now) || $stored->attemptsExhausted()) {
            return false;
        }

        if (!hash_equals($stored->codeHash, $this->hash('mfa.email_code', $userId, $code))) {
            $this->emailCodes->registerAttempt($userId);

            return false;
        }

        // Single use: a code that just unlocked the login must not work a
        // second time even if it has not expired yet.
        $this->emailCodes->delete($userId);

        return true;
    }

    // -------------------------------------------------------- Backup-Codes

    public function verifyBackupCode(int $userId, string $code, ?\DateTimeImmutable $now = null): bool
    {
        $hash = $this->hash('mfa.backup_code', $userId, BackupCodes::normalize($code));
        $stored = $this->backupCodes->findUnused($userId, $hash);
        if ($stored === null) {
            return false;
        }

        $this->backupCodes->markUsed($stored->id, $now);

        return true;
    }

    // --------------------------------------------------------- Geräte merken

    /**
     * @return string the plaintext token for the `__Host-td` cookie
     *                (App\Http\Cookie::trustedDevice())
     */
    public function rememberDevice(int $userId, string $label, int $days, ?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable();
        $token = bin2hex(random_bytes(32));

        $this->devices->insert(
            $userId,
            $this->hash('trusted_device.token', $userId, $token),
            $label,
            $now->modify(sprintf('+%d days', max(1, $days))),
            $now,
        );

        return $token;
    }

    public function isDeviceTrusted(int $userId, ?string $token, ?\DateTimeImmutable $now = null): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        $now ??= new \DateTimeImmutable();
        $device = $this->devices->findByTokenHash($userId, $this->hash('trusted_device.token', $userId, $token));
        if ($device === null || $device->isExpired($now)) {
            return false;
        }

        $this->devices->touchLastUsed($device->id, $now);

        return true;
    }

    /**
     * @return list<\App\Domain\TrustedDevice>
     */
    public function listDevices(int $userId): array
    {
        return $this->devices->listForUser($userId);
    }

    public function revokeDevice(int $deviceId, int $userId): void
    {
        $this->devices->delete($deviceId, $userId);
    }

    // ------------------------------------------------------- status/anzeige

    public function countUnusedBackupCodes(int $userId): int
    {
        return $this->backupCodes->countUnused($userId);
    }

    /**
     * The value is salted with the user id so that the same code or token
     * for two different accounts never hashes the same
     * (App\Service\Crypto\BlindIndex already namespaces by purpose; this is
     * the second axis).
     */
    private function hash(string $purpose, int $userId, string $value): string
    {
        return $this->crypto->blindIndex()->forValue($purpose, $userId . ':' . $value);
    }
}
