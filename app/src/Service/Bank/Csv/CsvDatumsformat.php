<?php

declare(strict_types=1);

namespace App\Service\Bank\Csv;

/**
 * Date notation of a CSV export. The dotted and the slashed form take a
 * two-digit year as well as a four-digit one ("31.12.24" and "31.12.2024"):
 * banks switch between them across export versions, and a two-digit year
 * is unambiguous for bank statements (00-69 = 20xx, the PHP rule).
 */
enum CsvDatumsformat: string
{
    case Punkt = 'd.m.Y';
    case Iso = 'Y-m-d';
    case Schraegstrich = 'd/m/Y';

    public function label(): string
    {
        return match ($this) {
            self::Punkt => 'TT.MM.JJJJ (auch TT.MM.JJ)',
            self::Iso => 'JJJJ-MM-TT',
            self::Schraegstrich => 'TT/MM/JJJJ (auch TT/MM/JJ)',
        };
    }

    /** Whether the text looks like a date of this notation - format only, not validity. */
    public function passt(string $text): bool
    {
        return preg_match($this->muster(), trim($text)) === 1;
    }

    /**
     * @return \DateTimeImmutable|null midnight Europe/Berlin, null when the
     *         text is no valid date of this notation ("30.02.2024")
     */
    public function parse(string $text): ?\DateTimeImmutable
    {
        if (preg_match($this->muster(), trim($text), $teile) !== 1) {
            return null;
        }
        [$tag, $monat, $jahr] = match ($this) {
            self::Iso => [(int) $teile[3], (int) $teile[2], $teile[1]],
            default => [(int) $teile[1], (int) $teile[2], $teile[3]],
        };
        $jahr = strlen($jahr) === 2 ? (int) $jahr + ((int) $jahr < 70 ? 2000 : 1900) : (int) $jahr;
        if (!checkdate($monat, $tag, $jahr)) {
            return null;
        }

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $jahr, $monat, $tag));
    }

    private function muster(): string
    {
        return match ($this) {
            self::Punkt => '/^(\d{1,2})\.(\d{1,2})\.(\d{4}|\d{2})$/',
            self::Iso => '/^(\d{4})-(\d{2})-(\d{2})$/',
            self::Schraegstrich => '/^(\d{1,2})\/(\d{1,2})\/(\d{4}|\d{2})$/',
        };
    }
}
