<?php

declare(strict_types=1);

namespace App\Service\Worker;

use App\Repository\SettingRepository;
use App\Service\RateLimiter;

/**
 * The web host's own limit on the mail intake (issue #243, N-17 of the
 * security review #78): mails and bytes per worker and hour. The worker
 * limits itself (20 mails per run, 200 per day, E-54) - this is the
 * backstop for a worker that does not, so a broken or taken-over worker
 * cannot fill the inbox and the web space.
 *
 * Counted on the `rate_limit` table (App\Service\RateLimiter, built with
 * RateLimiter::SUBMISSION_WINDOW_SECONDS, the hour the public submission
 * counts in as well): a mail when it became a receipt, bytes when an
 * upload ticket is handed out - before anything is written. Bytes are
 * counted in KiB so the counter stays far below the column's range.
 *
 * Settings `mail_eingang_limit_stunde` (mails, default 60) and
 * `mail_eingang_limit_mb_stunde` (MiB, default 500); an unusable value
 * falls back to the default instead of switching the limit off (like
 * App\Service\Submission\EinreichungsEinstellungen).
 */
final readonly class MailEingangGrenze
{
    public const string SETTING_MAILS_STUNDE = 'mail_eingang_limit_stunde';

    public const string SETTING_MB_STUNDE = 'mail_eingang_limit_mb_stunde';

    public const int MAILS_STUNDE_STANDARD = 60;

    public const int MB_STUNDE_STANDARD = 500;

    private const string ZWECK_MAILS = 'worker_mail';

    private const string ZWECK_KIB = 'worker_mail_kib';

    public function __construct(
        private RateLimiter $limiter,
        private SettingRepository $settings,
    ) {
    }

    public function mailsStunde(): int
    {
        return self::werte($this->settings)['mails'];
    }

    public function mbStunde(): int
    {
        return self::werte($this->settings)['mb'];
    }

    /**
     * Both limits as set - also for the admin page, which shows them.
     *
     * @return array{mails: int, mb: int}
     */
    public static function werte(SettingRepository $settings): array
    {
        return [
            'mails' => self::positiv($settings->get(self::SETTING_MAILS_STUNDE), self::MAILS_STUNDE_STANDARD),
            'mb' => self::positiv($settings->get(self::SETTING_MB_STUNDE), self::MB_STUNDE_STANDARD),
        ];
    }

    /** Whether this worker may hand in one more mail this hour. */
    public function mailErlaubt(int $workerId, ?\DateTimeImmutable $jetzt = null): bool
    {
        return $this->limiter->stand(self::ZWECK_MAILS, (string) $workerId, $jetzt) < $this->mailsStunde();
    }

    /** Whether $bytes more fit into this worker's hour. */
    public function bytesErlaubt(int $workerId, int $bytes, ?\DateTimeImmutable $jetzt = null): bool
    {
        return $this->limiter->stand(self::ZWECK_KIB, (string) $workerId, $jetzt) + self::kib($bytes) <= $this->mbStunde() * 1024;
    }

    public function mailZaehlen(int $workerId, ?\DateTimeImmutable $jetzt = null): void
    {
        $this->limiter->register(self::ZWECK_MAILS, (string) $workerId, $jetzt);
    }

    public function bytesZaehlen(int $workerId, int $bytes, ?\DateTimeImmutable $jetzt = null): void
    {
        $this->limiter->registerMenge(self::ZWECK_KIB, (string) $workerId, self::kib($bytes), $jetzt);
    }

    private static function kib(int $bytes): int
    {
        return intdiv(max(0, $bytes) + 1023, 1024);
    }

    private static function positiv(string $wert, int $standard): int
    {
        $zahl = filter_var(trim($wert), FILTER_VALIDATE_INT);

        return is_int($zahl) && $zahl > 0 ? min($zahl, 1_000_000) : $standard;
    }
}
