<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\BankTransactionDirection;
use App\Domain\BankTransactionDocStatus;
use App\Domain\DocumentStatus;
use App\Domain\InvoiceRecord;
use App\Domain\PaymentStatus;
use App\Domain\Zahlungsweg;
use App\Domain\Zugriffsbereich;

/**
 * The reads of the reimbursement matching (M10-3, issue #67, docs/spec/
 * 04-bank-und-abgleich.md section 5, E-36). Plaintext structure only: the
 * submitter's IBAN lives in `submission.payload_enc`, so which receipt
 * belongs to which IBAN is decided by App\Service\Bank\Erstattungen after
 * decrypting, in the session. Nothing here writes - allocations go through
 * App\Service\Bank\Zuordnung.
 */
final readonly class ErstattungRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Open reimbursements (`Zahlungsweg::Erstattung`, nothing or part
     * allocated) of a submission, not rejected, within $bereich - oldest
     * receipt first. With the sealed submission each, whose IBAN decides.
     *
     * @return list<array{beleg: InvoiceRecord, submissionId: int, dekSealed: string, payloadEnc: string}>
     */
    public function offene(Zugriffsbereich $bereich, int $limit): array
    {
        [$scope, $parameter] = $bereich->sqlBedingung('i.invoice_date', 'i.cost_center_id');
        $stmt = $this->pdo->prepare(
            'SELECT i.*, d.submission_id AS erstattung_submission_id, s.dek_sealed AS erstattung_dek_sealed,
                    s.payload_enc AS erstattung_payload_enc
             FROM invoice i
             JOIN document d ON d.id = i.document_id
             JOIN submission s ON s.id = d.submission_id
             WHERE i.payment_kind = ? AND i.payment_status IN (?, ?) AND d.status <> ?
               AND s.dek_sealed IS NOT NULL AND s.payload_enc IS NOT NULL AND ' . $scope . '
             ORDER BY i.invoice_date, i.id
             LIMIT ' . max(1, $limit),
        );
        $stmt->execute([
            Zahlungsweg::Erstattung->value,
            PaymentStatus::ErstattungOffen->value,
            PaymentStatus::Teilbezahlt->value,
            DocumentStatus::Abgelehnt->value,
            ...$parameter,
        ]);

        return array_map(self::zeile(...), $stmt->fetchAll());
    }

    /**
     * The sealed submission behind one receipt, or null when it has none.
     *
     * @return array{submissionId: int, dekSealed: string, payloadEnc: string}|null
     */
    public function einreichung(int $invoiceId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.id, s.dek_sealed, s.payload_enc
             FROM invoice i JOIN document d ON d.id = i.document_id JOIN submission s ON s.id = d.submission_id
             WHERE i.id = ? AND s.dek_sealed IS NOT NULL AND s.payload_enc IS NOT NULL',
        );
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return ['submissionId' => (int) $row['id'], 'dekSealed' => (string) $row['dek_sealed'], 'payloadEnc' => (string) $row['payload_enc']];
    }

    /**
     * Outgoing bookings to one counterparty IBAN (blind index
     * `bank_transaction.counterparty_bi`) that still miss a receipt -
     * oldest first, within $bereich (no cost center: a cost-center scope
     * sees none, 01 §4).
     *
     * @return list<int> booking ids
     */
    public function buchungenAnGegenseite(string $counterpartyBi, Zugriffsbereich $bereich, int $limit): array
    {
        [$scope, $parameter] = $bereich->sqlBedingung('booking_date', null);
        $stmt = $this->pdo->prepare(
            'SELECT id FROM bank_transaction
             WHERE counterparty_bi = ? AND direction = ? AND doc_status IN (?, ?) AND ' . $scope . '
             ORDER BY booking_date, id
             LIMIT ' . max(1, $limit),
        );
        $stmt->bindValue(1, $counterpartyBi, \PDO::PARAM_LOB);
        $stmt->bindValue(2, BankTransactionDirection::Ausgabe->value);
        $stmt->bindValue(3, BankTransactionDocStatus::Fehlt->value);
        $stmt->bindValue(4, BankTransactionDocStatus::Teilweise->value);
        foreach ($parameter as $i => $wert) {
            $stmt->bindValue(5 + $i, $wert);
        }
        $stmt->execute();

        return array_map(static fn(mixed $id): int => (int) $id, $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{beleg: InvoiceRecord, submissionId: int, dekSealed: string, payloadEnc: string}
     */
    private static function zeile(array $row): array
    {
        return [
            'beleg' => InvoiceRecord::fromRow($row),
            'submissionId' => (int) $row['erstattung_submission_id'],
            'dekSealed' => (string) $row['erstattung_dek_sealed'],
            'payloadEnc' => (string) $row['erstattung_payload_enc'],
        ];
    }
}
