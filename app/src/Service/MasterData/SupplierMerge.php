<?php

declare(strict_types=1);

namespace App\Service\MasterData;

use App\Domain\Supplier;
use App\Domain\SupplierData;
use App\Domain\SupplierRole;

/**
 * What a supplier looks like after another one was merged into it (M6-5,
 * issue #39, docs/spec/03-erfassung-und-ki.md section 7) - the data only,
 * no SQL and no encryption, so the preview and the merge itself compute
 * exactly the same result.
 *
 * Nothing of the source is lost:
 *
 * - The source's name and aliases become aliases of the target; IBANs and
 *   mandate references are united.
 * - VAT id, tax number and creditor id identify one supplier: the target
 *   takes the source's if it has none; two different ones are refused -
 *   that is no duplicate, or one of them is wrong and must be corrected
 *   first.
 * - Every other single value (address, BIC, e-mail, website, customer
 *   number) is the target's; if both have one and they differ, the
 *   source's is appended to the target's notes, as are the source's notes.
 * - Different roles become `beide`; the default category is the target's,
 *   else the source's (with `beide` it always fits).
 *
 * A result that would break the limits of the form (more than LIST_MAX
 * values, notes longer than NOTES_MAX) is refused instead of being cut.
 */
final class SupplierMerge
{
    /**
     * @throws SupplierRuleViolation
     */
    public static function plane(Supplier $quelle, Supplier $ziel): SupplierMergePlan
    {
        $q = $quelle->data;
        $z = $ziel->data;

        $aliases = [];
        foreach ([...$z->aliases, $q->name, ...$q->aliases] as $alias) {
            $aliases[mb_strtolower($alias)] ??= $alias;
        }
        unset($aliases[mb_strtolower($z->name)]);
        $aliases = self::begrenzt(array_values($aliases), 'Aliasse');

        $ibans = self::begrenzt(array_values(array_unique([...$z->ibans, ...$q->ibans])), 'IBANs');

        $mandate = [];
        foreach ([...$z->mandateRefs, ...$q->mandateRefs] as $mandat) {
            $mandate[SupplierKeys::kennung($mandat)] ??= $mandat;
        }
        $mandate = self::begrenzt(array_values($mandate), 'Mandatsreferenzen');

        $kennung = static function (string $zielWert, string $quellWert, string $was, callable $norm): string {
            if ($zielWert !== '' && $quellWert !== '' && $norm($zielWert) !== $norm($quellWert)) {
                throw new SupplierRuleViolation(sprintf(
                    'Die beiden Lieferanten haben unterschiedliche %s – das spricht gegen ein Duplikat. Falls eine davon falsch ist, bitte zuerst dort korrigieren.',
                    $was,
                ));
            }

            return $zielWert !== '' ? $zielWert : $quellWert;
        };
        $ustId = $kennung($z->vatId, $q->vatId, 'USt-IDs', SupplierKeys::kennung(...));
        $steuernummer = $kennung($z->taxNumber, $q->taxNumber, 'Steuernummern', SupplierKeys::steuernummer(...));
        $glaeubigerId = $kennung($z->creditorId, $q->creditorId, 'Gläubiger-IDs', SupplierKeys::kennung(...));

        $notiz = [];
        $einzeln = static function (string $zielWert, string $quellWert, string $was) use (&$notiz): string {
            if ($zielWert === '') {
                return $quellWert;
            }
            if ($quellWert !== '' && $quellWert !== $zielWert) {
                $notiz[] = $was . ': ' . $quellWert;
            }

            return $zielWert;
        };
        $anschrift = $einzeln($z->address, $q->address, 'Anschrift');
        $bic = $einzeln($z->bic, $q->bic, 'BIC');
        $email = $einzeln($z->email, $q->email, 'E-Mail');
        $website = $einzeln($z->website, $q->website, 'Website');
        $kundennummer = $einzeln($z->customerNumber, $q->customerNumber, 'Kundennummer');

        $notizen = $z->notes;
        if ($q->notes !== '' && $q->notes !== $z->notes) {
            $notiz[] = $q->notes;
        }
        if ($notiz !== []) {
            $notizen = trim($notizen . "\n\n" . 'Übernommen von „' . $q->name . '“:' . "\n" . implode("\n", $notiz));
        }
        if (mb_strlen($notizen) > SupplierService::NOTES_MAX) {
            throw new SupplierRuleViolation(sprintf(
                'Die zusammengeführte Notiz wäre länger als %d Zeichen – bitte vorher bei einem der beiden kürzen.',
                SupplierService::NOTES_MAX,
            ));
        }

        $daten = new SupplierData(
            name: $z->name,
            aliases: $aliases,
            address: $anschrift,
            ibans: $ibans,
            bic: $bic,
            vatId: $ustId,
            taxNumber: $steuernummer,
            email: $email,
            website: $website,
            creditorId: $glaeubigerId,
            mandateRefs: $mandate,
            customerNumber: $kundennummer,
            notes: $notizen,
        );
        $rolle = $quelle->role === $ziel->role ? $ziel->role : SupplierRole::Beide;
        $kategorie = $ziel->defaultCategoryId ?? $quelle->defaultCategoryId;

        $geaendert = [];
        if ($rolle !== $ziel->role) {
            $geaendert[] = 'role';
        }
        if ($kategorie !== $ziel->defaultCategoryId) {
            $geaendert[] = 'default_category_id';
        }
        $alt = $z->toPayload();
        foreach ($daten->toPayload() as $feld => $wert) {
            if ($alt[$feld] !== $wert) {
                $geaendert[] = $feld;
            }
        }

        return new SupplierMergePlan($rolle, $kategorie, $daten, $geaendert);
    }

    /**
     * @param list<string> $werte
     *
     * @return list<string>
     *
     * @throws SupplierRuleViolation
     */
    private static function begrenzt(array $werte, string $was): array
    {
        if (count($werte) > SupplierService::LIST_MAX) {
            throw new SupplierRuleViolation(sprintf(
                'Zusammen wären es mehr als %d %s – bitte vorher bei einem der beiden aufräumen.',
                SupplierService::LIST_MAX,
                $was,
            ));
        }

        return $werte;
    }
}
