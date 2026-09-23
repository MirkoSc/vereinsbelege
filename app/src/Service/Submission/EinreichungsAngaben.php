<?php

declare(strict_types=1);

namespace App\Service\Submission;

use App\Domain\Erstattungsart;

/**
 * A validated submission form (docs/spec/03-erfassung-und-ki.md section 1
 * "Angaben", issue #24/M4-2) - only ever built once every field has passed
 * SubmissionService's checks, so nothing downstream (the payload JSON, the
 * confirmation mail) has to validate again.
 *
 * The internal capture (issue #28/M4-6, App\Service\Submission\
 * InterneErfassung) builds the same object: there the reimbursement is
 * optional ($erstattung null) and the description may be empty.
 */
final readonly class EinreichungsAngaben
{
    public function __construct(
        public string $name,
        public ?string $email,
        public ?Erstattungsart $erstattung,
        public ?string $iban,
        public ?string $kontoinhaber,
        public string $freitext,
        public ?int $kostenstelleId,
    ) {
    }

    /**
     * What `submission.payload_enc` holds (docs/spec/02-datenmodell.md
     * "Fachdaten"); optional parts are left out rather than written as
     * null - App\Service\Inbox\EinreichungsDaten reads either.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = ['name' => $this->name];

        if ($this->erstattung !== null) {
            $erstattung = ['art' => $this->erstattung->value];
            if ($this->iban !== null) {
                $erstattung['iban'] = $this->iban;
            }
            if ($this->kontoinhaber !== null) {
                $erstattung['kontoinhaber'] = $this->kontoinhaber;
            }
            $payload['erstattung'] = $erstattung;
        }

        $payload['freitext'] = $this->freitext;
        if ($this->email !== null) {
            $payload['email'] = $this->email;
        }
        if ($this->kostenstelleId !== null) {
            $payload['kostenstelle_hinweis'] = $this->kostenstelleId;
        }

        return $payload;
    }
}
