<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Domain\AuditAction;
use App\Domain\BankTransaction;
use App\Domain\BankTransactionDirection;
use App\Domain\BankTransactionRecord;
use App\Domain\BankTransactionSetBy;
use App\Domain\Umbuchung;
use App\Domain\UmbuchungMethode;
use App\Domain\Zugriffsbereich;
use App\Repository\BankTransactionRepository;
use App\Repository\TransferRepository;
use App\Service\Audit\AuditLog;
use App\Service\Bank\Import\KontoauszugImport;
use App\Service\Crypto\Vault;

/**
 * Transfers between the club's own accounts (M10-6, issue #70, docs/spec/
 * 04-bank-und-abgleich.md section 5 "Stand M10-6", E-27, E-38): money moved
 * from one own account (bank or cash box) to another is neither income nor
 * expense. The two bookings are paired in `transfer` - booking <-> booking,
 * not an allocation - and both are marked `doc_source = umbuchung`, which
 * means "no receipt needed: it is a transfer". This service is the only
 * place that pairs, unpairs or writes that mark.
 *
 * - A pair is an expense and an income of the same amount on two different
 *   accounts. Neither may be paired already or have a receipt allocated
 *   (a transfer needs none; App\Service\Bank\Zuordnung refuses a paired
 *   booking in turn). Both rows are locked in id order while this is
 *   checked.
 * - Recognised automatically (erkennen()) when a booking's counterparty
 *   IBAN is the IBAN of another own account and exactly one counter booking
 *   fits - and that one has no other candidate either. Anything ambiguous is
 *   left to a person (paaren()).
 * - Unpairing hands both receipt statuses back to the default of their
 *   direction (App\Service\Bank\BelegStandard).
 * - The audit log gets the two ids and the method, never an amount.
 */
final readonly class Umbuchungen
{
    /** Days between the two bookings of a recognised transfer, at most (bank working days, weekends). */
    public const int FENSTER_TAGE = 5;

    /** Days around a booking the manual choice offers counter bookings from. */
    public const int AUSWAHL_TAGE = 31;

    public function __construct(
        private \PDO $pdo,
        private TransferRepository $umbuchungen,
        private BankTransactionRepository $buchungen,
        private BankAccountService $konten,
        private AuditLog $audit,
        private BelegStandard $belegStandard = new BelegStandard(),
    ) {
    }

    /**
     * Pairs two bookings as a transfer. Returns the `transfer` id.
     *
     * @throws BankRuleViolation
     */
    public function paaren(Vault $vault, int $buchungA, int $buchungB, UmbuchungMethode $methode, ?int $userId, string $ip, \DateTimeImmutable $now): int
    {
        if ($buchungA === $buchungB) {
            throw new BankRuleViolation('Eine Buchung lässt sich nicht mit sich selbst verknüpfen.', 'gegenbuchung');
        }

        $this->pdo->beginTransaction();
        try {
            [$ausgabe, $einnahme] = $this->pruefen($vault, ...$this->sperren($buchungA, $buchungB));

            $id = $this->umbuchungen->insert($ausgabe->id, $einnahme->id, $methode, $userId, $now)
                ?? throw new BankRuleViolation('Eine der beiden Buchungen ist bereits als Umbuchung verknüpft.', 'gegenbuchung');
            $this->umbuchungen->markieren($ausgabe->id, $now);
            $this->umbuchungen->markieren($einnahme->id, $now);

            $this->audit->record(AuditAction::UmbuchungVerknuepft, $userId, $ip, $id, [
                'ausgabe_id' => $ausgabe->id,
                'einnahme_id' => $einnahme->id,
                'method' => $methode->value,
            ], $now);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return $id;
    }

    /**
     * Unpairs the transfer $transactionId is part of; both bookings get the
     * receipt status of their direction back. Needs no vault - nothing is
     * read but structure.
     *
     * @throws BankRuleViolation
     */
    public function loesen(int $transactionId, ?int $userId, string $ip, \DateTimeImmutable $now): void
    {
        $this->pdo->beginTransaction();
        try {
            $umbuchung = $this->umbuchungen->forTransaction($transactionId)
                ?? throw new BankRuleViolation('Diese Buchung ist nicht als Umbuchung verknüpft.');
            [$a, $b] = $this->sperren($umbuchung->ausgabeId, $umbuchung->einnahmeId);
            if (!$this->umbuchungen->delete($umbuchung->id)) {
                throw new BankRuleViolation('Diese Umbuchung gibt es nicht (mehr).');
            }
            foreach ([$a, $b] as $buchung) {
                $this->umbuchungen->aufheben($buchung->id, $this->belegStandard->noetig($buchung->direction), $now);
            }

            $this->audit->record(AuditAction::UmbuchungGeloest, $userId, $ip, $umbuchung->id, [
                'ausgabe_id' => $umbuchung->ausgabeId,
                'einnahme_id' => $umbuchung->einnahmeId,
                'method' => $umbuchung->method->value,
            ], $now);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Recognises transfers among the bookings in $bereich and pairs the
     * unambiguous ones (`auto`): the counterparty IBAN of one booking is the
     * IBAN of another own account, and on that account exactly one booking
     * of the opposite direction and the same amount lies within
     * FENSTER_TAGE - whose own counterparty is empty or the first account -,
     * and it has no second candidate either. Returns how many were paired.
     * Called after every statement import and from the booking list.
     */
    public function erkennen(
        Vault $vault,
        Zugriffsbereich $bereich,
        ?int $userId,
        string $ip,
        \DateTimeImmutable $now,
        ?\DateTimeImmutable $von = null,
        ?\DateTimeImmutable $bis = null,
    ): int {
        // Own IBAN -> account, keyed by the blind index the import writes
        // into `counterparty_bi`. A cash box has no IBAN.
        $kontoNachBi = [];
        $biNachKonto = [];
        foreach ($this->konten->liste($vault) as $konto) {
            if ($konto->data->iban === '') {
                continue;
            }
            $bi = $vault->blindIndex()->forValue(KontoauszugImport::COUNTERPARTY_PURPOSE, $konto->data->iban);
            $kontoNachBi[$bi] = $konto->id;
            $biNachKonto[$konto->id] = $bi;
        }
        if ($kontoNachBi === []) {
            return 0;
        }

        $gepaart = [];
        $anzahl = 0;
        foreach ($this->umbuchungen->mitEigenerGegenseite(array_keys($kontoNachBi), $bereich, $von, $bis) as [$bi, $record]) {
            $zielKonto = $kontoNachBi[$bi];
            if ($zielKonto === $record->accountId || isset($gepaart[$record->id])) {
                continue;
            }
            $buchung = Buchungen::entschluesseln($vault, $record);
            $kandidaten = $this->passende($vault, $buchung, $zielKonto, $biNachKonto[$record->accountId] ?? null, $bereich);
            if (count($kandidaten) !== 1 || isset($gepaart[$kandidaten[0]->id])) {
                continue;
            }
            $gegen = $kandidaten[0];
            $zurueck = $this->passende($vault, $gegen, $buchung->accountId, $biNachKonto[$gegen->accountId] ?? null, $bereich);
            if (count($zurueck) !== 1 || $zurueck[0]->id !== $buchung->id) {
                continue;
            }
            try {
                $this->paaren($vault, $buchung->id, $gegen->id, UmbuchungMethode::Auto, $userId, $ip, $now);
            } catch (BankRuleViolation) {
                // Changed meanwhile (a receipt allocated, paired by hand): leave it.
                continue;
            }
            $gepaart[$buchung->id] = true;
            $gepaart[$gegen->id] = true;
            ++$anzahl;
        }

        return $anzahl;
    }

    /**
     * The recognition at the end of a statement import: starting points
     * within the file's dates (plus the window, so a counter booking of this
     * file finds an earlier start), keeping the last step short. Over all
     * bookings - pairing writes structure only.
     */
    public function nachImport(Vault $vault, int $importId, ?int $userId, string $ip, \DateTimeImmutable $now): int
    {
        $zeitraum = $this->umbuchungen->zeitraumDesImports($importId);
        if ($zeitraum === null) {
            return 0;
        }

        return $this->erkennen(
            $vault,
            Zugriffsbereich::unbeschraenkt(),
            $userId,
            $ip,
            $now,
            $zeitraum[0]->modify('-' . self::FENSTER_TAGE . ' days'),
            $zeitraum[1]->modify('+' . self::FENSTER_TAGE . ' days'),
        );
    }

    /** The transfer a booking is part of, if any. */
    public function fuerBuchung(int $transactionId): ?Umbuchung
    {
        return $this->umbuchungen->forTransaction($transactionId);
    }

    /**
     * What a person may pair $buchung with by hand: bookings on another
     * account, opposite direction, same amount, within AUSWAHL_TAGE, not
     * paired, no receipt allocated - within $bereich. Nearest date first.
     *
     * @return list<BankTransaction>
     */
    public function auswahl(Vault $vault, BankTransaction $buchung, Zugriffsbereich $bereich): array
    {
        if ($buchung->docSource === BankTransactionSetBy::Umbuchung || $buchung->docStatus->hatBeleg() || $buchung->amount === 0) {
            return [];
        }
        $treffer = [];
        foreach ($this->umbuchungen->offeneGegenbuchungen(
            null,
            $buchung->accountId,
            self::gegenrichtung($buchung->direction),
            $buchung->bookingDate->modify('-' . self::AUSWAHL_TAGE . ' days'),
            $buchung->bookingDate->modify('+' . self::AUSWAHL_TAGE . ' days'),
            $bereich,
        ) as [, $record]) {
            $gegen = Buchungen::entschluesseln($vault, $record);
            if (abs($gegen->amount) === abs($buchung->amount)) {
                $treffer[] = $gegen;
            }
        }
        $tage = static fn(BankTransaction $b): int => abs((int) $b->bookingDate->diff($buchung->bookingDate)->days);
        usort($treffer, static fn(BankTransaction $x, BankTransaction $y): int => [$tage($x), $x->id] <=> [$tage($y), $y->id]);

        return $treffer;
    }

    /**
     * Counter bookings of $buchung on $kontoId for the recognition: opposite
     * direction, same amount, within FENSTER_TAGE, and their counterparty is
     * empty or $eigenerBi (the IBAN of $buchung's own account).
     *
     * @return list<BankTransaction>
     */
    private function passende(Vault $vault, BankTransaction $buchung, int $kontoId, ?string $eigenerBi, Zugriffsbereich $bereich): array
    {
        $treffer = [];
        foreach ($this->umbuchungen->offeneGegenbuchungen(
            $kontoId,
            null,
            self::gegenrichtung($buchung->direction),
            $buchung->bookingDate->modify('-' . self::FENSTER_TAGE . ' days'),
            $buchung->bookingDate->modify('+' . self::FENSTER_TAGE . ' days'),
            $bereich,
        ) as [$bi, $record]) {
            if ($bi !== null && ($eigenerBi === null || !hash_equals($eigenerBi, $bi))) {
                continue;
            }
            $gegen = Buchungen::entschluesseln($vault, $record);
            if (abs($gegen->amount) === abs($buchung->amount) && $gegen->amount !== 0) {
                $treffer[] = $gegen;
            }
        }

        return $treffer;
    }

    /**
     * Locks both bookings, lower id first - the same order everywhere, so
     * two requests pairing overlapping bookings cannot deadlock.
     *
     * @return array{BankTransactionRecord, BankTransactionRecord}
     *
     * @throws BankRuleViolation
     */
    private function sperren(int $a, int $b): array
    {
        $erste = $this->buchungen->find(min($a, $b), sperren: true);
        $zweite = $this->buchungen->find(max($a, $b), sperren: true);
        if ($erste === null || $zweite === null) {
            throw new BankRuleViolation('Diese Buchung gibt es nicht (mehr).', 'gegenbuchung');
        }

        return $erste->id === $a ? [$erste, $zweite] : [$zweite, $erste];
    }

    /**
     * The rules of a pair, checked under the row locks.
     *
     * @return array{BankTransactionRecord, BankTransactionRecord} expense, income
     *
     * @throws BankRuleViolation
     */
    private function pruefen(Vault $vault, BankTransactionRecord $a, BankTransactionRecord $b): array
    {
        foreach ([$a, $b] as $record) {
            if ($record->docSource === BankTransactionSetBy::Umbuchung) {
                throw new BankRuleViolation('Eine der beiden Buchungen ist bereits als Umbuchung verknüpft – erst diese Umbuchung lösen.', 'gegenbuchung');
            }
            if ($record->docStatus->hatBeleg()) {
                throw new BankRuleViolation('Einer der beiden Buchungen ist ein Beleg zugeordnet – eine Umbuchung braucht keinen Beleg; erst die Zuordnung lösen.', 'gegenbuchung');
            }
        }
        if ($a->accountId === $b->accountId) {
            throw new BankRuleViolation('Beide Buchungen liegen auf demselben Konto – eine Umbuchung verbindet zwei verschiedene eigene Konten.', 'gegenbuchung');
        }
        if ($a->direction === $b->direction) {
            throw new BankRuleViolation('Eine Umbuchung besteht aus einer Ausgabe auf dem einen und einer Einnahme auf dem anderen Konto.', 'gegenbuchung');
        }
        $betragA = Buchungen::entschluesseln($vault, $a)->amount;
        $betragB = Buchungen::entschluesseln($vault, $b)->amount;
        if ($betragA === 0 || abs($betragA) !== abs($betragB)) {
            throw new BankRuleViolation('Die Beträge der beiden Buchungen sind verschieden – eine Umbuchung bewegt denselben Betrag.', 'gegenbuchung');
        }

        return $a->direction === BankTransactionDirection::Ausgabe ? [$a, $b] : [$b, $a];
    }

    private static function gegenrichtung(BankTransactionDirection $richtung): BankTransactionDirection
    {
        return $richtung === BankTransactionDirection::Ausgabe ? BankTransactionDirection::Einnahme : BankTransactionDirection::Ausgabe;
    }
}
