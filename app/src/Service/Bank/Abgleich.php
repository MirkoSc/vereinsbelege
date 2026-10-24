<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Domain\BankTransaction;
use App\Domain\BankTransactionDirection;
use App\Domain\BankTransactionRecord;
use App\Domain\InvoiceData;
use App\Domain\InvoiceRecord;
use App\Domain\Supplier;
use App\Domain\Zugriffsbereich;
use App\Domain\ZuordnungMethode;
use App\Repository\BankTransactionRepository;
use App\Repository\InvoiceRepository;
use App\Repository\SettingRepository;
use App\Service\Crypto\Vault;
use App\Service\Invoice\Pruefung;
use App\Service\MasterData\SupplierService;

/**
 * The matching run (M10-2, issue #66, docs/spec/04-bank-und-abgleich.md
 * section 5 "Punktebewertung", E-35): loads the open receipts and the
 * bookings not yet fully allocated within the user's scope, decrypts them
 * with the session's vault, lets Punktebewertung score every pair and
 * writes the automatic allocations - through App\Service\Bank\Zuordnung,
 * the only place that writes one.
 *
 * Only in a user's session (the amounts are vault data), never in the cron.
 * Nothing is stored about a suggestion: they are scored afresh on every
 * page view, so a suggestion never outlives what it was based on.
 */
final readonly class Abgleich
{
    public function __construct(
        private InvoiceRepository $belege,
        private BankTransactionRepository $buchungen,
        private SupplierService $lieferanten,
        private Zuordnung $zuordnung,
        private SettingRepository $settings,
    ) {
    }

    public function parameter(): Abgleichsparameter
    {
        return Abgleichsparameter::fromSettings($this->settings);
    }

    /** Stores the parameters; whether anything changed. */
    public function parameterSpeichern(Abgleichsparameter $neu): bool
    {
        if ($neu->alsWerte() === $this->parameter()->alsWerte()) {
            return false;
        }
        $neu->speichern($this->settings);

        return true;
    }

    /**
     * Scores everything within $bereich - automatic allocations and
     * suggestions, nothing written - plus the decrypted sides for showing.
     */
    public function planen(Vault $vault, Zugriffsbereich $bereich): Abgleichsstand
    {
        $parameter = $this->parameter();
        $records = $this->belege->abgleichKandidaten($bereich);
        $belege = $this->belegeLaden($vault, $records);
        // Without an open receipt there is nothing to score - no booking is
        // decrypted. A booking from before the earliest open receipt's
        // window is out of every window and stays unread as well.
        $buchungen = $records === [] ? [] : $this->buchungenLaden($vault, $this->buchungen->abgleichKandidaten(
            $bereich,
            $records[0]->invoiceDate->modify(sprintf('-%d days', $parameter->fensterVorTage)),
        ));

        $belegAnzeige = [];
        foreach ($belege as [$eingabe, $record, $daten, $lieferant]) {
            $belegAnzeige[$eingabe->id] = new AbgleichAnzeige\Beleg($record, $daten, $lieferant, $eingabe->offen);
        }
        $buchungAnzeige = [];
        foreach ($buchungen as [$eingabe, $buchung]) {
            $buchungAnzeige[$eingabe->id] = new AbgleichAnzeige\Buchung($buchung, $eingabe->offen);
        }

        return new Abgleichsstand(
            Punktebewertung::planen(array_column($belege, 0), array_column($buchungen, 0), $parameter),
            $belegAnzeige,
            $buchungAnzeige,
        );
    }

    /**
     * Writes every automatic allocation of the plan. A pair another request
     * changed in between is refused by Zuordnung under its row locks and
     * skipped.
     *
     * @return array{angelegt: int, uebersprungen: int}
     */
    public function automatischZuordnen(Vault $vault, Zugriffsbereich $bereich, ?int $userId, string $ip, \DateTimeImmutable $now): array
    {
        $angelegt = 0;
        $uebersprungen = 0;
        foreach ($this->planen($vault, $bereich)->plan->auto as $b) {
            try {
                $this->zuordnung->zuordnen($vault, $b->invoiceId, $b->transactionId, $b->betrag, ZuordnungMethode::Auto, $b->score, $b->trace(), $userId, $ip, $now);
                $angelegt++;
            } catch (BankRuleViolation) {
                $uebersprungen++;
            }
        }

        return ['angelegt' => $angelegt, 'uebersprungen' => $uebersprungen];
    }

    /**
     * Accepts one suggestion: scores the pair afresh - the page may be old -
     * and allocates what both still have open, as "Vorschlag bestätigt".
     * Refused when the pair no longer reaches the suggestion minimum or the
     * amount differs from the $betrag the page showed.
     *
     * @return int the id of the new allocation
     *
     * @throws BankRuleViolation when the pair is out of scope, no suggestion any more, or changed
     */
    public function uebernehmen(Vault $vault, Zugriffsbereich $bereich, int $invoiceId, int $transactionId, int $betrag, ?int $userId, string $ip, \DateTimeImmutable $now): int
    {
        $parameter = $this->parameter();
        $beleg = $this->belegeLaden($vault, $this->belege->abgleichKandidaten($bereich, [$invoiceId]))[0][0] ?? null;
        $buchung = $this->buchungenLaden($vault, $this->buchungen->abgleichKandidaten($bereich, null, [$transactionId]))[0][0] ?? null;
        $bewertung = $beleg === null || $buchung === null ? null : Punktebewertung::bewerten($beleg, $buchung, $parameter);
        if ($bewertung === null || $bewertung->score < $parameter->vorschlagAb) {
            throw new BankRuleViolation('Dieser Vorschlag passt nicht mehr – Beleg oder Buchung wurden inzwischen zugeordnet oder geändert.');
        }
        if ($bewertung->betrag !== $betrag) {
            throw new BankRuleViolation('Der Betrag dieses Vorschlags hat sich inzwischen geändert – bitte den neuen Vorschlag prüfen.');
        }

        return $this->zuordnung->zuordnen($vault, $invoiceId, $transactionId, $bewertung->betrag, ZuordnungMethode::VorschlagBestaetigt, $bewertung->score, $bewertung->trace(), $userId, $ip, $now);
    }

    /**
     * @param list<InvoiceRecord> $records
     *
     * @return list<array{0: AbgleichBeleg, 1: InvoiceRecord, 2: InvoiceData, 3: string}> input, record, decrypted data, supplier name
     */
    private function belegeLaden(Vault $vault, array $records): array
    {
        /** @var array<int, ?Supplier> $lieferanten */
        $lieferanten = [];
        $liste = [];
        foreach ($records as $record) {
            $daten = Pruefung::entschluesseln($vault, $record);
            $offen = $this->zuordnung->zahlungsstand($vault, $record)->offen();
            $lieferant = null;
            if ($record->supplierId !== null) {
                if (!array_key_exists($record->supplierId, $lieferanten)) {
                    $lieferanten[$record->supplierId] = $this->lieferanten->finde($vault, $record->supplierId);
                }
                $lieferant = $lieferanten[$record->supplierId];
            }
            $s = $lieferant?->data;
            $liste[] = [
                new AbgleichBeleg(
                    id: $record->id,
                    buchungsrichtung: BankTransactionDirection::from(Zuordnung::erwarteteRichtung($record)),
                    zahlungsweg: $record->paymentKind,
                    brutto: $daten->gross,
                    offen: $offen,
                    rechnungsnummer: $daten->invoiceNumber,
                    rechnungsdatum: $record->invoiceDate,
                    faelligkeit: $record->dueDate,
                    lieferantNamen: $s === null ? [] : [$s->name, ...$s->aliases],
                    lieferantIbans: $s === null ? [] : $s->ibans,
                    mandatsreferenzen: $s === null ? [] : $s->mandateRefs,
                    glaeubigerId: $s === null ? '' : $s->creditorId,
                    kundennummer: $s === null ? '' : $s->customerNumber,
                    // The submitter's IBAN arrives with the reimbursements (M10-3, issue #67).
                    einreicherIban: null,
                ),
                $record,
                $daten,
                $s === null ? '' : $s->name,
            ];
        }

        return $liste;
    }

    /**
     * @param list<BankTransactionRecord> $records
     *
     * @return list<array{0: AbgleichBuchung, 1: BankTransaction}>
     */
    private function buchungenLaden(Vault $vault, array $records): array
    {
        $liste = [];
        foreach ($records as $record) {
            $buchung = Buchungen::entschluesseln($vault, $record);
            $offen = $this->zuordnung->belegstand($vault, $record)->offen();
            $liste[] = [
                new AbgleichBuchung(
                    id: $record->id,
                    richtung: $record->direction,
                    offen: $offen,
                    buchungsdatum: $record->bookingDate,
                    gegenseiteName: $buchung->counterpartyName,
                    gegenseiteIban: $buchung->counterpartyIban,
                    verwendungszweck: $buchung->purpose,
                    endToEndReferenz: $buchung->endToEndRef,
                    mandatsreferenz: $buchung->mandateRef,
                    glaeubigerId: $buchung->creditorId,
                ),
                $buchung,
            ];
        }

        return $liste;
    }
}
