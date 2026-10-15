<?php

declare(strict_types=1);

namespace App\Service\Archiv;

use App\Service\Export\PfadMuster;

/**
 * The file name pattern of the archive import (issue #77/M12-3,
 * docs/spec/05-auswertung-und-export.md section 3): which part of a name
 * like "Stadtwerke Musterstadt 15.01.2026 (2).pdf" is the supplier and
 * which the date. The default `{lieferant} {datum}` is the club's old
 * manual archive and the file level of every bundled export pattern
 * (App\Service\Export\PfadMuster), so an export can be imported again.
 *
 * Placeholders, each at most once:
 * - `{lieferant}` any text - the supplier or payer;
 * - `{datum}` T.M.JJJJ or TT.MM.JJJJ; `{datum_iso}` JJJJ-MM-TT (not both);
 * - `{nr}` any text - the invoice number;
 * - `{egal}` any text that is ignored (may appear more than once).
 *
 * Matching is forgiving where archives differ in habit, never where it
 * would mean guessing:
 * - the extension does not count (.pdf, .PDF, .jpg …), nor does a
 *   collision suffix " (2)" / "(3)" before it - our own export and Windows
 *   write those;
 * - a space in the pattern stands for any run of spaces and underscores,
 *   and underscores inside a supplier name become spaces;
 * - an impossible date (31.02.2026) or a year outside 1990 to next year
 *   gives no date, but a hint; the supplier is still taken;
 * - a name that does not fit the pattern gives nothing at all.
 * "_Ohne Lieferant" (what the export writes for a receipt without one)
 * means: no supplier.
 *
 * Pure: no database, no session, no vault.
 */
final readonly class DateinamenMuster
{
    public const string STANDARD = '{lieferant} {datum}';

    public const int MAX_LAENGE = 300;

    /** Placeholder => what the import page says about it. */
    public const array PLATZHALTER = [
        'lieferant' => 'Lieferant bzw. Zahler',
        'datum' => 'Belegdatum TT.MM.JJJJ (auch T.M.JJJJ)',
        'datum_iso' => 'Belegdatum JJJJ-MM-TT',
        'nr' => 'Rechnungsnummer',
        'egal' => 'beliebiger Text, wird ignoriert',
    ];

    public const int ERSTES_JAHR = 1990;

    private const array AUSDRUCK = [
        'lieferant' => '(?<lieferant>.+?)',
        'datum' => '(?<datum>\d{1,2}\.\d{1,2}\.\d{4})',
        'datum_iso' => '(?<datum_iso>\d{4}-\d{2}-\d{2})',
        'nr' => '(?<nr>.+?)',
        'egal' => '.*?',
    ];

    private function __construct(
        public string $muster,
        private string $regex,
    ) {
    }

    public static function standard(): self
    {
        return self::parse(self::STANDARD);
    }

    /**
     * @throws UngueltigesDateinamenMuster with a German message for the import page
     */
    public static function parse(string $muster): self
    {
        $muster = trim($muster);
        if (!mb_check_encoding($muster, 'UTF-8')) {
            throw new UngueltigesDateinamenMuster('Das Muster enthält ungültige Zeichen.');
        }
        if ($muster === '') {
            throw new UngueltigesDateinamenMuster('Bitte ein Muster angeben.');
        }
        if (mb_strlen($muster) > self::MAX_LAENGE) {
            throw new UngueltigesDateinamenMuster(sprintf('Das Muster ist zu lang (höchstens %d Zeichen).', self::MAX_LAENGE));
        }
        if (str_contains($muster, '/') || str_contains($muster, '\\')) {
            throw new UngueltigesDateinamenMuster('Das Muster beschreibt nur den Dateinamen – Ordner werden für sich ausgewertet, bitte ohne „/“.');
        }
        // A written-out extension is decoration, as in the export pattern.
        $muster = (string) preg_replace('/\.pdf$/i', '', $muster);

        $regex = '';
        $gesehen = [];
        $vorherPlatzhalter = false;
        foreach (preg_split('/(\{[^{}]*\})/', $muster, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $teil) {
            if (preg_match('/^\{([^{}]*)\}$/', $teil, $treffer) === 1) {
                $name = trim($treffer[1]);
                if (!array_key_exists($name, self::PLATZHALTER)) {
                    throw new UngueltigesDateinamenMuster(sprintf('Unbekannter Platzhalter „{%s}“.', $treffer[1]));
                }
                if ($name !== 'egal' && isset($gesehen[$name])) {
                    throw new UngueltigesDateinamenMuster(sprintf('Der Platzhalter „{%s}“ darf nur einmal vorkommen.', $name));
                }
                if ($vorherPlatzhalter) {
                    throw new UngueltigesDateinamenMuster('Zwischen zwei Platzhaltern muss ein Trennzeichen stehen, z. B. ein Leerzeichen.');
                }
                $gesehen[$name] = true;
                $regex .= self::AUSDRUCK[$name];
                $vorherPlatzhalter = true;

                continue;
            }

            if (str_contains($teil, '{') || str_contains($teil, '}')) {
                throw new UngueltigesDateinamenMuster('Geschweifte Klammern sind nur um Platzhalter erlaubt, z. B. „{datum}“.');
            }
            foreach (preg_split('/(\s+)/u', $teil, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $stueck) {
                $regex .= trim($stueck) === '' ? '[\s_]+' : preg_quote($stueck, '/');
            }
            $vorherPlatzhalter = false;
        }

        if (array_diff_key($gesehen, ['egal' => true]) === []) {
            throw new UngueltigesDateinamenMuster('Das Muster braucht mindestens einen Platzhalter, z. B. „{lieferant} {datum}“.');
        }
        if (isset($gesehen['datum'], $gesehen['datum_iso'])) {
            throw new UngueltigesDateinamenMuster('Bitte entweder „{datum}“ oder „{datum_iso}“ verwenden, nicht beide.');
        }

        return new self($muster, '/^[\s_]*' . $regex . '(?:[\s_]*\(\d{1,4}\))?[\s_]*$/u');
    }

    /**
     * Reads one file name (with or without extension).
     *
     * @param int $bisJahr the latest plausible year - next year, from the caller's clock
     * @return DateinamenTreffer|null null when the name does not fit the pattern
     */
    public function lies(string $dateiname, int $bisJahr): ?DateinamenTreffer
    {
        $name = (string) preg_replace('/\.(?=[0-9]*[a-z])[a-z0-9]{2,5}$/i', '', trim($dateiname));
        if (preg_match($this->regex, $name, $t) !== 1) {
            return null;
        }

        $hinweise = [];
        $lieferant = self::text($t['lieferant'] ?? '');
        $ohneLieferant = false;
        if ($lieferant !== null && mb_strtolower(ltrim($lieferant, '_ ')) === mb_strtolower(ltrim(PfadMuster::OHNE_LIEFERANT, '_'))) {
            $lieferant = null;
            $ohneLieferant = true;
        }
        if ($lieferant !== null) {
            $lieferant = trim(str_replace('_', ' ', $lieferant));
            $lieferant = (string) preg_replace(['/\s+/u', '/[\s\-–,;]+$/u'], [' ', ''], $lieferant);
            $lieferant = $lieferant === '' ? null : $lieferant;
        }

        $datum = null;
        $roh = ($t['datum'] ?? '') !== '' ? $t['datum'] : ($t['datum_iso'] ?? '');
        if ($roh !== '') {
            [$jahr, $monat, $tag] = ($t['datum'] ?? '') !== ''
                ? array_reverse(array_map(intval(...), explode('.', $roh)))
                : array_map(intval(...), explode('-', $roh));
            if (checkdate($monat, $tag, $jahr) && $jahr >= self::ERSTES_JAHR && $jahr <= $bisJahr) {
                $datum = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $jahr, $monat, $tag));
            } else {
                $hinweise[] = sprintf('Das Datum „%s“ im Dateinamen ist ungültig – kein Datum vorbelegt.', $roh);
            }
        }

        return new DateinamenTreffer(
            lieferant: $lieferant,
            datum: $datum,
            nr: self::text($t['nr'] ?? ''),
            ohneLieferant: $ohneLieferant,
            hinweise: $hinweise,
        );
    }

    private static function text(string $wert): ?string
    {
        $wert = trim((string) preg_replace('/\s+/u', ' ', $wert));

        return $wert === '' ? null : $wert;
    }
}
