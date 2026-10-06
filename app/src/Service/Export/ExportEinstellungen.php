<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Repository\SettingRepository;

/**
 * The admin-configurable part of the ZIP export (issue #75/M12-1,
 * docs/spec/05-auswertung-und-export.md section 2): the path pattern. A
 * plaintext setting - the pattern names placeholders, never a value.
 *
 * Same shape as App\Service\Submission\EinreichungsEinstellungen: a value
 * in the database the export cannot use (empty, broken by hand) falls back
 * to the default instead of failing the export.
 */
final readonly class ExportEinstellungen
{
    public const string SETTING_MUSTER = 'export_pfad_muster';

    public function __construct(public PfadMuster $muster)
    {
    }

    public static function fromSettings(SettingRepository $settings): self
    {
        try {
            return new self(PfadMuster::parse($settings->get(self::SETTING_MUSTER, PfadMuster::STANDARD)));
        } catch (UngueltigesPfadMuster) {
            return new self(PfadMuster::standard());
        }
    }

    public function speichern(SettingRepository $settings): void
    {
        $settings->set(self::SETTING_MUSTER, $this->muster->muster);
    }

    /**
     * The top folder of the ZIP and, with ".zip", its download name: only
     * the period - structure, no business data, so it may show up in the
     * browser's download list and the Content-Disposition header.
     * "Belege_2026" for a whole calendar year (the business year, E-16),
     * "Belege_2026-01-01_bis_2026-03-31" otherwise.
     */
    public static function wurzelordner(\DateTimeImmutable $von, \DateTimeImmutable $bis): string
    {
        $jahr = $von->format('Y');
        if ($von->format('m-d') === '01-01' && $bis->format('m-d') === '12-31' && $bis->format('Y') === $jahr) {
            return 'Belege_' . $jahr;
        }

        return sprintf('Belege_%s_bis_%s', $von->format('Y-m-d'), $bis->format('Y-m-d'));
    }
}
