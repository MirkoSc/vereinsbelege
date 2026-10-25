<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Domain\BankTransaction;
use App\Domain\BankTransactionSetBy;
use App\Domain\DocumentStatus;
use App\Domain\Zugriffsbereich;
use App\Repository\AllocationRepository;
use App\Repository\CategoryRepository;
use App\Repository\InvoiceRepository;
use App\Repository\SettingRepository;
use App\Repository\TransferRepository;
use App\Service\Bank\BankAccountService;
use App\Service\Bank\BuchungFilter;
use App\Service\Bank\Buchungen;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\Invoice\Pruefung;

/**
 * The DATEV export for the tax advisor (issue #93, E-49,
 * docs/spec/05-auswertung-und-export.md section 4): the bookings of all
 * accounts and cash boxes of a period as one Buchungsstapel. Runs in a
 * session with the unlocked vault (CLAUDE.md section 4) - it decrypts
 * amounts and receipt numbers - and builds everything in memory; nothing is
 * written to disk.
 *
 * Per booking (by booking date, then id):
 * - Konto = the ledger account of its bank account or cash box,
 *   Gegenkonto = the ledger account of its category (a booking without
 *   category: the clearing account, if set). The amount keeps its sign:
 *   money in is Soll on "Konto", money out Haben.
 * - A transfer between own accounts: with a money-in-transit account set,
 *   each half is its own row against it, on its own day - right across a
 *   period or year boundary. Without one, the transfer is one row, its
 *   outgoing half with the receiving account as Gegenkonto, in the period
 *   of that outgoing half; the incoming half is always left out, so no
 *   export of any period books the transfer twice.
 * - Belegfeld 1 = the invoice number of the first allocated receipt that
 *   is checked or locked and lies within the reader's scope - never an AI
 *   suggestion nobody confirmed.
 * - Buchungstext = the category name ("Umbuchung", "ohne Kategorie") - no
 *   counterparty, no purpose: those may name members.
 * - The period scope of the reader (`export.csv`) applies to the booking
 *   date in SQL (App\Repository\BankTransactionRepository::liste()).
 *
 * A booking that cannot be written (account or category without ledger
 * account, not in EUR) holds the whole export back: the plan lists it.
 * Bookings of 0,00 are left out - DATEV refuses them.
 */
final readonly class DatevExport
{
    public const string TEXT_UMBUCHUNG = 'Umbuchung';

    public const string TEXT_OHNE_KATEGORIE = 'ohne Kategorie';

    public function __construct(
        private Buchungen $buchungen,
        private TransferRepository $umbuchungen,
        private AllocationRepository $zuordnungen,
        private InvoiceRepository $belege,
        private CategoryRepository $kategorien,
        private BankAccountService $konten,
        private SettingRepository $settings,
    ) {
    }

    public function einstellungen(): DatevEinstellungen
    {
        return DatevEinstellungen::fromSettings($this->settings);
    }

    public function vorbereiten(Vault $vault, DatevZeitraum $zeitraum, Zugriffsbereich $bereich, \DateTimeImmutable $jetzt): DatevPlan
    {
        $einstellungen = $this->einstellungen();

        $kategorieNamen = [];
        foreach ($this->kategorien->all() as $kategorie) {
            $kategorieNamen[$kategorie->id] = $kategorie->name;
        }
        $kontoNamen = [];
        foreach ($this->konten->lesbare($vault)['konten'] as $konto) {
            $kontoNamen[$konto->id] = $konto->data->name;
        }

        $buchungen = $this->buchungen->liste($vault, new BuchungFilter(von: $zeitraum->von, bis: $zeitraum->bis), $bereich);
        usort($buchungen, static fn(BankTransaction $a, BankTransaction $b): int => ($a->bookingDate <=> $b->bookingDate) ?: $a->id <=> $b->id);
        $belegnummern = $this->belegnummern($vault, $buchungen, $bereich);

        $zeilen = [];
        $ohneFinanzkonto = [];
        $ohneGegenkonto = [];
        $fremdwaehrung = 0;
        $nullbetraege = 0;
        $gegenbuchungen = 0;

        $umbuchungen = $this->umbuchungen->mitKontenFuer(array_map(
            static fn(BankTransaction $b): int => $b->id,
            array_values(array_filter($buchungen, static fn(BankTransaction $b): bool => $b->docSource === BankTransactionSetBy::Umbuchung)),
        ));
        $geldtransit = $einstellungen->geldtransit();

        foreach ($buchungen as $buchung) {
            // Marked as a transfer, but no pair (cannot happen with the
            // foreign keys): booked like any booking, by its category.
            $umbuchung = $umbuchungen[$buchung->id] ?? null;
            if ($umbuchung !== null && $geldtransit === null && $umbuchung['umbuchung']->einnahmeId === $buchung->id) {
                $gegenbuchungen++;
                continue;
            }
            if (strtoupper($buchung->currency) !== 'EUR') {
                $fremdwaehrung++;
                continue;
            }
            if ($buchung->amount === 0) {
                $nullbetraege++;
                continue;
            }

            $konto = $einstellungen->finanzkonto($buchung->accountId);
            if ($konto === null) {
                $ohneFinanzkonto[$buchung->accountId] = ($ohneFinanzkonto[$buchung->accountId] ?? 0) + 1;
            }

            if ($umbuchung !== null && $geldtransit !== null) {
                $gegenkonto = $geldtransit;
                $text = self::TEXT_UMBUCHUNG;
            } elseif ($umbuchung !== null) {
                $empfaenger = $umbuchung['einnahmeKonto'];
                $gegenkonto = $einstellungen->finanzkonto($empfaenger);
                if ($gegenkonto === null) {
                    $ohneFinanzkonto[$empfaenger] = ($ohneFinanzkonto[$empfaenger] ?? 0) + 1;
                }
                $text = self::TEXT_UMBUCHUNG;
            } else {
                $gegenkonto = $einstellungen->gegenkonto($buchung->categoryId);
                if ($gegenkonto === null) {
                    $ohneGegenkonto[$buchung->categoryId ?? 0] = ($ohneGegenkonto[$buchung->categoryId ?? 0] ?? 0) + 1;
                }
                $text = $buchung->categoryId === null
                    ? self::TEXT_OHNE_KATEGORIE
                    : ($kategorieNamen[$buchung->categoryId] ?? self::TEXT_OHNE_KATEGORIE);
            }

            if ($konto !== null && $gegenkonto !== null) {
                $zeilen[] = new DatevZeile(
                    $buchung->amount,
                    $konto,
                    $gegenkonto,
                    $buchung->bookingDate,
                    $belegnummern[$buchung->id] ?? '',
                    $text,
                );
            }
        }

        return new DatevPlan(
            zeitraum: $zeitraum,
            kopf: $einstellungen->kopf($zeitraum->von, $zeitraum->bis, $jetzt),
            zeilen: $zeilen,
            ohneFinanzkonto: $ohneFinanzkonto,
            ohneGegenkonto: $ohneGegenkonto,
            fremdwaehrung: $fremdwaehrung,
            nullbetraege: $nullbetraege,
            gegenbuchungen: $gegenbuchungen,
            kontoNamen: $kontoNamen,
            kategorieNamen: $kategorieNamen,
        );
    }

    /**
     * Belegfeld 1 per booking id: the invoice number of the first allocated
     * receipt (by allocation) that is checked or locked, lies within
     * $bereich and has a number DATEV can take.
     *
     * @param list<BankTransaction> $buchungen
     *
     * @return array<int, string>
     */
    private function belegnummern(Vault $vault, array $buchungen, Zugriffsbereich $bereich): array
    {
        $zuordnungen = $this->zuordnungen->forTransactions(array_map(static fn(BankTransaction $b): int => $b->id, $buchungen));
        $invoiceIds = [];
        foreach ($zuordnungen as $liste) {
            foreach ($liste as $zuordnung) {
                $invoiceIds[] = $zuordnung->invoiceId;
            }
        }
        $belege = $this->belege->auswertungNachIds($invoiceIds, [DocumentStatus::Geprueft, DocumentStatus::Festgeschrieben]);

        $nummern = [];
        $ergebnis = [];
        foreach ($zuordnungen as $transaktionId => $liste) {
            foreach ($liste as $zuordnung) {
                $record = $belege[$zuordnung->invoiceId]['record'] ?? null;
                if ($record === null || !$bereich->erlaubt($record->costCenterId, $record->invoiceDate)) {
                    continue;
                }
                if (!array_key_exists($record->id, $nummern)) {
                    try {
                        $nummern[$record->id] = DatevBuchungsstapel::belegfeld(Pruefung::entschluesseln($vault, $record)->invoiceNumber);
                    } catch (CryptoException | \JsonException) {
                        $nummern[$record->id] = '';
                    }
                }
                if ($nummern[$record->id] !== '') {
                    $ergebnis[$transaktionId] = $nummern[$record->id];
                    break;
                }
            }
        }

        return $ergebnis;
    }
}
