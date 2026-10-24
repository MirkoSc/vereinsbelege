<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Domain\Allocation;
use App\Domain\AllocationRecord;
use App\Domain\AuditAction;
use App\Domain\BankAccountKind;
use App\Domain\BankTransactionRecord;
use App\Domain\Belegstand;
use App\Domain\Differenzgrund;
use App\Domain\InvoiceRecord;
use App\Domain\InvoiceType;
use App\Domain\Zahlungsstand;
use App\Domain\Zahlungsweg;
use App\Domain\ZuordnungMethode;
use App\Repository\AllocationRepository;
use App\Repository\BankTransactionRepository;
use App\Repository\InvoiceRepository;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\Vault;
use App\Service\Invoice\Pruefung;

/**
 * Matching receipt <-> booking (M10-1, issue #65, docs/spec/
 * 04-bank-und-abgleich.md section 5 "Modell", E-27): the `allocation` rows
 * and the two statuses derived from them.
 *
 * - An allocation says how much of a receipt's gross one booking settles
 *   (`amount_enc`, integer cents, always positive, own DEK, AAD
 *   "allocation|<id>|amount_enc"). n:m with partial amounts covers 1:1, a
 *   collective transfer for several invoices, several instalments for one
 *   invoice, a cash discount (closed as a difference with a reason) and a
 *   credit note (settled by a booking of the opposite direction, or netted
 *   - then the invoice closes with the reason "Gutschrift" and the credit
 *   note itself is `Zahlungsweg::Keine`).
 * - `invoice.payment_status` and `bank_transaction.doc_status`
 *   (`teilweise`/`zugeordnet`) are DERIVED here from the sums, after every
 *   change, inside the same transaction - nobody sets them by hand. Both
 *   sides are locked (SELECT ... FOR UPDATE) while the sums are checked, so
 *   two requests cannot allocate the same open amount twice.
 * - Everything needs the unlocked vault: the amounts are ciphertext.
 * - The audit log gets ids and the method, never an amount.
 *
 * Scoring and suggestions (M10-2), reimbursements (M10-3), the page (M10-4),
 * the lists (M10-5) and transfers between own accounts (M10-6) build on
 * this; none of them writes an allocation or a status another way.
 */
final readonly class Zuordnung
{
    private const string TABLE = 'allocation';

    private const string COLUMN = 'amount_enc';

    public function __construct(
        private \PDO $pdo,
        private AllocationRepository $zuordnungen,
        private InvoiceRepository $belege,
        private BankTransactionRepository $buchungen,
        private BankAccountService $konten,
        private AuditLog $audit,
    ) {
    }

    /**
     * Links a receipt with a booking for $betrag cents of the receipt.
     *
     * @param array<string, mixed>|null $ruleTrace the signals that fired (M10-2) - keys and points, no values
     *
     * @return int the id of the new `allocation` row
     *
     * @throws BankRuleViolation
     */
    public function zuordnen(
        Vault $vault,
        int $invoiceId,
        int $transactionId,
        int $betrag,
        ZuordnungMethode $methode,
        ?int $score,
        ?array $ruleTrace,
        ?int $userId,
        string $ip,
        \DateTimeImmutable $now,
    ): int {
        if ($betrag <= 0) {
            throw new BankRuleViolation('Der zugeordnete Betrag muss größer als null sein.', 'betrag');
        }

        $this->pdo->beginTransaction();
        try {
            $beleg = $this->belege->find($invoiceId, sperren: true)
                ?? throw new BankRuleViolation('Diesen Beleg gibt es nicht.');
            $buchung = $this->buchungen->find($transactionId, sperren: true)
                ?? throw new BankRuleViolation('Diese Buchung gibt es nicht.');

            if (!$beleg->paymentKind->erwartetBuchung()) {
                throw new BankRuleViolation(sprintf(
                    'Für diesen Beleg wird keine Buchung erwartet (Zahlungsweg „%s“) – zuerst den Zahlungsweg in der Prüfansicht ändern.',
                    $beleg->paymentKind->label(),
                ));
            }
            if ($buchung->direction->value !== self::erwarteteRichtung($beleg)) {
                throw new BankRuleViolation(
                    $beleg->docType === InvoiceType::Gutschrift
                        ? 'Eine Gutschrift wird durch eine Buchung der Gegenrichtung ausgeglichen – oder mit der Rechnung verrechnet (Differenzgrund „Gutschrift“).'
                        : 'Richtung von Beleg und Buchung passen nicht zusammen (Ausgabe ↔ Einnahme).',
                );
            }

            $stand = $this->zahlungsstand($vault, $beleg);
            if ($betrag > $stand->offen()) {
                throw new BankRuleViolation('Der Betrag übersteigt den offenen Betrag des Belegs.', 'betrag');
            }
            $belegstand = $this->belegstand($vault, $buchung);
            if ($betrag > $belegstand->offen()) {
                throw new BankRuleViolation('Der Betrag übersteigt den noch nicht zugeordneten Betrag der Buchung.', 'betrag');
            }

            $key = DataKey::generate();
            $id = $this->zuordnungen->insert($invoiceId, $transactionId, $vault->sealDataKey($key), $methode, $score, $ruleTrace, $userId, $now)
                ?? throw new BankRuleViolation('Beleg und Buchung sind einander bereits zugeordnet – zum Ändern die Zuordnung lösen und neu anlegen.');
            $this->zuordnungen->setAmount($id, FieldCipher::encrypt($key, (string) $betrag, new FieldContext(self::TABLE, $id, self::COLUMN)));

            $this->belege->setzeZahlungsstatus($invoiceId, (new Zahlungsstand($stand->weg, $stand->brutto, $stand->zugeordnet + $betrag, $stand->differenz))->status(), $stand->differenz);
            $this->buchungen->setzeBelegstatus($transactionId, (new Belegstand($belegstand->belegNoetig, $belegstand->betrag, $belegstand->zugeordnet + $betrag))->status(), $now);

            $this->audit->record(AuditAction::ZuordnungAngelegt, $userId, $ip, $id, [
                'invoice_id' => $invoiceId,
                'transaction_id' => $transactionId,
                'method' => $methode->value,
                'score' => $score,
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
     * Removes one allocation; both statuses follow the remaining ones.
     *
     * @throws BankRuleViolation
     */
    public function loesen(Vault $vault, int $allocationId, ?int $userId, string $ip, \DateTimeImmutable $now): void
    {
        $this->pdo->beginTransaction();
        try {
            $zuordnung = $this->zuordnungen->find($allocationId)
                ?? throw new BankRuleViolation('Diese Zuordnung gibt es nicht (mehr).');
            $this->belege->find($zuordnung->invoiceId, sperren: true);
            $this->buchungen->find($zuordnung->transactionId, sperren: true);

            if (!$this->zuordnungen->delete($allocationId)) {
                throw new BankRuleViolation('Diese Zuordnung gibt es nicht (mehr).');
            }
            $this->belegNeuBerechnen($vault, $zuordnung->invoiceId);
            $this->buchungNeuBerechnen($vault, $zuordnung->transactionId, $now);

            $this->audit->record(AuditAction::ZuordnungGeloest, $userId, $ip, $allocationId, [
                'invoice_id' => $zuordnung->invoiceId,
                'transaction_id' => $zuordnung->transactionId,
                'method' => $zuordnung->method->value,
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
     * Closes what the allocations leave open - a cash discount, a credit
     * note netted, something else - so the receipt counts as settled.
     * Only while something is allocated and something is missing.
     *
     * @throws BankRuleViolation
     */
    public function differenzAbschliessen(Vault $vault, int $invoiceId, Differenzgrund $grund, ?int $userId, string $ip, \DateTimeImmutable $now): void
    {
        $this->pdo->beginTransaction();
        try {
            $beleg = $this->belege->find($invoiceId, sperren: true)
                ?? throw new BankRuleViolation('Diesen Beleg gibt es nicht.');
            if ($beleg->differenceReason !== null) {
                throw new BankRuleViolation('Die Differenz dieses Belegs ist bereits abgeschlossen.');
            }
            $stand = $this->zahlungsstand($vault, $beleg);
            if (!$stand->weg->erwartetBuchung() || $stand->zugeordnet <= 0 || $stand->differenzbetrag() <= 0) {
                throw new BankRuleViolation('Eine Differenz lässt sich nur abschließen, wenn ein Teil des Belegs zugeordnet ist und ein Rest fehlt.');
            }

            $neu = new Zahlungsstand($stand->weg, $stand->brutto, $stand->zugeordnet, $grund);
            $this->belege->setzeZahlungsstatus($invoiceId, $neu->status(), $grund);
            $this->audit->record(AuditAction::BelegDifferenzAbgeschlossen, $userId, $ip, $beleg->documentId, ['grund' => $grund->value], $now);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Takes a closed difference back: the receipt is open again for what
     * the allocations do not cover.
     *
     * @throws BankRuleViolation
     */
    public function differenzOeffnen(Vault $vault, int $invoiceId, ?int $userId, string $ip, \DateTimeImmutable $now): void
    {
        $this->pdo->beginTransaction();
        try {
            $beleg = $this->belege->find($invoiceId, sperren: true)
                ?? throw new BankRuleViolation('Diesen Beleg gibt es nicht.');
            if ($beleg->differenceReason === null) {
                throw new BankRuleViolation('Für diesen Beleg ist keine Differenz abgeschlossen.');
            }
            $stand = $this->zahlungsstand($vault, $beleg);
            $neu = new Zahlungsstand($stand->weg, $stand->brutto, $stand->zugeordnet, null);
            $this->belege->setzeZahlungsstatus($invoiceId, $neu->status(), null);
            $this->audit->record(AuditAction::BelegDifferenzGeoeffnet, $userId, $ip, $beleg->documentId, ['grund' => $beleg->differenceReason->value], $now);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Derives the receipt's status from its allocations and writes it - after
     * the review page changed the Zahlungsweg or the gross amount (App\
     * Service\Invoice\Pruefung), or after an allocation went. Within the
     * caller's transaction.
     */
    public function belegNeuBerechnen(Vault $vault, int $invoiceId): Zahlungsstand
    {
        $beleg = $this->belege->find($invoiceId)
            ?? throw new BankRuleViolation('Diesen Beleg gibt es nicht.');
        $stand = $this->zahlungsstand($vault, $beleg);
        // A closed difference needs something allocated and something
        // missing; once that is no longer so, it goes.
        if ($stand->differenz !== null && ($stand->zugeordnet <= 0 || $stand->differenzbetrag() <= 0)) {
            $stand = new Zahlungsstand($stand->weg, $stand->brutto, $stand->zugeordnet, null);
        }
        $this->belege->setzeZahlungsstatus($invoiceId, $stand->status(), $stand->differenz);

        return $stand;
    }

    /** The booking-side twin of belegNeuBerechnen(). */
    public function buchungNeuBerechnen(Vault $vault, int $transactionId, \DateTimeImmutable $now): Belegstand
    {
        $buchung = $this->buchungen->find($transactionId)
            ?? throw new BankRuleViolation('Diese Buchung gibt es nicht.');
        $stand = $this->belegstand($vault, $buchung);
        $this->buchungen->setzeBelegstatus($transactionId, $stand->status(), $now);

        return $stand;
    }

    /** Gross, allocated sum, Zahlungsweg and closed difference of one receipt - decrypted. */
    public function zahlungsstand(Vault $vault, InvoiceRecord $beleg): Zahlungsstand
    {
        return new Zahlungsstand(
            $beleg->paymentKind,
            Pruefung::entschluesseln($vault, $beleg)->gross,
            $this->summe($vault, $this->zuordnungen->forInvoice($beleg->id)),
            $beleg->differenceReason,
        );
    }

    /** Amount, allocated sum and need of one booking - decrypted. */
    public function belegstand(Vault $vault, BankTransactionRecord $buchung): Belegstand
    {
        return new Belegstand(
            $buchung->docRequired,
            Buchungen::entschluesseln($vault, $buchung)->amount,
            $this->summe($vault, $this->zuordnungen->forTransaction($buchung->id)),
        );
    }

    public function hatZuordnungen(int $invoiceId): bool
    {
        return $this->zuordnungen->countForInvoice($invoiceId) > 0;
    }

    /**
     * @return list<Allocation>
     */
    public function fuerBeleg(Vault $vault, int $invoiceId): array
    {
        return array_map(fn(AllocationRecord $r): Allocation => $this->entschluesseln($vault, $r), $this->zuordnungen->forInvoice($invoiceId));
    }

    /**
     * @return list<Allocation>
     */
    public function fuerBuchung(Vault $vault, int $transactionId): array
    {
        return array_map(fn(AllocationRecord $r): Allocation => $this->entschluesseln($vault, $r), $this->zuordnungen->forTransaction($transactionId));
    }

    /**
     * What the ZIP export writes about the payment of each receipt (issue
     * #76, "bezahlt am", "Konto", "Referenz", `{kasse}`): filled from the
     * settling bookings once the receipt is settled - a partly paid receipt
     * reads as unpaid, its payment is not done. A receipt paid in cash
     * without a cash-book entry (`Zahlungsweg::Bar`) only sets `{kasse}`.
     *
     * @param list<InvoiceRecord> $belege
     *
     * @return array<int, Zahlungsangaben> by invoice id; receipts without anything to say are left out
     */
    public function zahlungsangaben(Vault $vault, array $belege): array
    {
        $angaben = [];
        $ids = [];
        foreach ($belege as $beleg) {
            if ($beleg->paymentKind->erwartetBuchung() && $beleg->paymentStatus->ausgeglichen()) {
                $ids[] = $beleg->id;
            } elseif ($beleg->paymentKind === Zahlungsweg::Bar) {
                $angaben[$beleg->id] = new Zahlungsangaben(kasse: true);
            }
        }
        if ($ids === []) {
            return $angaben;
        }

        $konten = [];
        foreach ($this->konten->liste($vault) as $konto) {
            $konten[$konto->id] = $konto;
        }
        $buchungen = [];
        foreach ($this->zuordnungen->forInvoices($ids) as $invoiceId => $zuordnungen) {
            $bezahltAm = null;
            $namen = [];
            $referenzen = [];
            $nurKasse = true;
            foreach ($zuordnungen as $zuordnung) {
                $record = $this->buchungen->find($zuordnung->transactionId);
                if ($record === null) {
                    continue;
                }
                $buchungen[$record->id] ??= Buchungen::entschluesseln($vault, $record);
                $buchung = $buchungen[$record->id];
                if ($bezahltAm === null || $buchung->bookingDate > $bezahltAm) {
                    $bezahltAm = $buchung->bookingDate;
                }
                $konto = $konten[$buchung->accountId] ?? null;
                if ($konto !== null && !in_array($konto->data->name, $namen, true)) {
                    $namen[] = $konto->data->name;
                }
                if ($konto?->kind !== BankAccountKind::Kasse) {
                    $nurKasse = false;
                }
                if ($buchung->purpose !== '' && !in_array($buchung->purpose, $referenzen, true)) {
                    $referenzen[] = $buchung->purpose;
                }
            }
            if ($bezahltAm !== null) {
                $angaben[$invoiceId] = new Zahlungsangaben($bezahltAm, $namen, $referenzen, $nurKasse);
            }
        }

        return $angaben;
    }

    /**
     * The booking direction a receipt is settled by: its own - a credit
     * note the opposite, money flows back.
     */
    private static function erwarteteRichtung(InvoiceRecord $beleg): string
    {
        $eigene = $beleg->direction->value;
        if ($beleg->docType !== InvoiceType::Gutschrift) {
            return $eigene;
        }

        return $eigene === 'ausgabe' ? 'einnahme' : 'ausgabe';
    }

    /**
     * @param list<AllocationRecord> $zuordnungen
     */
    private function summe(Vault $vault, array $zuordnungen): int
    {
        $summe = 0;
        foreach ($zuordnungen as $zuordnung) {
            $summe += $this->betrag($vault, $zuordnung);
        }

        return $summe;
    }

    private function betrag(Vault $vault, AllocationRecord $record): int
    {
        $klartext = FieldCipher::decrypt($vault->openDataKey($record->dekSealed), $record->amountEnc, new FieldContext(self::TABLE, $record->id, self::COLUMN));

        return (int) $klartext;
    }

    private function entschluesseln(Vault $vault, AllocationRecord $record): Allocation
    {
        return new Allocation(
            id: $record->id,
            invoiceId: $record->invoiceId,
            transactionId: $record->transactionId,
            amount: $this->betrag($vault, $record),
            method: $record->method,
            score: $record->score,
            ruleTrace: $record->ruleTrace,
            createdBy: $record->createdBy,
            createdAt: $record->createdAt,
        );
    }
}
