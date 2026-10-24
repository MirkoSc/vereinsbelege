<?php

declare(strict_types=1);

namespace App\Service\MasterData;

/**
 * How alike two supplier names are - step 5 of the supplier resolution
 * (issue #48/M7-6, docs/spec/03-erfassung-und-ki.md section 7: "Ähnlichkeit
 * (Trigramm/Levenshtein) … ≥ Schwelle → Vorschlag, nicht automatisch").
 *
 * Both names come normalised by SupplierKeys::name() (lower case, umlauts
 * spelled out, legal form at the end removed), so "Getränke Müller GmbH"
 * and "Getraenke Muller" differ by one letter, not by case and legal form.
 * The value is the better of two measures, each from 0 to 1:
 *
 * - Levenshtein: 1 - edits / length of the longer name - catches typos and
 *   a misread letter ("mueller" / "muller").
 * - Trigrams (Dice coefficient over the sets of three-letter pieces, the
 *   name padded with spaces): catches words swapped or one added
 *   ("sporthaus nord" / "nord sporthaus").
 *
 * Names shorter than MIN_LAENGE letters are only ever alike when equal:
 * "obi" and "abi" are two different shops, not a typo.
 *
 * Pure functions, multibyte-safe; no vault, no database.
 */
final class LieferantenAehnlichkeit
{
    /**
     * From here on a similar name is offered as a suggestion (step 5) -
     * still never chosen without a person confirming it.
     */
    public const float SCHWELLE = 0.8;

    /** Shorter normalised names are compared for equality only. */
    public const int MIN_LAENGE = 4;

    private function __construct()
    {
        // Static utility, no instances.
    }

    /**
     * @param string $a a name normalised by SupplierKeys::name()
     * @param string $b another one
     */
    public static function wert(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }
        $zeichenA = mb_str_split($a);
        $zeichenB = mb_str_split($b);
        if (min(count($zeichenA), count($zeichenB)) < self::MIN_LAENGE) {
            return 0.0;
        }

        return max(self::levenshtein($zeichenA, $zeichenB), self::trigramme($zeichenA, $zeichenB));
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function levenshtein(array $a, array $b): float
    {
        $laengeB = count($b);
        $vorher = range(0, $laengeB);
        foreach ($a as $i => $zeichen) {
            $jetzt = [$i + 1];
            foreach ($b as $j => $anderes) {
                $jetzt[] = min(
                    $vorher[$j + 1] + 1,
                    $jetzt[$j] + 1,
                    $vorher[$j] + ($zeichen === $anderes ? 0 : 1),
                );
            }
            $vorher = $jetzt;
        }

        return 1.0 - $vorher[$laengeB] / max(count($a), $laengeB);
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function trigramme(array $a, array $b): float
    {
        $mengeA = self::trigrammMenge($a);
        $mengeB = self::trigrammMenge($b);

        return 2 * count(array_intersect_key($mengeA, $mengeB)) / (count($mengeA) + count($mengeB));
    }

    /**
     * @param list<string> $zeichen
     *
     * @return array<string, true>
     */
    private static function trigrammMenge(array $zeichen): array
    {
        $gepolstert = [' ', ' ', ...$zeichen, ' '];
        $menge = [];
        for ($i = 0, $n = count($gepolstert) - 2; $i < $n; $i++) {
            $menge[$gepolstert[$i] . $gepolstert[$i + 1] . $gepolstert[$i + 2]] = true;
        }

        return $menge;
    }
}
