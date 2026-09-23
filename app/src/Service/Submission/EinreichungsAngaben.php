<?php

declare(strict_types=1);

namespace App\Service\Submission;

use App\Domain\Erstattungsart;

/**
 * A validated submission form (docs/spec/03-erfassung-und-ki.md section 1
 * "Angaben", issue #24/M4-2) - only ever built once every field has passed
 * SubmissionService's checks, so nothing downstream (the payload JSON, the
 * confirmation mail) has to validate again.
 */
final readonly class EinreichungsAngaben
{
    public function __construct(
        public string $name,
        public ?string $email,
        public Erstattungsart $erstattung,
        public ?string $iban,
        public ?string $kontoinhaber,
        public string $freitext,
        public ?int $kostenstelleId,
    ) {
    }
}
