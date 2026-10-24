<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One `allocation` row as stored (M10-1, issue #65): plaintext structure plus
 * the sealed data key and the ciphertext of the amount. What
 * App\Repository\AllocationRepository returns; App\Service\Bank\Zuordnung
 * opens the amount once a vault is at hand.
 */
final readonly class AllocationRecord
{
    /**
     * @param array<string, mixed>|null $ruleTrace the signals that fired (M10-2), keys and points only
     */
    public function __construct(
        public int $id,
        public int $invoiceId,
        public int $transactionId,
        public string $dekSealed,
        public string $amountEnc,
        public ZuordnungMethode $method,
        public ?int $score,
        public ?array $ruleTrace,
        public ?int $createdBy,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $trace = $row['rule_trace'] === null ? null : json_decode((string) $row['rule_trace'], true, flags: JSON_THROW_ON_ERROR);

        return new self(
            id: (int) $row['id'],
            invoiceId: (int) $row['invoice_id'],
            transactionId: (int) $row['transaction_id'],
            dekSealed: (string) $row['dek_sealed'],
            amountEnc: (string) $row['amount_enc'],
            method: ZuordnungMethode::from((string) $row['method']),
            score: $row['score'] === null ? null : (int) $row['score'],
            ruleTrace: is_array($trace) ? $trace : null,
            createdBy: $row['created_by'] === null ? null : (int) $row['created_by'],
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
        );
    }
}
