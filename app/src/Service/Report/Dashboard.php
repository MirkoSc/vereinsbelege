<?php

declare(strict_types=1);

namespace App\Service\Report;

use App\Domain\Berechtigungen;
use App\Domain\DocumentStatus;
use App\Domain\OffenerBelegRecord;
use App\Domain\PaymentStatus;
use App\Domain\Permission;
use App\Domain\Zahlungsweg;
use App\Domain\Zugriffsbereich;
use App\Repository\BankTransactionRepository;
use App\Repository\DocumentRepository;
use App\Repository\InvoiceRepository;
use App\Repository\RecurringSeriesRepository;
use App\Service\Crypto\Vault;

/**
 * The figures of the dashboard (issue #72, docs/spec/
 * 05-auswertung-und-export.md section 1 "Dashboard"): income, expenses and
 * balance of one calendar year (= fiscal year, E-16), balances per account,
 * and the counts of what is still to do.
 *
 * Every block has the right of the page it links to, with that right's
 * scope (docs/spec/01-sicherheit.md section 4):
 * - sums: `report.view`, booking basis (the default of 05 section 1),
 *   through ReportService; cost center and period scope;
 * - balances: `bank.view`; bookings carry no cost center, so a cost-center
 *   scope sees none (the block is left out), a period scope sees the
 *   balance at a day inside its period;
 * - inbox open: `inbox.view`; in review: `document.edit` (or `inbox.view`
 *   without a link); unpaid receipts and open reimbursements: `report.view`
 *   (the lists "Offen"); bookings without a receipt: `bank.view` without
 *   cost-center scope; overdue recurring receipts: `document.edit`
 *   without any scope (series span every cost center, E-26).
 * The counts are structure fields counted in SQL with the scope - no vault
 * needed. Sums and balances are vault data and come through the
 * Auswertungsquelle (the session cache in production).
 */
final readonly class Dashboard
{
    public function __construct(
        private Auswertungsquelle $quelle,
        private DocumentRepository $dokumente,
        private InvoiceRepository $belege,
        private BankTransactionRepository $buchungen,
        private RecurringSeriesRepository $serien,
    ) {
    }

    /**
     * @param Vault|null $vault the unlocked vault, null when locked - the
     *        counts are still shown
     */
    public function kennzahlen(Berechtigungen $b, ?Vault $vault, int $jahr, \DateTimeImmutable $heute): DashboardKennzahlen
    {
        $zeitraum = Zeitraum::jahr($jahr);

        $summenErlaubt = $b->darf(Permission::ReportView);
        $berichtsBereich = $b->zugriffsbereich(Permission::ReportView);
        $sichtbar = $summenErlaubt ? $zeitraum->begrenztAuf($berichtsBereich) : null;
        $summen = null;
        $unlesbar = 0;
        $fremd = 0;
        // A year outside the period scope shows only a note - nothing to load.
        if ($summenErlaubt && $vault !== null && $sichtbar !== null) {
            $grundlage = $this->quelle->grundlage($vault, Grundlage::Buchungen, $zeitraum, $berichtsBereich);
            $summen = $this->quelle->rechner()->summen($grundlage, $berichtsBereich);
            $unlesbar = $grundlage->unlesbar;
            $fremd = $grundlage->fremdwaehrung;
        }

        $bankBereich = $b->zugriffsbereich(Permission::BankView);
        $kontenErlaubt = $b->darf(Permission::BankView) && $bankBereich->kostenstellen === null;
        $stichtag = $kontenErlaubt ? self::stichtag($zeitraum, $bankBereich, $heute) : null;
        $kontostaende = null;
        $kontenUnlesbar = 0;
        if ($kontenErlaubt && $vault !== null) {
            $kontostaende = [];
            if ($stichtag !== null) {
                $daten = $this->quelle->kontobewegungen($vault, Zeitraum::frei($zeitraum->von, $stichtag), $bankBereich);
                $staende = $this->quelle->rechner()->kontostaende($daten['konten'], $daten['bewegungen'], $stichtag, $bankBereich);
                foreach ($daten['konten'] as $konto) {
                    if (array_key_exists($konto->id, $staende)) {
                        $kontostaende[] = new Kontostand($konto, $staende[$konto->id]);
                    }
                }
                $kontenUnlesbar = $daten['unlesbar'];
            }
        }

        return new DashboardKennzahlen(
            $jahr,
            $sichtbar,
            $summenErlaubt,
            $summen,
            $unlesbar,
            $fremd,
            $kontenErlaubt,
            $kontostaende,
            $stichtag,
            $kontenUnlesbar,
            $this->zaehler($b, $heute),
        );
    }

    /**
     * The calendar year the dashboard opens with, and the last one its
     * switcher offers: the current one - or, for a reader whose period
     * scope ended before it (an external auditor with last year released),
     * the year that scope ends in.
     */
    public static function standardJahr(Berechtigungen $b, \DateTimeImmutable $heute): int
    {
        $jahr = (int) $heute->format('Y');
        $bis = self::zeitraumScope($b)->bis;

        return $bis !== null && (int) $bis->format('Y') < $jahr ? (int) $bis->format('Y') : $jahr;
    }

    /** The first year the switcher offers: $erstes, or where the period scope starts. */
    public static function erstesJahr(Berechtigungen $b, int $erstes): int
    {
        $von = self::zeitraumScope($b)->von;

        return $von !== null && (int) $von->format('Y') > $erstes ? (int) $von->format('Y') : $erstes;
    }

    /**
     * The period scope of the account. It is the account's, not a right's
     * (docs/spec/01-sicherheit.md section 4): read through the first right it
     * holds - an account with `bank.view` but no `report.view` has it too.
     */
    private static function zeitraumScope(Berechtigungen $b): Zugriffsbereich
    {
        foreach (Permission::cases() as $recht) {
            if ($b->darf($recht)) {
                return $b->zugriffsbereich($recht);
            }
        }

        return Zugriffsbereich::unbeschraenkt();
    }

    /**
     * The day the balances are at: today in the current year, the last day
     * of a past year, never after the end of the period scope; null when
     * that day is outside the year or outside the period scope.
     */
    private static function stichtag(Zeitraum $jahr, Zugriffsbereich $bereich, \DateTimeImmutable $heute): ?\DateTimeImmutable
    {
        $tag = min($jahr->bis->format('Y-m-d'), $heute->format('Y-m-d'));
        if ($bereich->bis !== null && $bereich->bis->format('Y-m-d') < $tag) {
            $tag = $bereich->bis->format('Y-m-d');
        }
        if ($tag < $jahr->von->format('Y-m-d') || ($bereich->von !== null && $tag < $bereich->von->format('Y-m-d'))) {
            return null;
        }

        return new \DateTimeImmutable($tag);
    }

    /**
     * @return list<Zaehlwert>
     */
    private function zaehler(Berechtigungen $b, \DateTimeImmutable $heute): array
    {
        $zaehler = [];

        if ($b->darf(Permission::InboxView)) {
            $zaehler[] = new Zaehlwert(
                'Posteingang offen',
                $this->dokumente->anzahlPosteingangOffen($b->zugriffsbereich(Permission::InboxView), $heute),
                '/app/posteingang',
            );
        }

        // The review queue (/app/belege/pruefen) - with `document.edit`; an
        // account that may only look at the inbox sees the number, no link.
        $pruefRecht = $b->darf(Permission::DocumentEdit) ? Permission::DocumentEdit : ($b->darf(Permission::InboxView) ? Permission::InboxView : null);
        if ($pruefRecht !== null) {
            $zaehler[] = new Zaehlwert(
                'Belege in Prüfung',
                $this->dokumente->anzahlImStatus(DocumentStatus::pruefbare(), $b->zugriffsbereich($pruefRecht)),
                $pruefRecht === Permission::DocumentEdit ? '/app/belege/pruefen' : null,
            );
        }

        if ($b->darf(Permission::ReportView)) {
            $bereich = $b->zugriffsbereich(Permission::ReportView);
            $offen = $this->belege->offen(Zahlungsweg::Konto, [PaymentStatus::Offen, PaymentStatus::Teilbezahlt], $bereich);
            $altbestand = count(array_filter($offen, static fn(OffenerBelegRecord $r): bool => $r->altbestand));
            $zaehler[] = new Zaehlwert(
                'Unbezahlte Rechnungen',
                count($offen) - $altbestand,
                '/app/offen/rechnungen',
                $altbestand > 0 ? sprintf('dazu %d aus der Zeit vor dem Abgleich', $altbestand) : null,
            );
        }

        $bankBereich = $b->zugriffsbereich(Permission::BankView);
        if ($b->darf(Permission::BankView) && $bankBereich->kostenstellen === null) {
            $zaehler[] = new Zaehlwert('Buchungen ohne Beleg', $this->buchungen->anzahlOhneBeleg($bankBereich), '/app/offen/buchungen');
        }

        if ($b->darf(Permission::ReportView)) {
            $erstattungen = $this->belege->offen(
                Zahlungsweg::Erstattung,
                [PaymentStatus::ErstattungOffen, PaymentStatus::Teilbezahlt],
                $b->zugriffsbereich(Permission::ReportView),
            );
            $zaehler[] = new Zaehlwert(
                'Offene Erstattungen',
                count($erstattungen),
                '/app/offen/erstattungen',
            );
        }

        if ($b->darf(Permission::DocumentEdit) && $b->zugriffsbereich(Permission::DocumentEdit)->istUnbeschraenkt()) {
            $zaehler[] = new Zaehlwert('Überfällige wiederkehrende Belege', $this->serien->anzahlUeberfaellig($heute), '/app/offen/erwartet');
        }

        return $zaehler;
    }
}
