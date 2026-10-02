<?php

declare(strict_types=1);

namespace App\Service\Bank\Csv;

/**
 * Number notation of a CSV export: German "1.234,56" or English
 * "1,234.56". The other character is the thousands separator and is
 * dropped. Never float (CLAUDE.md section 5): the text goes straight to
 * integer cents.
 */
enum CsvDezimaltrenner: string
{
    case Komma = ',';
    case Punkt = '.';

    /** 999.999.999,99 - the same bound as App\Service\Processing\Betrag. */
    private const int MAX_CENT = 99_999_999_999;

    public function label(): string
    {
        return match ($this) {
            self::Komma => 'Komma (1.234,56)',
            self::Punkt => 'Punkt (1,234.56)',
        };
    }

    public function tausender(): string
    {
        return $this === self::Komma ? '.' : ',';
    }

    /**
     * Whether the text is written in this notation with decimals - what
     * the format detection counts. "12,50" is German, "12.50" English;
     * "1.234" (no decimals) proves nothing and does not count.
     */
    public function passt(string $text): bool
    {
        $d = preg_quote($this->value, '/');
        $t = preg_quote($this->tausender(), '/');

        return preg_match('/^[+-]?(\d{1,3}(' . $t . '\d{3})+|\d+)' . $d . '\d{1,2}-?$/', self::ohneWaehrung($text)) === 1;
    }

    /**
     * @return int|null signed cents; null when the text is no amount in this
     *         notation (more than two decimals, letters, the wrong
     *         separator after the decimal one). Empty text is null too - the
     *         caller decides whether an empty cell is allowed.
     */
    public function cent(string $text): ?int
    {
        $text = self::ohneWaehrung($text);
        $text = str_replace(['−', '–'], '-', $text);

        $negativ = false;
        if (str_starts_with($text, '-') || str_starts_with($text, '+')) {
            $negativ = $text[0] === '-';
            $text = substr($text, 1);
        } elseif (str_ends_with($text, '-')) {
            $negativ = true;
            $text = substr($text, 0, -1);
        }

        $d = preg_quote($this->value, '/');
        $t = preg_quote($this->tausender(), '/');
        if (preg_match('/^(\d{1,3}(?:' . $t . '\d{3})+|\d+)(?:' . $d . '(\d{1,2}))?$/', $text, $teile) !== 1) {
            return null;
        }
        $ganz = ltrim(str_replace($this->tausender(), '', $teile[1]), '0');
        if (strlen($ganz) > 9) {
            return null;
        }
        $cent = (int) $ganz * 100 + (int) str_pad($teile[2] ?? '', 2, '0');
        if ($cent > self::MAX_CENT) {
            return null;
        }

        return $negativ ? -$cent : $cent;
    }

    private static function ohneWaehrung(string $text): string
    {
        return (string) preg_replace('/\s+|\x{00A0}|\x{202F}|€|EUR$/u', '', trim($text));
    }
}
