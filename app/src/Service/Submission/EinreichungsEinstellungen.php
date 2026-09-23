<?php

declare(strict_types=1);

namespace App\Service\Submission;

use App\Repository\SettingRepository;
use App\Service\Upload\UploadService;

/**
 * The admin-configurable half of the public submission's spam defence
 * (docs/spec/01-sicherheit.md section 5, issue #25/M4-3): rate limits, page
 * count and size limits, and whether the form is paused. The other half -
 * proof of work and the honeypot - is not configurable; there is nothing an
 * admin should tune about them (App\Service\Submission\ProofOfWork,
 * App\Service\Submission\Spamschutz).
 *
 * Same shape as App\Service\Account\SessionTimeouts: an unusable value in
 * the database (empty, zero, negative, not a number) falls back to the
 * default instead of switching a limit off.
 */
final readonly class EinreichungsEinstellungen
{
    public const int LIMIT_IP_STUNDE_DEFAULT = 10;
    public const int LIMIT_GESAMT_STUNDE_DEFAULT = 60;
    public const int MAX_SEITEN_DEFAULT = 20;
    public const int MAX_DATEI_MB_DEFAULT = 10;
    public const int MAX_EINREICHUNG_MB_DEFAULT = 50;

    /** A page count above this is not a receipt anymore, whatever an admin types in. */
    private const int MAX_SEITEN_OBERGRENZE = 100;

    public const string SETTING_PAUSIERT = 'einreichung_pausiert';
    public const string SETTING_LIMIT_IP_STUNDE = 'einreichung_limit_ip_stunde';
    public const string SETTING_LIMIT_GESAMT_STUNDE = 'einreichung_limit_gesamt_stunde';
    public const string SETTING_MAX_SEITEN = 'einreichung_max_seiten';
    public const string SETTING_MAX_DATEI_MB = 'einreichung_max_datei_mb';
    public const string SETTING_MAX_EINREICHUNG_MB = 'einreichung_max_einreichung_mb';

    public function __construct(
        public bool $pausiert = false,
        public int $limitProIpStunde = self::LIMIT_IP_STUNDE_DEFAULT,
        public int $limitGesamtStunde = self::LIMIT_GESAMT_STUNDE_DEFAULT,
        public int $maxSeiten = self::MAX_SEITEN_DEFAULT,
        public int $maxDateiMb = self::MAX_DATEI_MB_DEFAULT,
        public int $maxEinreichungMb = self::MAX_EINREICHUNG_MB_DEFAULT,
    ) {
    }

    public static function fromSettings(SettingRepository $settings): self
    {
        return new self(
            pausiert: $settings->get(self::SETTING_PAUSIERT) === '1',
            limitProIpStunde: self::positiv($settings->get(self::SETTING_LIMIT_IP_STUNDE), self::LIMIT_IP_STUNDE_DEFAULT),
            limitGesamtStunde: self::positiv($settings->get(self::SETTING_LIMIT_GESAMT_STUNDE), self::LIMIT_GESAMT_STUNDE_DEFAULT),
            maxSeiten: min(
                self::positiv($settings->get(self::SETTING_MAX_SEITEN), self::MAX_SEITEN_DEFAULT),
                self::MAX_SEITEN_OBERGRENZE,
            ),
            maxDateiMb: self::maxDateiMbAusEinstellung($settings->get(self::SETTING_MAX_DATEI_MB)),
            maxEinreichungMb: self::positiv($settings->get(self::SETTING_MAX_EINREICHUNG_MB), self::MAX_EINREICHUNG_MB_DEFAULT),
        );
    }

    public function maxDateiBytes(): int
    {
        return $this->maxDateiMb * 1024 * 1024;
    }

    public function maxEinreichungBytes(): int
    {
        return $this->maxEinreichungMb * 1024 * 1024;
    }

    /**
     * Persists the limits (not the pause, which its own toggle owns -
     * App\Admin\SubmissionSettingsController::pausieren()/fortsetzen()).
     * Values are clamped the same way fromSettings() reads them back, so a
     * saved value and the value the next request enforces never disagree.
     */
    public function speichern(SettingRepository $settings): void
    {
        $settings->set(self::SETTING_LIMIT_IP_STUNDE, (string) max(1, $this->limitProIpStunde));
        $settings->set(self::SETTING_LIMIT_GESAMT_STUNDE, (string) max(1, $this->limitGesamtStunde));
        $settings->set(self::SETTING_MAX_SEITEN, (string) min(max(1, $this->maxSeiten), self::MAX_SEITEN_OBERGRENZE));
        $settings->set(self::SETTING_MAX_DATEI_MB, (string) self::maxDateiMbAusEinstellung((string) $this->maxDateiMb));
        $settings->set(self::SETTING_MAX_EINREICHUNG_MB, (string) max(1, $this->maxEinreichungMb));
    }

    public static function setzePausiert(SettingRepository $settings, bool $pausiert): void
    {
        $settings->set(self::SETTING_PAUSIERT, $pausiert ? '1' : '0');
    }

    /**
     * The per-file limit can never exceed what App\Service\Upload\
     * UploadService accepts at all (its MAX_FILE_BYTES bounds what an
     * unfinished upload may cost on disk) - an admin can only make the
     * public form stricter than that, never looser.
     */
    private static function maxDateiMbAusEinstellung(string $wert): int
    {
        $mb = self::positiv($wert, self::MAX_DATEI_MB_DEFAULT);
        $deckelMb = intdiv(UploadService::MAX_FILE_BYTES, 1024 * 1024);

        return min($mb, $deckelMb);
    }

    private static function positiv(string $wert, int $default): int
    {
        $zahl = filter_var($wert, FILTER_VALIDATE_INT);

        return is_int($zahl) && $zahl > 0 ? $zahl : $default;
    }
}
