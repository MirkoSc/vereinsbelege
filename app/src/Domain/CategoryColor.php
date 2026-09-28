<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The colours a category can carry (`category.color`, docs/spec/
 * 02-datenmodell.md "Kategorien").
 *
 * A fixed palette, not a free hex value: the CSP allows no inline styles
 * (CLAUDE.md section 4), so every colour needs its own class in
 * public/css/app.css - `.farbpunkt-<value>`, with a light and a dark token
 * behind it.
 */
enum CategoryColor: string
{
    case Blau = 'blau';
    case Gruen = 'gruen';
    case Tuerkis = 'tuerkis';
    case Gelb = 'gelb';
    case Orange = 'orange';
    case Rot = 'rot';
    case Lila = 'lila';
    case Grau = 'grau';

    public function label(): string
    {
        return match ($this) {
            self::Blau => 'Blau',
            self::Gruen => 'Grün',
            self::Tuerkis => 'Türkis',
            self::Gelb => 'Gelb',
            self::Orange => 'Orange',
            self::Rot => 'Rot',
            self::Lila => 'Lila',
            self::Grau => 'Grau',
        };
    }

    public function cssKlasse(): string
    {
        return 'farbpunkt-' . $this->value;
    }
}
