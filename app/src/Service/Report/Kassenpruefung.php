<?php

declare(strict_types=1);

namespace App\Service\Report;

use App\Domain\Allocation;
use App\Domain\BankTransactionRecord;
use App\Domain\Zugriffsbereich;
use App\Repository\BankTransactionRepository;
use App\Repository\InvoiceRepository;
use App\Service\Bank\BuchungFilter;
use App\Service\Bank\Buchungen;
use App\Service\Bank\Zuordnung;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\Invoice\Pruefung;
use App\Service\MasterData\SupplierService;

/**
 * Builds the Kassenprüfung report (issue #74, docs/spec/
 * 05-auswertung-und-export.md section 1 "Kassenprüfung", E-52) in a session
 * with an unlocked vault.
 *
 * - Balances per account at the start and end of the period: the accounts
 *   and movements come from the Auswertungsquelle (session cache, issue
 *   #72), the figures from ReportService::kontosalden(). For a period-scoped
 *   reader (external auditor) everything before the released period is one
 *   carry-over (E-44).
 * - The bookings of the period, transfers between own accounts included
 *   and marked, each with its receipt status (`doc_status`) and its
 *   allocations. They are read straight from the database: the session
 *   cache holds report lines without counterparty and purpose, and a
 *   report page is opened rarely.
 *
 * Scopes: bookings and balances within $buchungsbereich (`bank.view`,
 * the period scope on the booking date - in SQL); the receipt behind an
 * allocation is named only within $belegbereich (`report.view`, receipt
 * date and cost center, per row) - otherwise the report says that a
 * receipt is allocated, not which one.
 */
final readonly class Kassenpruefung
{
    public function __construct(
        private Auswertungsquelle $quelle,
        private BankTransactionRepository $buchungen,
        private Zuordnung $zuordnung,
        private InvoiceRepository $belege,
        private SupplierService $lieferanten,
    ) {
    }

    public function bericht(Vault $vault, Zeitraum $zeitraum, Zugriffsbereich $buchungsbereich, Zugriffsbereich $belegbereich): KassenpruefungBericht
    {
        $sichtbar = $zeitraum->begrenztAuf($buchungsbereich);
        // Bookings carry no cost center: a cost-center scope sees none (01 §4).
        if ($sichtbar === null || $buchungsbereich->kostenstellen !== null) {
            return new KassenpruefungBericht($zeitraum, null, [], []);
        }

        ['konten' => $konten, 'bewegungen' => $bewegungen, 'unlesbar' => $unlesbar] = $this->quelle->kontobewegungen($vault, $zeitraum, $buchungsbereich);
        $salden = [];
        $kontoNamen = [];
        foreach ($konten as $konto) {
            $kontoNamen[$konto->id] = $konto;
        }
        foreach ($this->quelle->rechner()->kontosalden($konten, $bewegungen, $zeitraum, $buchungsbereich) as $saldo) {
            $salden[] = new KontosaldoZeile($kontoNamen[$saldo->kontoId], $saldo);
        }

        // liste() is newest first; the report reads oldest first.
        $records = array_reverse($this->buchungen->liste(new BuchungFilter(von: $sichtbar->von, bis: $sichtbar->bis), $buchungsbereich));
        $zuordnungen = $this->zuordnung->fuerBuchungen($vault, array_map(static fn(BankTransactionRecord $r): int => $r->id, $records));
        $belege = $this->belege($vault, $zuordnungen, $belegbereich);

        $buchungen = [];
        foreach ($records as $record) {
            try {
                $buchung = Buchungen::entschluesseln($vault, $record);
            } catch (CryptoException | \JsonException) {
                ++$unlesbar;
                continue;
            }
            $liste = $zuordnungen[$record->id] ?? [];
            $buchungen[] = new Pruefbuchung(
                $buchung,
                isset($kontoNamen[$buchung->accountId]) ? $kontoNamen[$buchung->accountId]->data->name : 'Konto ' . $buchung->accountId,
                array_map(static fn(Allocation $a): Pruefzuordnung => new Pruefzuordnung($a, $belege[$a->invoiceId] ?? null), self::sortiert($liste ?? [])),
                $liste === null,
            );
        }

        return new KassenpruefungBericht($zeitraum, $sichtbar, $salden, $buchungen, $buchungsbereich->von !== null, $unlesbar);
    }

    /**
     * The receipts behind the allocations that the reader may see, by
     * invoice id. A receipt that does not decrypt is named without its
     * number rather than left out - the allocation itself is fact.
     *
     * @param array<int, list<Allocation>|null> $zuordnungen
     *
     * @return array<int, Pruefbeleg>
     */
    private function belege(Vault $vault, array $zuordnungen, Zugriffsbereich $belegbereich): array
    {
        $ids = [];
        foreach ($zuordnungen as $liste) {
            foreach ($liste ?? [] as $z) {
                $ids[] = $z->invoiceId;
            }
        }
        if ($ids === []) {
            return [];
        }

        $namen = [];
        $belege = [];
        foreach ($this->belege->mitDokumentStatus($ids) as $id => ['record' => $record, 'status' => $status]) {
            if (!$belegbereich->erlaubt($record->costCenterId, $record->invoiceDate)) {
                continue;
            }
            try {
                $nummer = Pruefung::entschluesseln($vault, $record)->invoiceNumber;
            } catch (CryptoException | \JsonException) {
                $nummer = '';
            }
            $lieferant = '';
            if ($record->supplierId !== null) {
                // finde() also reads a supplier merged into another (issue #39).
                $namen[$record->supplierId] ??= $this->lieferant($vault, $record->supplierId);
                $lieferant = $namen[$record->supplierId];
            }
            $belege[$id] = new Pruefbeleg($record->documentId, $record->invoiceDate, $lieferant, $nummer, $status);
        }

        return $belege;
    }

    private function lieferant(Vault $vault, int $id): string
    {
        try {
            return $this->lieferanten->finde($vault, $id)?->data->name ?? '';
        } catch (CryptoException | \JsonException) {
            return '';
        }
    }

    /**
     * @param list<Allocation> $liste
     *
     * @return list<Allocation>
     */
    private static function sortiert(array $liste): array
    {
        usort($liste, static fn(Allocation $a, Allocation $b): int => $a->id <=> $b->id);

        return $liste;
    }
}
