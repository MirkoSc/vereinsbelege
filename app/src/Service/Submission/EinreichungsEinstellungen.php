<?php

declare(strict_types=1);

namespace App\Service\Submission;

use App\Repository\SettingRepository;
use App\Service\Upload\UploadService;

/**
 * The admin-configurable half of the public submission's spam defence
 * (docs/spec/01-sicherheit.md section 5, issue #25/M4-3): rate limits, page
 * count and size limits, the byte budget and free-space threshold (issue
 * #242), and whether the form is paused. The other half -
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

    /**
     * Byte budget of the public upload (issue #242, security review N-04):
     * per IP address (IPv6: per /64) and hour - two submissions at the
     * per-submission maximum -, for everybody together per calendar day,
     * and the free space below which public uploads pause.
     */
    public const int BUDGET_IP_MB_STUNDE_DEFAULT = 100;
    public const int BUDGET_GESAMT_MB_TAG_DEFAULT = 500;
    /** Announced but unfinished uploads per address: three full files. */
    public const int OFFEN_MB_IP_DEFAULT = 30;
    public const int MIN_FREI_MB_DEFAULT = 1024;

    /** A page count above this is not a receipt anymore, whatever an admin types in. */
    private const int MAX_SEITEN_OBERGRENZE = 100;

    public const string SETTING_PAUSIERT = 'einreichung_pausiert';
    public const string SETTING_LIMIT_IP_STUNDE = 'einreichung_limit_ip_stunde';
    public const string SETTING_LIMIT_GESAMT_STUNDE = 'einreichung_limit_gesamt_stunde';
    public const string SETTING_MAX_SEITEN = 'einreichung_max_seiten';
    public const string SETTING_MAX_DATEI_MB = 'einreichung_max_datei_mb';
    public const string SETTING_MAX_EINREICHUNG_MB = 'einreichung_max_einreichung_mb';
    public const string SETTING_BUDGET_IP_MB_STUNDE = 'einreichung_budget_ip_mb_stunde';
    public const string SETTING_BUDGET_GESAMT_MB_TAG = 'einreichung_budget_gesamt_mb_tag';
    public const string SETTING_MIN_FREI_MB = 'einreichung_min_frei_mb';
    public const string SETTING_OFFEN_MB_IP = 'einreichung_offen_mb_ip';

    private const int MIB = 1024 * 1024;

    public function __construct(
        public bool $pausiert = false,
        public int $limitProIpStunde = self::LIMIT_IP_STUNDE_DEFAULT,
        public int $limitGesamtStunde = self::LIMIT_GESAMT_STUNDE_DEFAULT,
        public int $maxSeiten = self::MAX_SEITEN_DEFAULT,
        public int $maxDateiMb = self::MAX_DATEI_MB_DEFAULT,
        public int $maxEinreichungMb = self::MAX_EINREICHUNG_MB_DEFAULT,
        public int $budgetIpMbStunde = self::BUDGET_IP_MB_STUNDE_DEFAULT,
        public int $budgetGesamtMbTag = self::BUDGET_GESAMT_MB_TAG_DEFAULT,
        public int $minFreiMb = self::MIN_FREI_MB_DEFAULT,
        public int $offenMbIp = self::OFFEN_MB_IP_DEFAULT,
    ) {
    }

    public static function fromSettings(SettingRepository $settings): self
    {
        $maxDateiMb = self::maxDateiMbAusEinstellung($settings->get(self::SETTING_MAX_DATEI_MB));
        $budgetIp = self::budgetIpMb(self::positiv($settings->get(self::SETTING_BUDGET_IP_MB_STUNDE), self::BUDGET_IP_MB_STUNDE_DEFAULT), $maxDateiMb);

        return new self(
            pausiert: $settings->get(self::SETTING_PAUSIERT) === '1',
            limitProIpStunde: self::positiv($settings->get(self::SETTING_LIMIT_IP_STUNDE), self::LIMIT_IP_STUNDE_DEFAULT),
            limitGesamtStunde: self::positiv($settings->get(self::SETTING_LIMIT_GESAMT_STUNDE), self::LIMIT_GESAMT_STUNDE_DEFAULT),
            maxSeiten: min(
                self::positiv($settings->get(self::SETTING_MAX_SEITEN), self::MAX_SEITEN_DEFAULT),
                self::MAX_SEITEN_OBERGRENZE,
            ),
            maxDateiMb: $maxDateiMb,
            maxEinreichungMb: self::positiv($settings->get(self::SETTING_MAX_EINREICHUNG_MB), self::MAX_EINREICHUNG_MB_DEFAULT),
            budgetIpMbStunde: $budgetIp,
            budgetGesamtMbTag: max($budgetIp, self::positiv($settings->get(self::SETTING_BUDGET_GESAMT_MB_TAG), self::BUDGET_GESAMT_MB_TAG_DEFAULT)),
            minFreiMb: self::positiv($settings->get(self::SETTING_MIN_FREI_MB), self::MIN_FREI_MB_DEFAULT),
            offenMbIp: self::budgetIpMb(self::positiv($settings->get(self::SETTING_OFFEN_MB_IP), self::OFFEN_MB_IP_DEFAULT), $maxDateiMb),
        );
    }

    public function maxDateiBytes(): int
    {
        return $this->maxDateiMb * self::MIB;
    }

    public function maxEinreichungBytes(): int
    {
        return $this->maxEinreichungMb * self::MIB;
    }

    /** Never below one file: a budget smaller than a page would refuse every page. */
    public function budgetIpBytes(): int
    {
        return self::budgetIpMb($this->budgetIpMbStunde, $this->maxDateiMb) * self::MIB;
    }

    /** Never below the per-IP budget, for the same reason. */
    public function budgetGesamtBytes(): int
    {
        return max($this->budgetGesamtMbTag * self::MIB, $this->budgetIpBytes());
    }

    /** Never below one file, for the same reason as budgetIpBytes(). */
    public function offenIpBytes(): int
    {
        return self::budgetIpMb($this->offenMbIp, $this->maxDateiMb) * self::MIB;
    }

    public function minFreiBytes(): int
    {
        return $this->minFreiMb * self::MIB;
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
        $maxDateiMb = self::maxDateiMbAusEinstellung((string) $this->maxDateiMb);
        $settings->set(self::SETTING_MAX_DATEI_MB, (string) $maxDateiMb);
        $settings->set(self::SETTING_MAX_EINREICHUNG_MB, (string) max(1, $this->maxEinreichungMb));
        $budgetIp = self::budgetIpMb($this->budgetIpMbStunde, $maxDateiMb);
        $settings->set(self::SETTING_BUDGET_IP_MB_STUNDE, (string) $budgetIp);
        $settings->set(self::SETTING_BUDGET_GESAMT_MB_TAG, (string) max($budgetIp, $this->budgetGesamtMbTag));
        $settings->set(self::SETTING_MIN_FREI_MB, (string) max(1, $this->minFreiMb));
        $settings->set(self::SETTING_OFFEN_MB_IP, (string) self::budgetIpMb($this->offenMbIp, $maxDateiMb));
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

    private static function budgetIpMb(int $mb, int $maxDateiMb): int
    {
        return max($mb, $maxDateiMb);
    }

    private static function positiv(string $wert, int $default): int
    {
        $zahl = filter_var($wert, FILTER_VALIDATE_INT);

        return is_int($zahl) && $zahl > 0 ? $zahl : $default;
    }
}
