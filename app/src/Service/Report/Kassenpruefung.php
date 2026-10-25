<?php

declare(strict_types=1);

namespace App\Service\Report;

use App\Domain\Allocation;
use App\Domain\BankAccount;
use App\Domain\BankTransaction;
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
        // Bookings carry no cost center: a cost-center scope would see none
        // (01 §4). `bank.view` cannot be cost-center scoped today
        // (Permission::kostenstellenFaehig()) - this only keeps it that way.
        if ($sichtbar === null || $buchungsbereich->kostenstellen !== null) {
            return new KassenpruefungBericht($zeitraum, null, [], []);
        }

        ['konten' => $konten, 'bewegungen' => $bewegungen, 'unlesbar' => $saldenUnlesbar] = $this->quelle->kontobewegungen($vault, $zeitraum, $buchungsbereich);
        $kontenNachId = [];
        foreach ($konten as $konto) {
            $kontenNachId[$konto->id] = $konto;
        }
        $salden = [];
        foreach ($this->quelle->rechner()->kontosalden($konten, $bewegungen, $zeitraum, $buchungsbereich) as $saldo) {
            $salden[] = new KontosaldoZeile($kontenNachId[$saldo->kontoId], $saldo);
        }
        // The opening balance holds a carry-over (E-44) when the scope cuts
        // off bookings of an account that existed before its start.
        $ab = $buchungsbereich->von?->format('Y-m-d');
        $uebertrag = $ab !== null && array_filter($konten, static fn(BankAccount $k): bool => $k->openingDate->format('Y-m-d') < $ab) !== [];

        // liste() is newest first; the report reads oldest first.
        $records = array_reverse($this->buchungen->liste(new BuchungFilter(von: $sichtbar->von, bis: $sichtbar->bis), $buchungsbereich));
        $zuordnungen = $this->zuordnung->fuerBuchungen($vault, array_map(static fn(BankTransactionRecord $r): int => $r->id, $records));
        $belege = $this->belege($vault, $zuordnungen, $belegbereich);

        $buchungen = [];
        $unlesbar = 0;
        foreach ($records as $record) {
            try {
                $buchung = Buchungen::entschluesseln($vault, $record);
            } catch (CryptoException | \JsonException) {
                ++$unlesbar;
                continue;
            }
            // null = an allocation of this booking did not decrypt.
            $liste = array_key_exists($record->id, $zuordnungen) ? $zuordnungen[$record->id] : [];
            $konto = $kontenNachId[$buchung->accountId] ?? null;
            $buchungen[] = new Pruefbuchung(
                $buchung,
                $konto?->data->name ?? 'Konto ' . $buchung->accountId,
                array_map(static fn(Allocation $a): Pruefzuordnung => new Pruefzuordnung($a, $belege[$a->invoiceId] ?? null), self::sortiert($liste ?? [])),
                $liste === null,
                self::nichtImSaldo($buchung, $konto),
            );
        }

        return new KassenpruefungBericht($zeitraum, $sichtbar, $salden, $buchungen, $uebertrag, $unlesbar, $saldenUnlesbar);
    }

    /**
     * Why a listed booking is not in its account's balance - the same rules
     * as Auswertungsdaten::kontobewegungen() and ReportService::
     * kontosalden(), so the auditor can reconcile list and balances.
     */
    private static function nichtImSaldo(BankTransaction $buchung, ?BankAccount $konto): ?string
    {
        return match (true) {
            $konto === null => 'Konto nicht lesbar',
            strtoupper($buchung->currency) !== strtoupper($konto->currency) => 'andere Währung als das Konto',
            $buchung->bookingDate->format('Y-m-d') < $konto->openingDate->format('Y-m-d') => 'vor der Kontoeröffnung',
            default => null,
        };
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
        foreach ($this->belege->mitDokumentStatus($ids, $belegbereich) as $id => ['record' => $record, 'status' => $status]) {
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
