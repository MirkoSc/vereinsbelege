<?php

declare(strict_types=1);

namespace App\Service\MasterData;

use App\Domain\Iban;
use App\Domain\InvoiceDirection;
use App\Domain\SupplierKeyKind;
use App\Domain\SupplierRecord;
use App\Domain\SupplierRole;
use App\Repository\SupplierRepository;
use App\Service\Crypto\Vault;

/**
 * The automatic supplier resolution (issue #48/M7-6, docs/spec/
 * 03-erfassung-und-ki.md section 7): from the supplier a receipt names - as
 * the AI read it, an e-invoice on top - to the supplier in the club's
 * master data, in a fixed order:
 *
 *   1. VAT id or tax number, 2. IBAN, 3. creditor id - exact, by blind index
 *   4. the name, normalised (SupplierKeys::name(): case, umlauts, legal
 *      form), exact against names and aliases, by blind index
 *   5. a similar name (LieferantenAehnlichkeit, against the decrypted names
 *      and aliases) → only a suggestion
 *   6. otherwise a new supplier, `created_via = ki`, `needs_review`
 *
 * Only suppliers whose role fits the receipt's direction count (a payer is
 * no match for an expense). An id that belongs to one of the other role
 * lets the later ids still find the right one; when none does - or the
 * name belongs to one of the other role - the resolution stops without a
 * choice and without a new supplier: that would be the same partner twice.
 * The review page says so.
 *
 * Also the blind-index preselection of known suppliers for the prompt
 * (vorauswahl()). Both need the vault: the blind indexes are keyed from it.
 * Runs in the session only (07-worker.md: `resolve_supplier` "bleibt
 * Session").
 */
final readonly class SupplierAufloesung
{
    /** Names in the prompt's preselection at most (section 6). */
    public const int VORAUSWAHL_MAX = 10;

    /** Suggestions of step 5 kept at most. */
    public const int KANDIDATEN_MAX = 3;

    /** Characters per name in the prompt - a name is master data, but the AI may have created it from a public submission. */
    public const int VORAUSWAHL_NAME_MAX = 100;

    public function __construct(
        private SupplierRepository $lieferanten,
        private SupplierService $service,
    ) {
    }

    /**
     * Resolves the supplier of $extraktion (`extract-v1`: `supplier`,
     * `direction`, `payment.mandate_reference`).
     *
     * With $anlegen, step 6 creates the supplier - then this runs inside
     * the caller's transaction, and the lookups lock what they read
     * (SupplierRepository::idsWithKey()), so two receipts of the same new
     * supplier resolved at the same moment do not create it twice. Without
     * it, step 6 ends as AufloesungsArt::Ohne.
     *
     * @param array<string, mixed> $extraktion
     *
     * @throws SupplierRuleViolation step 6 met an id another request has
     *         just given to a new supplier - the caller tries again
     */
    public function aufloesen(Vault $vault, array $extraktion, bool $anlegen, \DateTimeImmutable $now): Aufloesung
    {
        $partner = is_array($extraktion['supplier'] ?? null) ? $extraktion['supplier'] : [];
        $wert = static fn(string $schluessel): string => is_string($partner[$schluessel] ?? null) ? trim($partner[$schluessel]) : '';
        $richtung = InvoiceDirection::tryFrom(is_string($extraktion['direction'] ?? null) ? $extraktion['direction'] : '') ?? InvoiceDirection::Ausgabe;
        $iban = Iban::normalisieren($wert('iban'));

        $stufen = [
            1 => [[SupplierKeyKind::VatId, SupplierKeys::kennung($wert('vat_id'))], [SupplierKeyKind::TaxNumber, SupplierKeys::steuernummer($wert('tax_number'))]],
            // The club's own account names no supplier (a direct debit, an income).
            2 => [[SupplierKeyKind::Iban, Iban::istGueltig($iban) && !$this->service->istVereinsIban($vault, $iban) ? $iban : '']],
            3 => [[SupplierKeyKind::CreditorId, SupplierKeys::kennung($wert('creditor_id'))]],
            4 => [[SupplierKeyKind::Name, SupplierKeys::name($wert('name'))]],
        ];
        $index = $vault->blindIndex();
        // An id of a partner of the other role does not stop the ids that
        // follow: the right supplier may carry another one (step 1-3). Only
        // when none does, the resolution ends there - before the name.
        $anderRolle = null;
        foreach ($stufen as $stufe => $kennungen) {
            if ($stufe === 4 && $anderRolle !== null) {
                return $anderRolle;
            }
            foreach ($kennungen as [$art, $kennung]) {
                if ($kennung === '') {
                    continue;
                }
                $ids = $this->lieferanten->idsWithKey($art, $index->forValue($art->purpose(), $kennung), sperren: $anlegen);
                if ($ids === []) {
                    continue;
                }
                $passend = array_values(array_filter($ids, fn(int $id): bool => $this->passt($this->lieferanten->find($id), $richtung)));

                switch (count($passend)) {
                    case 0:
                        $anderRolle ??= new Aufloesung(AufloesungsArt::RollePasstNicht, $stufe, $art, $ids[0]);
                        if ($stufe === 4) {
                            return $anderRolle;
                        }
                        break;
                    case 1:
                        return new Aufloesung(AufloesungsArt::Treffer, $stufe, $art, $passend[0]);
                    default:
                        return new Aufloesung(
                            AufloesungsArt::Mehrdeutig,
                            $stufe,
                            $art,
                            null,
                            array_map(static fn(int $id): array => ['id' => $id, 'aehnlichkeit' => 1.0], $passend),
                        );
                }
            }
        }
        if ($anderRolle !== null) {
            return $anderRolle;
        }

        $name = SupplierKeys::name($wert('name'));
        if ($name === '') {
            return new Aufloesung(AufloesungsArt::Ohne, 0);
        }

        $kandidaten = $this->aehnliche($vault, $name, $richtung);
        if ($kandidaten !== []) {
            return new Aufloesung(AufloesungsArt::Vorschlag, 5, null, $kandidaten[0]['id'], $kandidaten);
        }

        if (!$anlegen) {
            return new Aufloesung(AufloesungsArt::Ohne, 6);
        }

        $mandat = is_array($extraktion['payment'] ?? null) && is_string($extraktion['payment']['mandate_reference'] ?? null)
            ? trim($extraktion['payment']['mandate_reference'])
            : '';
        $angelegt = $this->service->anlegenAusKi($vault, [
            'name' => $wert('name'),
            'address' => $wert('address'),
            'ibans' => $iban,
            'bic' => $wert('bic'),
            'vat_id' => $wert('vat_id'),
            'tax_number' => $wert('tax_number'),
            'email' => $wert('email'),
            'website' => $wert('website'),
            'creditor_id' => $wert('creditor_id'),
            // A mandate reference means something only next to its creditor.
            'mandate_refs' => $wert('creditor_id') === '' ? '' : $mandat,
        ], $richtung->neueLieferantenRolle(), $now);

        return new Aufloesung(AufloesungsArt::Angelegt, 6, null, $angelegt->id, [], $angelegt->geaenderteFelder);
    }

    /**
     * The known suppliers a receipt's text hints at, for the prompt
     * (section 6: "bekannte Lieferanten nur als Top-Kandidaten (per
     * Blind-Index-Vorauswahl, max. 10 Namen) – nie die komplette
     * Lieferantenliste"): every id and every run of words in $texte looked
     * up by blind index (LieferantenKandidaten), plus the ids and name of an
     * e-invoice's seller. Suppliers found by an id come first, then by
     * where their name appears. Only partners of the role `lieferant` or
     * `beide` - a payer may be a private person. Their names, cut to
     * VORAUSWAHL_NAME_MAX -
     * empty when nothing matched or there was no text (a photo).
     *
     * @param list<string> $texte the text layer, the submitter's free text
     * @param array<mixed> $partner `extract-v1` `supplier` of a read e-invoice, or []
     *
     * @return list<string>
     */
    public function vorauswahl(Vault $vault, array $texte, array $partner): array
    {
        $wert = static fn(string $schluessel): string => is_string($partner[$schluessel] ?? null) ? $partner[$schluessel] : '';
        $schluessel = [
            [SupplierKeyKind::VatId, SupplierKeys::kennung($wert('vat_id'))],
            [SupplierKeyKind::TaxNumber, SupplierKeys::steuernummer($wert('tax_number'))],
            [SupplierKeyKind::Iban, Iban::normalisieren($wert('iban'))],
            [SupplierKeyKind::CreditorId, SupplierKeys::kennung($wert('creditor_id'))],
            [SupplierKeyKind::Name, SupplierKeys::name($wert('name'))],
        ];
        foreach ($texte as $text) {
            array_push($schluessel, ...LieferantenKandidaten::aus($text));
        }

        $index = $vault->blindIndex();
        $ids = [];
        $namen = [];
        foreach ($schluessel as [$art, $kennung]) {
            if ($kennung !== '') {
                $ids[] = $index->forValue($art->purpose(), $kennung);
                $namen[] = $art === SupplierKeyKind::Name;
            }
        }
        $treffer = $this->lieferanten->idsMitSchluesseln($ids);

        // Ids first, then names - each list in the order of the text.
        $reihenfolge = [];
        foreach ([false, true] as $alsName) {
            foreach ($ids as $i => $bi) {
                if ($namen[$i] === $alsName) {
                    foreach ($treffer[$bi] ?? [] as $id) {
                        $reihenfolge[$id] = true;
                    }
                }
            }
        }

        $ergebnis = [];
        foreach (array_keys($reihenfolge) as $id) {
            if (count($ergebnis) >= self::VORAUSWAHL_MAX) {
                break;
            }
            $lieferant = $this->service->finde($vault, $id);
            // Suppliers only: a payer may be a private person, whose name
            // does not go to the AI provider as master data (data economy).
            if ($lieferant !== null && $lieferant->mergedInto === null && $lieferant->role !== SupplierRole::Zahler) {
                $ergebnis[] = mb_substr($lieferant->data->name, 0, self::VORAUSWAHL_NAME_MAX);
            }
        }

        return $ergebnis;
    }

    /**
     * Step 5: the suppliers of the role whose name or an alias is at least
     * LieferantenAehnlichkeit::SCHWELLE alike, best first.
     *
     * @return list<array{id: int, aehnlichkeit: float}>
     */
    private function aehnliche(Vault $vault, string $name, InvoiceDirection $richtung): array
    {
        $kandidaten = [];
        foreach ($this->service->liste($vault) as $lieferant) {
            if (!$richtung->passtZuLieferant($lieferant->role)) {
                continue;
            }
            $bester = max(array_map(
                static fn(string $vorhanden): float => LieferantenAehnlichkeit::wert($name, SupplierKeys::name($vorhanden)),
                [$lieferant->data->name, ...$lieferant->data->aliases],
            ));
            if ($bester >= LieferantenAehnlichkeit::SCHWELLE) {
                $kandidaten[] = ['id' => $lieferant->id, 'aehnlichkeit' => round($bester, 3)];
            }
        }
        usort($kandidaten, static fn(array $a, array $b): int => $b['aehnlichkeit'] <=> $a['aehnlichkeit'] ?: $a['id'] <=> $b['id']);

        return array_slice($kandidaten, 0, self::KANDIDATEN_MAX);
    }

    private function passt(?SupplierRecord $record, InvoiceDirection $richtung): bool
    {
        return $record !== null && $record->mergedInto === null && $richtung->passtZuLieferant($record->role);
    }
}
