<?php

declare(strict_types=1);

namespace App\Service\MasterData;

use App\Domain\Iban;
use App\Domain\SupplierData;

/**
 * Which ids and names of a receipt its supplier does not know yet (issue
 * #201/M7-6b, docs/spec/03-erfassung-und-ki.md section 7 "Stand M7-6b",
 * decision E-29) - a pure function, no keys, no database:
 *
 * - The values are the supplier as the AI and a read e-invoice state it
 *   (`extract-v1` `supplier`, `payment.mandate_reference`), never what a
 *   person typed into a free text field.
 * - IBAN: valid (check sum) and not among the supplier's yet.
 * - Name: becomes an alias if its normalised form (step 4, App\Service\
 *   MasterData\SupplierKeys::name()) is neither the name nor an alias yet -
 *   "SPORTHAUS BEISPIEL GmbH" teaches "Sporthaus Beispiel" nothing.
 * - Creditor id: valid and only when the supplier has none; a different one
 *   is reported, not replaced (one of the two is wrong).
 * - Mandate reference: only together with the supplier's creditor id (or
 *   the one learnt now) - without it, it says nothing about who collects.
 * - At most SupplierService::LIST_MAX per list, like the form.
 *
 * Whether an IBAN or creditor id belongs to another supplier needs the
 * blind indexes - SupplierService::lernbar() checks that on top.
 */
final class LieferantenMerkmale
{
    private function __construct()
    {
        // Static utility, no instances.
    }

    /**
     * @param array<mixed> $partner `extract-v1` `supplier`
     */
    public static function neue(SupplierData $lieferant, array $partner, string $mandat): Merkmale
    {
        $wert = static fn(string $schluessel): string => is_string($partner[$schluessel] ?? null) ? trim($partner[$schluessel]) : '';
        $hinweise = [];
        $voll = static fn(string $was): string => sprintf('Höchstens %d %s je Lieferant – nicht übernommen.', SupplierService::LIST_MAX, $was);

        $iban = Iban::normalisieren($wert('iban'));
        if ($iban === '' || !Iban::istGueltig($iban) || in_array($iban, array_map(Iban::normalisieren(...), $lieferant->ibans), true)) {
            $iban = null;
        } elseif (count($lieferant->ibans) >= SupplierService::LIST_MAX) {
            $hinweise[] = $voll('IBANs');
            $iban = null;
        }

        $alias = trim((string) preg_replace('/\s+/u', ' ', $wert('name')));
        $normal = SupplierKeys::name($alias);
        $bekannt = array_map(SupplierKeys::name(...), [$lieferant->name, ...$lieferant->aliases]);
        if ($normal === '' || in_array($normal, $bekannt, true) || mb_strlen($alias) > SupplierService::NAME_MAX) {
            $alias = null;
        } elseif (count($lieferant->aliases) >= SupplierService::LIST_MAX) {
            $hinweise[] = $voll('Aliasse');
            $alias = null;
        }

        $glaeubiger = SupplierKeys::kennung($wert('creditor_id'));
        $vorhanden = SupplierKeys::kennung($lieferant->creditorId);
        $neueGlaeubigerId = null;
        if ($glaeubiger !== '' && preg_match(SupplierService::GLAEUBIGER_ID_MUSTER, $glaeubiger) === 1) {
            if ($vorhanden === '') {
                $neueGlaeubigerId = $glaeubiger;
            } elseif ($vorhanden !== $glaeubiger) {
                $hinweise[] = sprintf('Der Beleg nennt die Gläubiger-ID %s, beim Lieferanten steht %s – nicht übernommen.', $glaeubiger, $vorhanden);
                $glaeubiger = '';
            }
        } else {
            $glaeubiger = '';
        }

        $mandat = trim($mandat);
        $neuesMandat = null;
        if ($mandat !== '' && $glaeubiger !== '' && mb_strlen($mandat) <= 35
            && !in_array(SupplierKeys::kennung($mandat), array_map(SupplierKeys::kennung(...), $lieferant->mandateRefs), true)) {
            if (count($lieferant->mandateRefs) >= SupplierService::LIST_MAX) {
                $hinweise[] = $voll('Mandatsreferenzen');
            } else {
                $neuesMandat = $mandat;
            }
        }

        return new Merkmale($iban, $alias, $neueGlaeubigerId, $neuesMandat, $hinweise);
    }
}
