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
        public int $wartenSekunden = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $state
     */
    public static function weiter(string $naechsterSchritt, array $state): self
    {
        return new self(JobStatus::Offen, $naechsterSchritt, $state);
    }

    /**
     * Not yet: the job waits for other work on the same document (issue
     * #47/M7-5: `ai_extract` waits for the text layer and the page images)
     * and is not claimed again for $sekunden - App\Repository\
     * JobRepository::schrittErledigt() keeps it in `locked_until`, which
     * claim() already skips. Unlike weiter(), the next call does not come
     * right away, so a job that waits does not keep the browser busy.
     *
     * @param array<string, mixed> $state
     */
    public static function warten(string $schritt, array $state, int $sekunden): self
    {
        if ($sekunden < 1) {
            throw new \InvalidArgumentException('A job waits at least one second.');
        }

        return new self(JobStatus::Offen, $schritt, $state, $sekunden);
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
