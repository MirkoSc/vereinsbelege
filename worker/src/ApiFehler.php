<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * The web host answered with an error: HTTP status plus its `fehler` code
 * (App\Api\WorkerApiController: `{fehler, meldung}`). The `meldung` is the
 * web host's own German text, without receipt content.
 */
final class ApiFehler extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $fehler,
        string $meldung = '',
    ) {
        parent::__construct($meldung !== '' ? $meldung : sprintf('Webhoster antwortet %d (%s).', $status, $fehler));
    }

    /** The job is no longer this worker's (lock expired, job went back). */
    public function jobVerloren(): bool
    {
        return in_array($this->fehler, ['sperre_verloren', 'job_unbekannt'], true);
    }
}
