<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Repository\WorkerNonceRepository;
use App\Repository\WorkerRepository;
use App\Service\Worker\WorkerAuth;
use App\Service\Worker\WorkerFreigaben;

/**
 * Housekeeping for the optional worker (issue #51/M7b-1,
 * docs/spec/07-worker.md section 4 "Cron räumt auf"): nonces that are
 * older than any accepted timestamp could replay, and pairing codes that
 * expired a day ago. Since issue #52/M7b-2 also the deadline of worker
 * grants and contexts (past `expires_at`, or no worker job of the document
 * waits any more) and artifact uploads that were never committed. Nothing
 * here decrypts (CLAUDE.md section 4).
 */
final readonly class WorkerCleanupTask implements CronTask
{
    public function __construct(
        private WorkerNonceRepository $nonces,
        private WorkerRepository $workers,
        private WorkerFreigaben $freigaben,
    ) {
    }

    public function name(): string
    {
        return 'worker_aufraeumen';
    }

    public function run(\DateTimeImmutable $now): int
    {
        return $this->nonces->deleteOlderThan($now->modify(sprintf('-%d seconds', WorkerAuth::NONCE_AUFBEWAHRUNG_SEKUNDEN)))
            + $this->workers->codesAufraeumen($now->modify('-1 day'))
            + $this->freigaben->aufraeumen($now);
    }
}
