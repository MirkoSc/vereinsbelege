<?php

declare(strict_types=1);

namespace App\Service\Job;

use App\Domain\JobStatus;

/**
 * What one call to a JobHandler::schritt() produced (docs/spec/06-betrieb.md
 * section 4): the runner (M4-7's `POST /api/jobs/step`) writes this straight
 * into the `job` row via App\Repository\JobRepository.
 *
 * `state` carries ids and counters only, never business data - the same rule
 * the `job` table itself follows.
 */
final readonly class JobSchrittErgebnis
{
    /**
     * @param array<string, mixed> $state
     */
    private function __construct(
        public JobStatus $status,
        public string $step,
        public array $state,
    ) {
    }

    /**
     * @param array<string, mixed> $state
     */
    public static function weiter(string $naechsterSchritt, array $state): self
    {
        return new self(JobStatus::Offen, $naechsterSchritt, $state);
    }

    public static function fertig(): self
    {
        return new self(JobStatus::Fertig, '', []);
    }

    /**
     * The job's work does not apply here (docs/spec/03-erfassung-und-ki.md
     * section 3: a mixed or multi-PDF submission is not something
     * PdfErzeugung produces a PDF for) - not an error, nothing to retry.
     */
    public static function uebersprungen(): self
    {
        return new self(JobStatus::Uebersprungen, '', []);
    }
}
