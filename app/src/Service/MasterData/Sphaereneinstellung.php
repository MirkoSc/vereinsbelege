<?php

declare(strict_types=1);

namespace App\Service\MasterData;

use App\Repository\SettingRepository;

/**
 * The switch `sphaeren_aktiv` (E-15, issue #84, E-57): off unless the
 * setting says '1'. Every place that shows, asks for or reports the tax
 * spheres asks here first (CLAUDE.md section 5) - with the switch off the
 * application looks and behaves exactly as without spheres. Read on every
 * use, never cached: switching takes effect with the next request.
 */
final readonly class Sphaereneinstellung
{
    public const string SETTING = 'sphaeren_aktiv';

    public function __construct(private SettingRepository $settings)
    {
    }

    public function aktiv(): bool
    {
        return $this->settings->get(self::SETTING, '0') === '1';
    }

    public function setzen(bool $aktiv): void
    {
        $this->settings->set(self::SETTING, $aktiv ? '1' : '0');
    }
}
