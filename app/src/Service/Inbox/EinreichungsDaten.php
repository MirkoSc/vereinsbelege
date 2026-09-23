<?php

declare(strict_types=1);

namespace App\Service\Inbox;

use App\Domain\Erstattungsart;

/**
 * The decrypted `submission.payload_enc` (docs/spec/02-datenmodell.md
 * "Fachdaten", written by App\Service\Submission\SubmissionService). Lives
 * only in the request that opened it.
 */
final readonly class EinreichungsDaten
{
    public function __construct(
        public string $name,
        public ?string $email,
        public ?Erstattungsart $erstattung,
        public ?string $iban,
        public ?string $kontoinhaber,
        public string $freitext,
        public ?int $kostenstelleHinweis,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        $erstattung = is_array($payload['erstattung'] ?? null) ? $payload['erstattung'] : [];

        return new self(
            name: is_string($payload['name'] ?? null) ? $payload['name'] : '',
            email: is_string($payload['email'] ?? null) ? $payload['email'] : null,
            erstattung: is_string($erstattung['art'] ?? null) ? Erstattungsart::tryFrom($erstattung['art']) : null,
            iban: is_string($erstattung['iban'] ?? null) ? $erstattung['iban'] : null,
            kontoinhaber: is_string($erstattung['kontoinhaber'] ?? null) ? $erstattung['kontoinhaber'] : null,
            freitext: is_string($payload['freitext'] ?? null) ? $payload['freitext'] : '',
            kostenstelleHinweis: is_int($payload['kostenstelle_hinweis'] ?? null) ? $payload['kostenstelle_hinweis'] : null,
        );
    }

    /**
     * Whether the search term occurs in name or description (case- and
     * accent-insensitive enough for a club's inbox: lower case only).
     */
    public function passtZu(string $suche): bool
    {
        $suche = mb_strtolower(trim($suche));

        return $suche === ''
            || str_contains(mb_strtolower($this->name), $suche)
            || str_contains(mb_strtolower($this->freitext), $suche);
    }
}
