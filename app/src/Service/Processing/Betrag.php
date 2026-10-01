<?php

declare(strict_types=1);

namespace App\Service\Processing;

/**
 * Money amounts as text <-> integer cents (issue #37/M6-3,
 * docs/spec/03-erfassung-und-ki.md section 6: "Beträge als Dezimal-String →
 * serverseitig in Cent geparst und geprüft"). Never float (CLAUDE.md
 * section 5).
 *
 * Framework-free like everything in App\Service\Processing: the review page
 * parses what a person typed with it, the AI extraction (M7-5) will parse
 * the model's decimal strings with the very same rules.
 *
 * What parse() accepts, at most two decimal places:
 * - German "1.234,56", "1234,56", "12,5", "1.234"
 * - English "1234.56", "1,234.56", "12.5"
 * - a sign in front ("-12,50", "−12,50") or behind ("12,50-", as on a
 *   till receipt), a currency sign or code and spaces anywhere
 * A single separator followed by exactly three digits is a thousands
 * separator when it is a dot ("1.234" = 1234,00 - German), and ambiguous
 * when it is a comma ("1,234": three decimals in German, a thousand in
 * English) - that one is refused rather than guessed.
 */
final class Betrag
{
    /** 999.999.999,99 - far above anything a club books, far below PHP_INT_MAX. */
    public const int MAX_CENT = 99_999_999_999;

    /**
     * @return int|null the cents, or null when the text is no amount
     */
    public static function parse(string $text): ?int
    {
        $text = str_replace(['€', 'EUR', 'eur'], '', $text);
        $text = preg_replace('/[\s\x{00A0}\x{202F}\x{2009}\']+/u', '', $text) ?? '';
        $text = str_replace(['−', '–'], '-', $text);

        $negativ = false;
        if (str_starts_with($text, '-') || str_starts_with($text, '+')) {
            $negativ = $text[0] === '-';
            $text = substr($text, 1);
        } elseif (str_ends_with($text, '-')) {
            $negativ = true;
            $text = substr($text, 0, -1);
        }

        if (preg_match('/^[0-9.,]+$/', $text) !== 1) {
            return null;
        }

        [$ganz, $bruch] = self::zerlege($text) ?? [null, null];
        if ($ganz === null || $bruch === null) {
            return null;
        }

        $ganz = ltrim($ganz, '0');
        if (strlen($ganz) > 9) {
            return null;
        }
        $cent = (int) $ganz * 100 + (int) str_pad($bruch, 2, '0');
        if ($cent > self::MAX_CENT) {
            return null;
        }

        return $negativ ? -$cent : $cent;
    }

    /**
     * German notation with thousands dots: 123456 -> "1.234,56",
     * -5 -> "-0,05".
     */
    public static function format(int $cent): string
    {
        $vorzeichen = $cent < 0 ? '-' : '';
        $betrag = abs($cent);

        return $vorzeichen . number_format(intdiv($betrag, 100), 0, ',', '.') . ',' . str_pad((string) ($betrag % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Whether net plus taxes add up to gross (docs/spec/03-erfassung-und-ki.md
     * section 6: "Summe Netto + Steuern ≈ Brutto, sonst Warnung"). One cent
     * of rounding is tolerated per tax line, at least one. Without a net
     * amount there is nothing to check.
     *
     * @param list<int> $steuern
     */
    public static function summePasst(?int $netto, array $steuern, int $brutto): bool
    {
        if ($netto === null) {
            return true;
        }

        return abs($netto + array_sum($steuern) - $brutto) <= max(1, count($steuern));
    }

    /**
     * A tax rate in percent, 0 to 100, at most two decimals: "19" -> "19",
     * "5,5" -> "5.5", "7,00 %" -> "7". Null when it is none.
     */
    public static function prozent(string $text): ?string
    {
        $text = preg_replace('/[\s%]+/u', '', $text) ?? '';
        if (preg_match('/^([0-9]{1,3})(?:[.,]([0-9]{1,2}))?$/', $text, $teile) !== 1) {
            return null;
        }

        $ganz = (int) $teile[1];
        $bruch = rtrim($teile[2] ?? '', '0');
        if ($ganz > 100 || ($ganz === 100 && $bruch !== '')) {
            return null;
        }

        return $bruch === '' ? (string) $ganz : $ganz . '.' . $bruch;
    }

    /**
     * Splits digits and separators into the integer and the fractional
     * part (no more than two digits), or null when the grouping is wrong.
     *
     * @return array{string, string}|null
     */
    private static function zerlege(string $text): ?array
    {
        $punkte = substr_count($text, '.');
        $kommas = substr_count($text, ',');

        if ($punkte > 0 && $kommas > 0) {
            // Both: whichever comes last separates the decimals.
            $dezimal = strrpos($text, ',') > strrpos($text, '.') ? ',' : '.';
            $tausender = $dezimal === ',' ? '.' : ',';
            if (substr_count($text, $dezimal) !== 1) {
                return null;
            }
            [$ganz, $bruch] = explode($dezimal, $text);

            return self::gruppiert($ganz, $tausender) && preg_match('/^[0-9]{1,2}$/', $bruch) === 1
                ? [str_replace($tausender, '', $ganz), $bruch]
                : null;
        }

        if ($punkte === 0 && $kommas === 0) {
            return $text === '' ? null : [$text, ''];
        }

        $zeichen = $punkte > 0 ? '.' : ',';
        if (substr_count($text, $zeichen) > 1) {
            // Only thousands separators can repeat.
            return self::gruppiert($text, $zeichen) ? [str_replace($zeichen, '', $text), ''] : null;
        }

        [$ganz, $bruch] = explode($zeichen, $text);
        if ($ganz === '') {
            $ganz = '0';
        }
        if (preg_match('/^[0-9]{1,2}$/', $bruch) === 1) {
            return [$ganz, $bruch];
        }
        if ($zeichen === '.' && strlen($bruch) === 3 && self::gruppiert($text, '.')) {
            return [$ganz . $bruch, ''];
        }

        return null;
    }

    /** "1.234.567": groups of three after a first group of one to three digits. */
    private static function gruppiert(string $ganz, string $zeichen): bool
    {
        return preg_match('/^[0-9]{1,3}(\\' . $zeichen . '[0-9]{3})*$/', $ganz) === 1;
    }
}
