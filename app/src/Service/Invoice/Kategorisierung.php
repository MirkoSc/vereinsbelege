<?php

declare(strict_types=1);

namespace App\Service\Invoice;

use App\Domain\AssignmentRule;
use App\Domain\Belegmerkmale;
use App\Domain\KategorieHerkunft;
use App\Service\Bank\Regelabgleich;

/**
 * Which category the review page suggests for a receipt (M7-7, issue #49,
 * docs/spec/03-erfassung-und-ki.md section 8) - in a fixed order, so the
 * club's own rules always win over the AI:
 *
 * 1. the oldest active rule (`assignment_rule`, the rules of the bookings,
 *    App\Service\Bank\Regelabgleich::trifftBeleg()) that matches and sets a
 *    category,
 * 2. the supplier's default category (`supplier.default_category_id`),
 * 3. learnt: the category of the supplier's newest GELERNT_AB checked
 *    receipts, if they all share it (section 7: "ab dem 2. bestätigten
 *    Beleg mit gleicher Kategorie"),
 * 4. the AI's suggestion.
 *
 * Each candidate counts only if the category is usable for the receipt -
 * active and fitting its direction ($passt) -, otherwise the next one is
 * asked. Plain PHP, no database: the caller hands in what it read - the
 * receipts only when they are asked for.
 */
final class Kategorisierung
{
    /** Checked receipts of one supplier with the same category from which it is suggested. */
    public const int GELERNT_AB = 2;

    private function __construct()
    {
        // Static utility, no instances.
    }

    /**
     * @param list<AssignmentRule> $regeln the rules, in any order (inactive ones are skipped)
     * @param \Closure(): list<?int> $letzteKategorien categories of the supplier's newest checked receipts, newest first - called only if neither a rule nor the default decides
     * @param \Closure(int): bool $passt whether a category is usable for this receipt
     */
    public static function vorschlagen(
        array $regeln,
        Belegmerkmale $beleg,
        ?int $lieferantStandard,
        \Closure $letzteKategorien,
        ?int $kiKategorie,
        ?float $kiKonfidenz,
        \Closure $passt,
    ): ?KategorieVorschlag {
        usort($regeln, static fn(AssignmentRule $a, AssignmentRule $b): int => $a->id <=> $b->id);
        foreach ($regeln as $regel) {
            if ($regel->active && $regel->categoryId !== null && Regelabgleich::trifftBeleg($regel, $beleg) && $passt($regel->categoryId)) {
                return new KategorieVorschlag($regel->categoryId, KategorieHerkunft::Regel, $beleg->richtung, regel: $regel->label);
            }
        }

        if ($lieferantStandard !== null && $passt($lieferantStandard)) {
            return new KategorieVorschlag($lieferantStandard, KategorieHerkunft::Lieferant, $beleg->richtung);
        }

        $gelernt = self::gelernt($letzteKategorien());
        if ($gelernt !== null && $passt($gelernt)) {
            return new KategorieVorschlag($gelernt, KategorieHerkunft::Gelernt, $beleg->richtung);
        }

        if ($kiKategorie !== null && $passt($kiKategorie)) {
            return new KategorieVorschlag($kiKategorie, KategorieHerkunft::Ki, $beleg->richtung, konfidenz: $kiKonfidenz);
        }

        return null;
    }

    /**
     * The category the newest GELERNT_AB checked receipts share, or null
     * when there are fewer or they differ.
     *
     * @param list<?int> $letzteKategorien newest first
     */
    public static function gelernt(array $letzteKategorien): ?int
    {
        $letzte = array_slice($letzteKategorien, 0, self::GELERNT_AB);
        if (count($letzte) < self::GELERNT_AB || $letzte[0] === null) {
            return null;
        }

        foreach ($letzte as $kategorie) {
            if ($kategorie !== $letzte[0]) {
                return null;
            }
        }

        return $letzte[0];
    }
}
