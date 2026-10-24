<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One allocation, decrypted (M10-1, issue #65): the structure of the
 * `allocation` row plus the amount in cents it settles of the receipt.
 * Built by App\Service\Bank\Zuordnung in a session with an unlocked vault -
 * never logged, never put into a URL.
 */
final readonly class Allocation
{
    /**
     * @param int $amount integer cents, always positive: the part of the receipt's gross this booking settles
     * @param array<string, mixed>|null $ruleTrace
     */
    public function __construct(
        public int $id,
        public int $invoiceId,
        public int $transactionId,
        public int $amount,
        public ZuordnungMethode $method,
        public ?int $score,
        public ?array $ruleTrace,
        public ?int $createdBy,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
