<?php

declare(strict_types=1);

namespace App\Service\Ki;

/**
 * The outcome of one call in KiAbfrage: valid data, or why not.
 *
 * $inhalt is the model's text as it came - business data, kept only so the
 * repair call can send it back (and the job can store it encrypted between
 * two requests). $fehler lists the schema errors (places and rules, no
 * values, see KiSchema).
 */
final readonly class KiVersuch
{
    /**
     * @param ?array<string, mixed> $daten the answer as an associative array, only when valid
     * @param list<string> $fehler
     */
    public function __construct(
        public KiAufrufStatus $status,
        public ?array $daten,
        public array $fehler,
        public string $inhalt,
        public bool $reparatur,
        public KiAufruf $aufruf,
    ) {
    }

    public function gueltig(): bool
    {
        return $this->status === KiAufrufStatus::Gueltig;
    }

    /**
     * Exactly one repair call, and only for an answer that is there but
     * does not fit (CLAUDE.md section 6).
     */
    public function reparaturMoeglich(): bool
    {
        return !$this->reparatur && $this->status->reparierbar();
    }

    /**
     * The one attempt with the fallback profile: only after the first call
     * of a chain (no repair, not itself a fallback) that found the provider
     * out of reach. A repair call that fails stays "KI fehlgeschlagen": the
     * first answer came from this provider, a fresh start elsewhere would
     * only hide that.
     */
    public function fallbackMoeglich(): bool
    {
        return !$this->reparatur && !$this->aufruf->fallback && $this->status->ausfall($this->aufruf->httpStatus);
    }
}
