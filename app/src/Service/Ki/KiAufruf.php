<?php

declare(strict_types=1);

namespace App\Service\Ki;

/**
 * One line of the call log (docs/spec/03-erfassung-und-ki.md section 6:
 * "Anbieter, Modell, Prompt-Version, Tokens (falls geliefert), Dauer,
 * Ergebnisstatus; Rohantwort verschlüsselt"). Only handed back here - the
 * job (M7-5) stores the raw answer encrypted with the receipt, the log page
 * (M7-8) the rest. $rohantwort is business data: never log it.
 *
 * $anbieterId/$anbieterName/$modell name the profile that was actually
 * asked; $fallback says that it was the fallback profile because the
 * default one did not answer (docs/spec/03-erfassung-und-ki.md section 6).
 */
final readonly class KiAufruf
{
    public function __construct(
        public int $anbieterId,
        public string $anbieterName,
        public string $modell,
        public string $promptVersion,
        public bool $reparatur,
        public KiAufrufStatus $status,
        public ?int $promptTokens,
        public ?int $antwortTokens,
        public ?int $tokensGesamt,
        public ?int $dauerMs,
        public ?int $httpStatus,
        public ?string $rohantwort,
        public bool $fallback = false,
    ) {
    }

    /**
     * For the encrypted store at the receipt (issue #47/M7-5: the job keeps
     * every call, raw answer included, in a `document_artifact` - never in
     * plaintext).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'anbieter_id' => $this->anbieterId,
            'anbieter_name' => $this->anbieterName,
            'modell' => $this->modell,
            'prompt_version' => $this->promptVersion,
            'reparatur' => $this->reparatur,
            'status' => $this->status->value,
            'prompt_tokens' => $this->promptTokens,
            'antwort_tokens' => $this->antwortTokens,
            'tokens_gesamt' => $this->tokensGesamt,
            'dauer_ms' => $this->dauerMs,
            'http_status' => $this->httpStatus,
            'rohantwort' => $this->rohantwort,
            'fallback' => $this->fallback,
        ];
    }

    /**
     * @param array<mixed> $daten what toArray() stored
     *
     * @throws \UnexpectedValueException when $daten is not such an entry
     */
    public static function fromArray(array $daten): self
    {
        $status = KiAufrufStatus::tryFrom(is_string($daten['status'] ?? null) ? $daten['status'] : '');
        if (
            !is_int($daten['anbieter_id'] ?? null)
            || !is_string($daten['anbieter_name'] ?? null)
            || !is_string($daten['modell'] ?? null)
            || !is_string($daten['prompt_version'] ?? null)
            || !is_bool($daten['reparatur'] ?? null)
            || $status === null
            || !is_bool($daten['fallback'] ?? null)
        ) {
            throw new \UnexpectedValueException('Not a stored AI call.');
        }
        $zahl = static fn(string $schluessel): ?int => is_int($daten[$schluessel] ?? null) ? $daten[$schluessel] : null;

        return new self(
            $daten['anbieter_id'],
            $daten['anbieter_name'],
            $daten['modell'],
            $daten['prompt_version'],
            $daten['reparatur'],
            $status,
            $zahl('prompt_tokens'),
            $zahl('antwort_tokens'),
            $zahl('tokens_gesamt'),
            $zahl('dauer_ms'),
            $zahl('http_status'),
            is_string($daten['rohantwort'] ?? null) ? $daten['rohantwort'] : null,
            $daten['fallback'],
        );
    }
}
