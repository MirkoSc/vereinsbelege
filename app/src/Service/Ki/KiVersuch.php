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

    /**
     * The attempt as the job keeps it between two requests (issue #47/M7-5):
     * encrypted in a `document_artifact`, because $inhalt and $daten are
     * business data - `job.state` only ever holds counters.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'daten' => $this->daten,
            'fehler' => $this->fehler,
            'inhalt' => $this->inhalt,
            'reparatur' => $this->reparatur,
            'aufruf' => $this->aufruf->toArray(),
        ];
    }

    /**
     * @param array<mixed> $daten what toArray() stored
     *
     * @throws \UnexpectedValueException when $daten is not such an attempt
     */
    public static function fromArray(array $daten): self
    {
        $status = KiAufrufStatus::tryFrom(is_string($daten['status'] ?? null) ? $daten['status'] : '');
        $werte = $daten['daten'] ?? null;
        if (
            $status === null
            || !($werte === null || (is_array($werte) && !array_is_list($werte)))
            || !is_array($daten['fehler'] ?? null)
            || !is_string($daten['inhalt'] ?? null)
            || !is_bool($daten['reparatur'] ?? null)
            || !is_array($daten['aufruf'] ?? null)
        ) {
            throw new \UnexpectedValueException('Not a stored AI attempt.');
        }

        return new self(
            $status,
            $werte,
            array_values(array_filter($daten['fehler'], is_string(...))),
            $daten['inhalt'],
            $daten['reparatur'],
            KiAufruf::fromArray($daten['aufruf']),
        );
    }
}
