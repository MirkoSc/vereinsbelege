<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Domain\BankTransaction;
use App\Domain\BankTransactionDirection;
use App\Domain\BankTransactionDocStatus;
use App\Domain\DocumentStatus;
use App\Domain\Erstattungsart;
use App\Domain\Iban;
use App\Domain\InvoiceDirection;
use App\Domain\InvoiceRecord;
use App\Domain\InvoiceType;
use App\Domain\Zahlungsweg;
use App\Domain\Zugriffsbereich;
use App\Domain\ZuordnungMethode;
use App\Repository\BankTransactionRepository;
use App\Repository\ErstattungRepository;
use App\Repository\InvoiceRepository;
use App\Service\Bank\Import\KontoauszugImport;
use App\Service\Crypto\Vault;
use App\Service\Inbox\EinreichungsDaten;
use App\Service\Invoice\Pruefung;

/**
 * Reimbursements (M10-3, issue #67, docs/spec/04-bank-und-abgleich.md
 * section 5, E-36): a member paid, the club transfers the money back -
 * often several receipts in one transfer.
 *
 * - The link is the submitter's IBAN (the public submission's
 *   "Erstattung per Überweisung"): compared as blind index
 *   (purpose `bank_transaction.counterparty`, the one the statement import
 *   writes), so a booking's `counterparty_bi` finds the receipts' bookings
 *   in SQL. The submission's IBAN itself has no stored index - it is read
 *   from the sealed submission in the session.
 * - Combinations: Kombinationssuche over the open amounts of this IBAN's
 *   open reimbursements, bounded (6 receipts, 20 candidates, 20 000 steps,
 *   5 hits), so it fits a short request.
 * - Writing goes through Zuordnung alone - one transaction for the whole
 *   combination, locks in the order document -> receipt -> booking, every
 *   receipt settled with its full open amount (methode
 *   `vorschlag_bestaetigt`, rule_trace `erstattung_iban` = 60 points of the
 *   spec's table plus the number of receipts).
 *
 * Needs the unlocked vault throughout; nothing of a submission, a receipt
 * or a booking leaves this class but in the returned objects.
 */
final readonly class Erstattungen
{
    /** Open reimbursements read per search - a club has a few dozen. */
    public const int MAX_OFFENE = 500;

    /** Bookings offered for one receipt. */
    public const int MAX_BUCHUNGEN = 50;

    /** Points of the signal "Empfänger-IBAN = Einreicher-IBAN" (spec table). */
    public const int PUNKTE_IBAN = 60;

    public function __construct(
        private \PDO $pdo,
        private ErstattungRepository $erstattungen,
        private InvoiceRepository $belege,
        private BankTransactionRepository $buchungen,
        private Zuordnung $zuordnung,
        private Kombinationssuche $suche = new Kombinationssuche(),
    ) {
    }

    /**
     * The open reimbursements of this booking's counterparty and their
     * combinations; null when the booking does not exist or is outside
     * $bereich.
     */
    public function vorschlag(Vault $vault, int $transactionId, Zugriffsbereich $bereich): ?Erstattungsvorschlag
    {
        $record = $this->buchungen->find($transactionId);
        if ($record === null || !$bereich->erlaubt(null, $record->bookingDate)) {
            return null;
        }
        $buchung = Buchungen::entschluesseln($vault, $record);
        $offen = $this->zuordnung->belegstand($vault, $record)->offen();

        if ($buchung->direction !== BankTransactionDirection::Ausgabe) {
            return new Erstattungsvorschlag($buchung, $offen, 'Erstattungen sind Ausgaben – diese Buchung ist eine Einnahme.');
        }
        if ($offen <= 0 || !in_array($buchung->docStatus, [BankTransactionDocStatus::Fehlt, BankTransactionDocStatus::Teilweise], true)) {
            return new Erstattungsvorschlag($buchung, $offen, 'Für diese Buchung wird kein weiterer Beleg erwartet.');
        }
        $bi = self::gegenseite($vault, $buchung);
        if ($bi === null) {
            return new Erstattungsvorschlag($buchung, $offen, 'Die Buchung nennt keine gültige IBAN der Gegenseite.');
        }

        // A receipt already allocated to this booking cannot get a second
        // allocation of the same pair - it is not offered again.
        $schon = array_map(static fn($z): int => $z->invoiceId, $this->zuordnung->fuerBuchung($vault, $transactionId));
        [$kandidaten, $alleGelesen] = $this->kandidaten($vault, $bi, $bereich, $schon);
        $nachId = [];
        $betraege = [];
        foreach ($kandidaten as $kandidat) {
            $nachId[$kandidat->invoiceId] = $kandidat;
            $betraege[$kandidat->invoiceId] = $kandidat->offen;
        }
        $ergebnis = $this->suche->suchen($offen, $betraege);

        return new Erstattungsvorschlag(
            $buchung,
            $offen,
            null,
            $kandidaten,
            array_map(static fn(array $ids): array => array_map(static fn(int $id): Erstattungskandidat => $nachId[$id], $ids), $ergebnis->treffer),
            $ergebnis->vollstaendig && $alleGelesen,
        );
    }

    /**
     * Outgoing bookings to the submitter's IBAN of one open reimbursement
     * that still miss a receipt (blind index in SQL; manual bookings carry
     * no index and are found from the booking side only) - the receipt-side
     * view of the same link, for the scoring (M10-2) and the matching page
     * (M10-4). Empty when the receipt is no open reimbursement or names no
     * valid IBAN.
     *
     * @return list<BankTransaction> oldest first
     */
    public function buchungenFuerBeleg(Vault $vault, int $invoiceId, Zugriffsbereich $bereich): array
    {
        $beleg = $this->belege->find($invoiceId);
        if ($beleg === null || !self::istOffeneErstattung($beleg) || !$bereich->erlaubt($beleg->costCenterId, $beleg->invoiceDate)
            || $this->belege->dokumentStatus($beleg->documentId) === DocumentStatus::Abgelehnt) {
            return [];
        }
        $bi = $this->einreicherIndex($vault, $invoiceId);
        if ($bi === null) {
            return [];
        }

        $buchungen = [];
        foreach ($this->erstattungen->buchungenAnGegenseite($bi, $bereich, self::MAX_BUCHUNGEN) as $id) {
            $record = $this->buchungen->find($id);
            if ($record !== null) {
                $buchungen[] = Buchungen::entschluesseln($vault, $record);
            }
        }

        return $buchungen;
    }

    /**
     * Settles the receipts $invoiceIds - each with its full open amount -
     * with one booking, in one transaction. Checked again under the row
     * locks: open reimbursements within $bereich, the booking's
     * counterparty is each submitter's IBAN, and together they are exactly
     * what the booking leaves open.
     *
     * @param list<int> $invoiceIds
     *
     * @return list<int> the new `allocation` ids
     *
     * @throws BankRuleViolation
     */
    public function zuordnen(
        Vault $vault,
        int $transactionId,
        array $invoiceIds,
        Zugriffsbereich $bereich,
        ?int $userId,
        string $ip,
        \DateTimeImmutable $now,
    ): array {
        $invoiceIds = array_values(array_unique($invoiceIds));
        sort($invoiceIds);
        if ($invoiceIds === [] || count($invoiceIds) > Kombinationssuche::MAX_BELEGE) {
            throw new BankRuleViolation(sprintf('Bitte 1 bis %d Belege wählen.', Kombinationssuche::MAX_BELEGE));
        }

        $this->pdo->beginTransaction();
        try {
            $record = $this->buchungen->find($transactionId);
            if ($record === null || !$bereich->erlaubt(null, $record->bookingDate)) {
                throw new BankRuleViolation('Diese Buchung gibt es nicht.');
            }

            // Document -> receipt -> booking, ascending ids within each, the
            // order every writer of allocations locks in.
            $dokumente = [];
            foreach ($invoiceIds as $id) {
                $dokumente[$id] = $this->belege->find($id)?->documentId
                    ?? throw new BankRuleViolation('Diesen Beleg gibt es nicht.');
            }
            $sortiert = array_values(array_unique($dokumente));
            sort($sortiert);
            foreach ($sortiert as $dokumentId) {
                if ($this->belege->dokumentStatus($dokumentId) === DocumentStatus::Abgelehnt) {
                    throw new BankRuleViolation('Ein gewählter Beleg ist abgelehnt und nimmt keine Zuordnung an.');
                }
            }
            $belege = [];
            foreach ($invoiceIds as $id) {
                $beleg = $this->belege->find($id, sperren: true)
                    ?? throw new BankRuleViolation('Diesen Beleg gibt es nicht.');
                if (!$bereich->erlaubt($beleg->costCenterId, $beleg->invoiceDate)) {
                    throw new BankRuleViolation('Diesen Beleg gibt es nicht.');
                }
                if (!self::istOffeneErstattung($beleg)) {
                    throw new BankRuleViolation('Ein gewählter Beleg ist keine offene Erstattung (mehr).');
                }
                $belege[] = $beleg;
            }
            $record = $this->buchungen->find($transactionId, sperren: true)
                ?? throw new BankRuleViolation('Diese Buchung gibt es nicht.');
            $buchung = Buchungen::entschluesseln($vault, $record);
            $bi = self::gegenseite($vault, $buchung);
            if ($buchung->direction !== BankTransactionDirection::Ausgabe || $bi === null) {
                throw new BankRuleViolation('Eine Erstattung ist eine Ausgabe an die IBAN des Einreichers.');
            }
            if (!in_array($record->docStatus, [BankTransactionDocStatus::Fehlt, BankTransactionDocStatus::Teilweise], true)) {
                throw new BankRuleViolation('Für diese Buchung wird kein weiterer Beleg erwartet.');
            }

            $betraege = [];
            $summe = 0;
            foreach ($belege as $beleg) {
                $einreicher = $this->einreicherIndex($vault, $beleg->id);
                if ($einreicher === null || !hash_equals($einreicher, $bi)) {
                    throw new BankRuleViolation('Die IBAN der Gegenseite ist nicht die IBAN, die der Einreicher für die Erstattung angegeben hat.');
                }
                $betraege[$beleg->id] = $this->zuordnung->zahlungsstand($vault, $beleg)->offen();
                $summe += $betraege[$beleg->id];
            }
            if ($summe !== $this->zuordnung->belegstand($vault, $record)->offen()) {
                throw new BankRuleViolation('Die offenen Beträge der gewählten Belege ergeben zusammen nicht den offenen Betrag der Buchung.');
            }

            $ids = [];
            $trace = ['erstattung_iban' => self::PUNKTE_IBAN, 'belege' => count($belege)];
            foreach ($betraege as $invoiceId => $betrag) {
                $ids[] = $this->zuordnung->zuordnen($vault, $invoiceId, $transactionId, $betrag, ZuordnungMethode::VorschlagBestaetigt, null, $trace, $userId, $ip, $now);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return $ids;
    }

    /**
     * @param list<int> $ausgenommen invoice ids already allocated to the booking
     *
     * @return array{0: list<Erstattungskandidat>, 1: bool} oldest first; false when MAX_OFFENE cut the read
     */
    private function kandidaten(Vault $vault, string $bi, Zugriffsbereich $bereich, array $ausgenommen): array
    {
        $index = $vault->blindIndex();
        $kandidaten = [];
        $zeilen = $this->erstattungen->offene($bereich, self::MAX_OFFENE);
        foreach ($zeilen as $zeile) {
            if (in_array($zeile['beleg']->id, $ausgenommen, true) || !self::istOffeneErstattung($zeile['beleg'])) {
                continue;
            }
            $daten = EinreichungsDaten::oeffnen($zeile['submissionId'], $zeile['dekSealed'], $zeile['payloadEnc'], $vault);
            $iban = self::ibanAus($daten);
            if ($iban === null || !hash_equals($index->forValue(KontoauszugImport::COUNTERPARTY_PURPOSE, $iban), $bi)) {
                continue;
            }
            $beleg = $zeile['beleg'];
            $offen = $this->zuordnung->zahlungsstand($vault, $beleg)->offen();
            if ($offen <= 0) {
                continue;
            }
            $inhalt = Pruefung::entschluesseln($vault, $beleg);
            $kandidaten[] = new Erstattungskandidat(
                $beleg->id,
                $beleg->documentId,
                $beleg->invoiceDate,
                $inhalt->invoiceNumber,
                $inhalt->purposeShort,
                $daten?->name ?? '',
                $offen,
            );
        }

        return [$kandidaten, count($zeilen) < self::MAX_OFFENE];
    }

    /** Blind index of the IBAN a receipt's submitter gave for the transfer, or null. */
    private function einreicherIndex(Vault $vault, int $invoiceId): ?string
    {
        $einreichung = $this->erstattungen->einreichung($invoiceId);
        if ($einreichung === null) {
            return null;
        }
        $iban = self::ibanAus(EinreichungsDaten::oeffnen($einreichung['submissionId'], $einreichung['dekSealed'], $einreichung['payloadEnc'], $vault));

        return $iban === null ? null : $vault->blindIndex()->forValue(KontoauszugImport::COUNTERPARTY_PURPOSE, $iban);
    }

    /** Blind index of the booking's counterparty IBAN - as the import writes `counterparty_bi`. */
    private static function gegenseite(Vault $vault, BankTransaction $buchung): ?string
    {
        $iban = Iban::normalisieren($buchung->counterpartyIban);

        return Iban::istGueltig($iban) ? $vault->blindIndex()->forValue(KontoauszugImport::COUNTERPARTY_PURPOSE, $iban) : null;
    }

    private static function ibanAus(?EinreichungsDaten $daten): ?string
    {
        if ($daten === null || $daten->erstattung !== Erstattungsart::Ueberweisung) {
            return null;
        }
        $iban = Iban::normalisieren($daten->iban ?? '');

        return Iban::istGueltig($iban) ? $iban : null;
    }

    /**
     * Open, reimbursed by transfer, and settled by an outgoing booking: an
     * expense that is no credit note (a credit note is settled by the
     * opposite direction - App\Service\Bank\Zuordnung).
     */
    private static function istOffeneErstattung(InvoiceRecord $beleg): bool
    {
        return $beleg->paymentKind === Zahlungsweg::Erstattung && $beleg->paymentStatus->offen()
            && $beleg->direction === InvoiceDirection::Ausgabe && $beleg->docType !== InvoiceType::Gutschrift;
    }
}
