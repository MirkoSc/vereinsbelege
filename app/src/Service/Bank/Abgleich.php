<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Domain\BankTransaction;
use App\Domain\BankTransactionDirection;
use App\Domain\BankAccount;
use App\Domain\BankTransactionDocStatus;
use App\Domain\BankTransactionRecord;
use App\Domain\BankTransactionSetBy;
use App\Domain\Belegstand;
use App\Domain\Differenzgrund;
use App\Domain\InvoiceData;
use App\Domain\InvoiceRecord;
use App\Domain\Supplier;
use App\Domain\Zahlungsstand;
use App\Domain\Zugriffsbereich;
use App\Domain\ZuordnungMethode;
use App\Repository\BankTransactionRepository;
use App\Repository\InvoiceRepository;
use App\Repository\MatchRejectionRepository;
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
 * Each row is decrypted once, the allocated sums are read in batches, and
 * Punktebewertung scores candidate pairs from an index, not the cross
 * product - the run stays short (E-35).
 * Nothing is stored about a suggestion: they are scored afresh on every
 * page view, so a suggestion never outlives what it was based on. Only a
 * user's "no" to a pair is stored (M10-4, issue #68, E-41,
 * `match_rejection`) and leaves that pair out of the scoring.
 *
 * The matching page (M10-4) also lists both sides page by page, shows one
 * receipt with its allocations and the bookings that could take it, and
 * allocates by hand, releases and closes a difference - always within the
 * scope of `matching.edit`, always through Zuordnung.
 */
final readonly class Abgleich
{
    /** Bookings shown for one receipt (best first); the rest via the filters. */
    public const int KANDIDATEN_MAX = 20;

    public function __construct(
        private InvoiceRepository $belege,
        private BankTransactionRepository $buchungen,
        private SupplierService $lieferanten,
        private Zuordnung $zuordnung,
        private SettingRepository $settings,
        private MatchRejectionRepository $verworfen,
        private BankAccountService $konten,
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
        // decrypted; only the directions some open receipt expects are read,
        // and none from before the earliest open receipt's window.
        $richtungen = [];
        foreach ($belege as [$eingabe]) {
            $richtungen[$eingabe->buchungsrichtung->value] = $eingabe->buchungsrichtung;
        }
        $buchungen = $records === [] ? [] : $this->buchungenLaden($vault, $this->buchungen->abgleichKandidaten(
            $bereich,
            $records[0]->invoiceDate->modify(sprintf('-%d days', $parameter->fensterVorTage)),
            null,
            array_values($richtungen),
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
            Punktebewertung::planen(
                array_column($belege, 0),
                array_column($buchungen, 0),
                $parameter,
                $this->verworfen->paare(array_map(static fn(InvoiceRecord $r): int => $r->id, $records)),
            ),
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
        if ($bewertung === null || $bewertung->score < $parameter->vorschlagAb
            || isset($this->verworfen->paare([$invoiceId])[MatchRejectionRepository::schluessel($invoiceId, $transactionId)])
        ) {
            throw new BankRuleViolation('Dieser Vorschlag passt nicht mehr – Beleg oder Buchung wurden inzwischen zugeordnet oder geändert.');
        }
        if ($bewertung->betrag !== $betrag) {
            throw new BankRuleViolation('Der Betrag dieses Vorschlags hat sich inzwischen geändert – bitte den neuen Vorschlag prüfen.');
        }

        return $this->zuordnung->zuordnen($vault, $invoiceId, $transactionId, $bewertung->betrag, ZuordnungMethode::VorschlagBestaetigt, $bewertung->score, $bewertung->trace(), $userId, $ip, $now);
    }

    /**
     * The accounts for the filter.
     *
     * @return list<BankAccount>
     */
    public function konten(Vault $vault): array
    {
        return $this->konten->liste($vault);
    }

    /**
     * One page of each side: open receipts (checked, expecting a booking,
     * not settled) and bookings that still miss a receipt (`fehlt`/
     * `teilweise`), oldest first. Account and period filter in SQL or on
     * the structure; only amount and search text need the plaintext - then
     * every row of that side is decrypted, otherwise only the page.
     */
    public function liste(Vault $vault, Zugriffsbereich $bereich, AbgleichFilter $f): AbgleichAnzeige\Liste
    {
        $belegRecords = array_values(array_filter(
            $this->belege->abgleichKandidaten($bereich),
            static fn(InvoiceRecord $r): bool => $f->imZeitraum($r->invoiceDate),
        ));
        if ($f->vertraulich()) {
            $belege = array_values(array_filter(
                $this->belegeAnzeigen($vault, $belegRecords),
                static fn(AbgleichAnzeige\Beleg $b): bool => $f->passtZumBetrag($b->daten->gross, $b->offen)
                    && $f->passtZurSuche($b->lieferant, $b->daten->invoiceNumber, $b->daten->purposeShort),
            ));
            $belegeGesamt = count($belege);
            $belege = array_slice($belege, ($f->seiteBelege - 1) * AbgleichFilter::SEITE, AbgleichFilter::SEITE);
        } else {
            $belegeGesamt = count($belegRecords);
            $belege = $this->belegeAnzeigen($vault, array_slice($belegRecords, ($f->seiteBelege - 1) * AbgleichFilter::SEITE, AbgleichFilter::SEITE));
        }

        $buchungRecords = [];
        foreach ([BankTransactionDocStatus::Fehlt, BankTransactionDocStatus::Teilweise] as $status) {
            array_push($buchungRecords, ...$this->buchungen->liste(new BuchungFilter(kontoId: $f->kontoId, von: $f->von, bis: $f->bis, belegStatus: $status), $bereich));
        }
        usort($buchungRecords, static fn(BankTransactionRecord $a, BankTransactionRecord $b): int => [$a->bookingDate, $a->id] <=> [$b->bookingDate, $b->id]);
        if ($f->vertraulich()) {
            $buchungen = array_values(array_filter(
                $this->buchungenAnzeigen($vault, $buchungRecords),
                static fn(AbgleichAnzeige\Buchung $b): bool => $f->passtZumBetrag($b->buchung->amount, $b->offen)
                    && $f->passtZurSuche($b->buchung->counterpartyName, $b->buchung->counterpartyIban, $b->buchung->purpose),
            ));
            $buchungenGesamt = count($buchungen);
            $buchungen = array_slice($buchungen, ($f->seiteBuchungen - 1) * AbgleichFilter::SEITE, AbgleichFilter::SEITE);
        } else {
            $buchungenGesamt = count($buchungRecords);
            $buchungen = $this->buchungenAnzeigen($vault, array_slice($buchungRecords, ($f->seiteBuchungen - 1) * AbgleichFilter::SEITE, AbgleichFilter::SEITE));
        }

        return new AbgleichAnzeige\Liste($belege, $belegeGesamt, $buchungen, $buchungenGesamt);
    }

    /**
     * One receipt within $bereich: its payment state and allocations, and -
     * while it takes one - the bookings that could take it, best first,
     * with discarded pairs marked. Null when it is not there or out of scope.
     */
    public function detail(Vault $vault, Zugriffsbereich $bereich, int $invoiceId, AbgleichFilter $f): ?AbgleichAnzeige\Detail
    {
        $record = $this->belegImBereich($bereich, $invoiceId);
        if ($record === null) {
            return null;
        }
        $beleg = $this->belegeAnzeigen($vault, [$record])[0];
        $stand = $this->zuordnung->zahlungsstand($vault, $record);

        $zuordnungen = [];
        foreach ($this->zuordnung->fuerBeleg($vault, $invoiceId) as $z) {
            $buchung = $this->buchungImBereich($bereich, $z->transactionId);
            $zuordnungen[] = new AbgleichAnzeige\ZuordnungZeile($z, $buchung === null ? null : Buchungen::entschluesseln($vault, $buchung));
        }

        $nimmtAn = $this->belege->abgleichKandidaten($bereich, [$invoiceId]) !== [];
        $kandidaten = [];
        if ($nimmtAn && $stand->offen() > 0) {
            $parameter = $this->parameter();
            $eingabe = $this->belegeLaden($vault, [$record])[0][0];
            $verworfen = $this->verworfen->paare([$invoiceId]);
            $records = array_values(array_filter(
                $this->buchungen->abgleichKandidaten($bereich, null, null, [$eingabe->buchungsrichtung]),
                static fn(BankTransactionRecord $r): bool => $r->docSource !== BankTransactionSetBy::Umbuchung
                    && ($f->kontoId === null || $r->accountId === $f->kontoId)
                    && $f->imZeitraum($r->bookingDate),
            ));
            foreach ($this->buchungenLaden($vault, $records) as [$b, $buchung]) {
                if ($b->offen <= 0 || strtoupper($b->waehrung) !== strtoupper($eingabe->waehrung)
                    || !$f->passtZumBetrag($buchung->amount, $b->offen)
                    || !$f->passtZurSuche($buchung->counterpartyName, $buchung->counterpartyIban, $buchung->purpose)
                ) {
                    continue;
                }
                $kandidaten[] = new AbgleichAnzeige\Kandidat(
                    new AbgleichAnzeige\Buchung($buchung, $b->offen),
                    Punktebewertung::bewerten($eingabe, $b, $parameter),
                    isset($verworfen[MatchRejectionRepository::schluessel($invoiceId, $b->id)]),
                    min($eingabe->offen, $b->offen),
                );
            }
            // Best first; a discarded pair after the others; then the nearest date.
            $tag = (int) $record->invoiceDate->format('U');
            usort($kandidaten, static fn(AbgleichAnzeige\Kandidat $a, AbgleichAnzeige\Kandidat $b): int => [
                $a->verworfen,
                -($a->bewertung->score ?? 0),
                abs((int) $a->buchung->buchung->bookingDate->format('U') - $tag),
                $a->buchung->buchung->id,
            ] <=> [
                $b->verworfen,
                -($b->bewertung->score ?? 0),
                abs((int) $b->buchung->buchung->bookingDate->format('U') - $tag),
                $b->buchung->buchung->id,
            ]);
        }

        return new AbgleichAnzeige\Detail(
            $beleg,
            $stand,
            $nimmtAn,
            $zuordnungen,
            array_slice($kandidaten, 0, self::KANDIDATEN_MAX),
            count($kandidaten),
        );
    }

    /**
     * Allocates by hand - any open booking of the right direction, any part
     * up to what both still have open (Zuordnung checks under its locks).
     * The points of the pair are kept for traceability.
     *
     * @return int the id of the new allocation
     *
     * @throws BankRuleViolation
     */
    public function zuordnenVonHand(Vault $vault, Zugriffsbereich $bereich, int $invoiceId, int $transactionId, int $betrag, ?int $userId, string $ip, \DateTimeImmutable $now): int
    {
        $beleg = $this->belegeLaden($vault, $this->belege->abgleichKandidaten($bereich, [$invoiceId]))[0][0] ?? null;
        if ($beleg === null) {
            throw new BankRuleViolation('Dieser Beleg nimmt keine Zuordnung an – er ist ausgeglichen, nicht geprüft, erwartet keine Buchung oder liegt außerhalb Ihres Bereichs.');
        }
        $buchung = $this->buchungenLaden($vault, $this->buchungen->abgleichKandidaten($bereich, null, [$transactionId]))[0][0] ?? null;
        if ($buchung === null) {
            throw new BankRuleViolation('Diese Buchung nimmt keinen Beleg mehr an oder liegt außerhalb Ihres Bereichs.');
        }
        $bewertung = Punktebewertung::bewerten($beleg, $buchung, $this->parameter());

        return $this->zuordnung->zuordnen($vault, $invoiceId, $transactionId, $betrag, ZuordnungMethode::Manuell, $bewertung?->score, $bewertung?->trace(), $userId, $ip, $now);
    }

    /**
     * Releases one allocation of the receipt - both sides must lie within
     * $bereich.
     *
     * @throws BankRuleViolation
     */
    public function loesen(Vault $vault, Zugriffsbereich $bereich, int $invoiceId, int $allocationId, ?int $userId, string $ip, \DateTimeImmutable $now): void
    {
        if ($this->belegImBereich($bereich, $invoiceId) === null) {
            throw new BankRuleViolation('Diesen Beleg gibt es nicht oder er liegt außerhalb Ihres Bereichs.');
        }
        $zuordnung = null;
        foreach ($this->zuordnung->fuerBeleg($vault, $invoiceId) as $z) {
            if ($z->id === $allocationId) {
                $zuordnung = $z;
            }
        }
        if ($zuordnung === null) {
            throw new BankRuleViolation('Diese Zuordnung gibt es nicht (mehr).');
        }
        if ($this->buchungImBereich($bereich, $zuordnung->transactionId) === null) {
            throw new BankRuleViolation('Die Buchung dieser Zuordnung liegt außerhalb Ihres Bereichs.');
        }
        $this->zuordnung->loesen($vault, $allocationId, $userId, $ip, $now);
    }

    /**
     * Closes or reopens the difference of a receipt within $bereich.
     *
     * @throws BankRuleViolation
     */
    public function differenz(Vault $vault, Zugriffsbereich $bereich, int $invoiceId, ?Differenzgrund $grund, ?int $userId, string $ip, \DateTimeImmutable $now): void
    {
        if ($this->belegImBereich($bereich, $invoiceId) === null) {
            throw new BankRuleViolation('Diesen Beleg gibt es nicht oder er liegt außerhalb Ihres Bereichs.');
        }
        if ($grund === null) {
            $this->zuordnung->differenzOeffnen($vault, $invoiceId, $userId, $ip, $now);
        } else {
            $this->zuordnung->differenzAbschliessen($vault, $invoiceId, $grund, $userId, $ip, $now);
        }
    }

    /**
     * Discards a suggested pair: it is no longer suggested or allocated
     * automatically. Both sides must still be candidates within $bereich.
     *
     * @return bool whether it was newly discarded
     *
     * @throws BankRuleViolation
     */
    public function verwerfen(Zugriffsbereich $bereich, int $invoiceId, int $transactionId, ?int $userId, \DateTimeImmutable $now): bool
    {
        if ($this->belege->abgleichKandidaten($bereich, [$invoiceId]) === []
            || $this->buchungen->abgleichKandidaten($bereich, null, [$transactionId]) === []
        ) {
            throw new BankRuleViolation('Dieser Vorschlag passt nicht mehr – Beleg oder Buchung wurden inzwischen zugeordnet oder geändert.');
        }

        return $this->verworfen->verwerfen($invoiceId, $transactionId, $userId, $now);
    }

    /**
     * Takes a discard back - the pair is scored again.
     *
     * @return bool whether there was one
     *
     * @throws BankRuleViolation
     */
    public function wiederVorschlagen(Zugriffsbereich $bereich, int $invoiceId, int $transactionId): bool
    {
        if ($this->belegImBereich($bereich, $invoiceId) === null || $this->buchungImBereich($bereich, $transactionId) === null) {
            throw new BankRuleViolation('Beleg oder Buchung gibt es nicht oder sie liegen außerhalb Ihres Bereichs.');
        }

        return $this->verworfen->zuruecknehmen($invoiceId, $transactionId);
    }

    private function belegImBereich(Zugriffsbereich $bereich, int $invoiceId): ?InvoiceRecord
    {
        $record = $this->belege->find($invoiceId);

        return $record !== null && $bereich->erlaubt($record->costCenterId, $record->invoiceDate) ? $record : null;
    }

    /** A booking has no cost center: a cost-center scope sees none (E-35). */
    private function buchungImBereich(Zugriffsbereich $bereich, int $transactionId): ?BankTransactionRecord
    {
        $record = $this->buchungen->find($transactionId);

        return $record !== null && $bereich->erlaubt(null, $record->bookingDate) ? $record : null;
    }

    /**
     * @param list<InvoiceRecord> $records
     *
     * @return list<AbgleichAnzeige\Beleg>
     */
    private function belegeAnzeigen(Vault $vault, array $records): array
    {
        return array_map(
            static fn(array $b): AbgleichAnzeige\Beleg => new AbgleichAnzeige\Beleg($b[1], $b[2], $b[3], $b[0]->offen),
            $this->belegeLaden($vault, $records),
        );
    }

    /**
     * @param list<BankTransactionRecord> $records
     *
     * @return list<AbgleichAnzeige\Buchung>
     */
    private function buchungenAnzeigen(Vault $vault, array $records): array
    {
        return array_map(
            static fn(array $b): AbgleichAnzeige\Buchung => new AbgleichAnzeige\Buchung($b[1], $b[0]->offen),
            $this->buchungenLaden($vault, $records),
        );
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
        $summen = $this->zuordnung->summenJeBeleg($vault, array_map(static fn(InvoiceRecord $r): int => $r->id, $records));
        foreach ($records as $record) {
            $daten = Pruefung::entschluesseln($vault, $record);
            $offen = (new Zahlungsstand($record->paymentKind, $daten->gross, $summen[$record->id] ?? 0, $record->differenceReason))->offen();
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
                    waehrung: $daten->currency,
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
        $summen = $this->zuordnung->summenJeBuchung($vault, array_map(static fn(BankTransactionRecord $r): int => $r->id, $records));
        foreach ($records as $record) {
            $buchung = Buchungen::entschluesseln($vault, $record);
            $offen = (new Belegstand($record->docRequired, $buchung->amount, $summen[$record->id] ?? 0))->offen();
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
                    waehrung: $buchung->currency,
                ),
                $buchung,
            ];
        }

        return $liste;
    }
}
