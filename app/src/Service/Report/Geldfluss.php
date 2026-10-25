<?php

declare(strict_types=1);

namespace App\Service\Report;

use App\Domain\InvoiceDirection;
use App\Domain\InvoiceRecord;
use App\Domain\Zugriffsbereich;
use App\Repository\BankTransactionRepository;
use App\Repository\CategoryRepository;
use App\Repository\CostCenterRepository;
use App\Repository\InvoiceRepository;
use App\Service\Bank\Buchungen;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\Invoice\Pruefung;
use App\Service\MasterData\SupplierService;

/**
 * "Wohin fließt das Geld" (issue #73, docs/spec/05-auswertung-und-export.md
 * section 1, E-51): the expenses (or the income) of a period by category and
 * by cost center, and the drilldown from one group to its single lines.
 *
 * The figures come from the ReportService on the input of the
 * Auswertungsquelle (the session cache in production), already narrowed to
 * the reader's Zugriffsbereich; this class only adds the plaintext names of
 * categories and cost centers and, for the drilldown, what identifies a
 * line to a human (supplier, invoice number, booking counterparty). Those
 * are vault data and only ever leave here inside the rendered page.
 */
final readonly class Geldfluss
{
    /** What the page may group by - the issue's two views. */
    public const array DIMENSIONEN = [Dimension::Kategorie, Dimension::Kostenstelle];

    public function __construct(
        private Auswertungsquelle $quelle,
        private CategoryRepository $kategorien,
        private CostCenterRepository $kostenstellen,
        private InvoiceRepository $belege,
        private BankTransactionRepository $buchungen,
        private SupplierService $lieferanten,
    ) {
    }

    public function uebersicht(Vault $vault, Grundlage $grundlage, Zeitraum $zeitraum, Zugriffsbereich $bereich, InvoiceDirection $seite): GeldflussErgebnis
    {
        $sichtbar = $zeitraum->begrenztAuf($bereich);
        if ($sichtbar === null) {
            return new GeldflussErgebnis(null, []);
        }

        $daten = $this->quelle->grundlage($vault, $grundlage, $zeitraum, $bereich);
        $rechner = $this->quelle->rechner();
        $tabellen = [];
        foreach (self::DIMENSIONEN as $dimension) {
            $namen = $this->namen($dimension);
            $gruppen = $rechner->gruppiert($daten, $bereich, $dimension, $seite);
            $summe = 0;
            $groesster = 0;
            foreach ($gruppen as $gruppe) {
                $betrag = self::betrag($gruppe->summen, $seite);
                $summe += $betrag;
                $groesster = max($groesster, $betrag);
            }
            $zeilen = [];
            foreach ($gruppen as $gruppe) {
                $betrag = self::betrag($gruppe->summen, $seite);
                $schluessel = is_int($gruppe->schluessel) ? $gruppe->schluessel : null;
                $zeilen[] = new GeldflussZeile(
                    $schluessel,
                    self::name($dimension, $schluessel, $namen),
                    $betrag,
                    $gruppe->summen->anzahl,
                    $summe > 0 && $betrag >= 0 ? self::promille($betrag, $summe) : null,
                    $groesster > 0 && $betrag > 0 ? self::promille($betrag, $groesster) : 0,
                );
            }
            $tabellen[$dimension->value] = new GeldflussTabelle($dimension, $zeilen, $summe);
        }

        return new GeldflussErgebnis($sichtbar, $tabellen, $daten->unlesbar, $daten->fremdwaehrung);
    }

    /**
     * The lines behind one group, by date. $schluessel null = the lines
     * without a category / cost center.
     *
     * @param bool $buchungsdetails whether the reader may see a booking's
     *        counterparty and purpose (`bank.view`); otherwise a booking
     *        without a receipt shows as such, nothing more
     */
    public function posten(
        Vault $vault,
        Grundlage $grundlage,
        Zeitraum $zeitraum,
        Zugriffsbereich $bereich,
        InvoiceDirection $seite,
        Dimension $dimension,
        ?int $schluessel,
        bool $buchungsdetails,
    ): GeldflussDrilldown {
        if (!in_array($dimension, self::DIMENSIONEN, true)) {
            throw new \InvalidArgumentException('Unsupported dimension.');
        }
        $sichtbar = $zeitraum->begrenztAuf($bereich);
        $titel = self::name($dimension, $schluessel, $this->namen($dimension));
        if ($sichtbar === null) {
            return new GeldflussDrilldown(null, $titel, [], 0);
        }

        $daten = $this->quelle->grundlage($vault, $grundlage, $zeitraum, $bereich);
        $treffer = $this->quelle->rechner()->drilldown($daten, $bereich, $dimension, $schluessel, $seite);

        $belegIds = array_values(array_unique(array_filter(array_map(static fn(Posten $p): ?int => $p->belegId, $treffer))));
        /** @var array<int, InvoiceRecord> $records */
        $records = [];
        foreach ($this->belege->nachIds($belegIds) as $record) {
            $records[$record->id] = $record;
        }

        $kategorien = $this->namen(Dimension::Kategorie);
        $kostenstellen = $this->namen(Dimension::Kostenstelle);
        $lieferanten = [];
        $zeilen = [];
        $summe = 0;
        foreach ($treffer as $posten) {
            $summe += $posten->betrag;
            $record = $posten->belegId === null ? null : ($records[$posten->belegId] ?? null);
            [$bezeichnung, $detail] = $record !== null
                ? $this->belegText($vault, $record, $lieferanten)
                : $this->buchungText($vault, $posten, $buchungsdetails);
            $zeilen[] = new GeldflussPosten(
                $posten->datum,
                $posten->betrag,
                $bezeichnung,
                $detail,
                self::name(Dimension::Kategorie, $posten->kategorieId, $kategorien),
                self::name(Dimension::Kostenstelle, $posten->kostenstelleId, $kostenstellen),
                $record?->documentId,
                $posten->buchungId,
            );
        }

        return new GeldflussDrilldown($sichtbar, $titel, $zeilen, $summe, $daten->unlesbar, $daten->fremdwaehrung);
    }

    /**
     * @param array<int, string> $lieferanten names already decrypted, by id
     *
     * @return array{0: string, 1: string}
     */
    private function belegText(Vault $vault, InvoiceRecord $record, array &$lieferanten): array
    {
        $name = 'ohne Lieferant';
        if ($record->supplierId !== null) {
            if (!array_key_exists($record->supplierId, $lieferanten)) {
                try {
                    $lieferanten[$record->supplierId] = $this->lieferanten->finde($vault, $record->supplierId)?->data->name ?? '';
                } catch (CryptoException | \JsonException) {
                    $lieferanten[$record->supplierId] = '';
                }
            }
            $name = $lieferanten[$record->supplierId] !== '' ? $lieferanten[$record->supplierId] : 'Lieferant nicht lesbar';
        }
        try {
            $daten = Pruefung::entschluesseln($vault, $record);
            $detail = implode(' · ', array_filter([
                $daten->invoiceNumber !== '' ? 'Nr. ' . $daten->invoiceNumber : '',
                $daten->purposeShort,
            ], static fn(string $s): bool => $s !== ''));
        } catch (CryptoException | \JsonException) {
            $detail = 'Beleg nicht lesbar';
        }

        return [$name, $detail];
    }

    /** @return array{0: string, 1: string} */
    private function buchungText(Vault $vault, Posten $posten, bool $buchungsdetails): array
    {
        if (!$buchungsdetails || $posten->buchungId === null) {
            return ['Buchung ohne Beleg', ''];
        }
        $record = $this->buchungen->find($posten->buchungId);
        if ($record === null) {
            return ['Buchung ohne Beleg', ''];
        }
        try {
            $buchung = Buchungen::entschluesseln($vault, $record);
        } catch (CryptoException | \JsonException) {
            return ['Buchung ohne Beleg', 'Buchung nicht lesbar'];
        }

        return [$buchung->counterpartyName !== '' ? $buchung->counterpartyName : 'Buchung ohne Beleg', $buchung->purpose];
    }

    /** @return array<int, string> */
    private function namen(Dimension $dimension): array
    {
        $namen = [];
        if ($dimension === Dimension::Kategorie) {
            foreach ($this->kategorien->all() as $kategorie) {
                $namen[$kategorie->id] = $kategorie->name;
            }
        } elseif ($dimension === Dimension::Kostenstelle) {
            foreach ($this->kostenstellen->all() as $kostenstelle) {
                $namen[$kostenstelle->id] = $kostenstelle->name;
            }
        }

        return $namen;
    }

    /** @param array<int, string> $namen */
    private static function name(Dimension $dimension, ?int $schluessel, array $namen): string
    {
        $art = $dimension === Dimension::Kostenstelle ? 'Kostenstelle' : 'Kategorie';
        if ($schluessel === null) {
            return 'ohne ' . $art;
        }

        return $namen[$schluessel] ?? sprintf('%s Nr. %d', $art, $schluessel);
    }

    private static function betrag(Summen $summen, InvoiceDirection $seite): int
    {
        return $seite === InvoiceDirection::Einnahme ? $summen->einnahmen : $summen->ausgaben;
    }

    /** $teil of $ganz in tenths of a percent, rounded half up; both >= 0, $ganz > 0. */
    private static function promille(int $teil, int $ganz): int
    {
        return intdiv($teil * 1000 * 2 + $ganz, $ganz * 2);
    }
}
