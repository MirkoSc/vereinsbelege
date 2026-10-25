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
 * - VAT id, tax number (issue #245): like the creditor id - valid and only
 *   when the supplier has none; a different one is reported, not replaced
 *   (more likely a misread than a second id). A VAT id follows the form's
 *   rule; a tax number is learnt only as a German one (10 to 13 digits once
 *   slashes and spaces are gone) - a label, a fragment or anything else the
 *   AI put there would become a unique step-1 key.
 *   (The form keeps accepting any tax number a person types.)
 * - Mandate reference: only together with the supplier's creditor id (or
 *   the one learnt now) - without it, it says nothing about who collects.
 * - At most SupplierService::LIST_MAX per list, like the form.
 *
 * Whether an IBAN, creditor id, VAT id, tax number or the name belongs to another supplier
 * needs the blind indexes - SupplierService::lernbar() checks that on top.
 */
final class LieferantenMerkmale
{
    /** A German tax number after SupplierKeys::steuernummer(). */
    private const string STEUERNUMMER_MUSTER = '/^[0-9]{10,13}$/';

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
        if ($normal === '' || in_array($normal, $bekannt, true)) {
            $alias = null;
        } elseif (mb_strlen($alias) > SupplierService::NAME_MAX) {
            $hinweise[] = sprintf('Der Name des Belegs ist länger als %d Zeichen – nicht als Alias übernommen.', SupplierService::NAME_MAX);
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
        if ($mandat !== '' && $glaeubiger !== ''
            && !in_array(SupplierKeys::kennung($mandat), array_map(SupplierKeys::kennung(...), $lieferant->mandateRefs), true)) {
            if (mb_strlen($mandat) > SupplierService::MANDAT_MAX) {
                $hinweise[] = sprintf('Die Mandatsreferenz des Belegs ist länger als %d Zeichen – nicht übernommen.', SupplierService::MANDAT_MAX);
            } elseif (count($lieferant->mandateRefs) >= SupplierService::LIST_MAX) {
                $hinweise[] = $voll('Mandatsreferenzen');
            } else {
                $neuesMandat = $mandat;
            }
        }

        $ustId = SupplierKeys::kennung($wert('vat_id'));
        $neueUstId = self::einmalig(
            preg_match(SupplierService::USTID_MUSTER, $ustId) === 1 ? $ustId : '',
            $lieferant->vatId,
            SupplierKeys::kennung(...),
            'die USt-ID',
            $hinweise,
        );

        $steuernummer = trim((string) preg_replace('/\s+/u', ' ', $wert('tax_number')));
        $neueSteuernummer = self::einmalig(
            mb_strlen($steuernummer) <= SupplierService::TEXT_MAX && preg_match(self::STEUERNUMMER_MUSTER, SupplierKeys::steuernummer($steuernummer)) === 1 ? $steuernummer : '',
            $lieferant->taxNumber,
            SupplierKeys::steuernummer(...),
            'die Steuernummer',
            $hinweise,
        );

        return new Merkmale($iban, $alias, $neueGlaeubigerId, $neuesMandat, $hinweise, vatId: $neueUstId, taxNumber: $neueSteuernummer);
    }

    /**
     * A single id the supplier may have only one of: $neu ('' = none or
     * invalid) when the supplier has none, null otherwise - with a hint
     * when the two differ ($normal: how its key compares them).
     *
     * @param \Closure(string): string $normal
     * @param list<string> $hinweise
     */
    private static function einmalig(string $neu, string $vorhanden, \Closure $normal, string $was, array &$hinweise): ?string
    {
        if ($neu === '') {
            return null;
        }
        if ($normal($vorhanden) === '') {
            return $neu;
        }
        if ($normal($vorhanden) !== $normal($neu)) {
            $hinweise[] = sprintf('Der Beleg nennt %s %s, beim Lieferanten steht %s – nicht übernommen.', $was, $neu, $vorhanden);
        }

        return null;
    }
}
