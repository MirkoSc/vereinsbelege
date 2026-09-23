<?php

declare(strict_types=1);

namespace App\Service\Audit;

/**
 * Result of checking (a stretch of) the audit chain: how many rows held,
 * where the check stands, and the first break if there is one.
 */
final readonly class AuditChainResult
{
    public function __construct(
        public int $geprueft,
        /** id of the last row that held (0 if none). */
        public int $letzteId,
        /** raw hash of that row (GENESIS if none). */
        public string $letzterHash,
        public ?int $bruchId = null,
        public ?AuditChainBreak $bruch = null,
    ) {
    }

    public function intakt(): bool
    {
        return $this->bruch === null;
    }
}
