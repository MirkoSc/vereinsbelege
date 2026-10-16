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
}
