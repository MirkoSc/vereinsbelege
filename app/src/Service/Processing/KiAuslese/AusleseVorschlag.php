<?php

declare(strict_types=1);

namespace App\Service\Processing\KiAuslese;

/**
 * A valid AI answer after the server's own checks (Auslesung::auswerten(),
 * issue #47/M7-5): $extraktion is still `extract-v1`, with whatever did not
 * hold up set to null; $hinweise are the server's findings, apart from the
 * model's own `warnings`. A suggestion only - a person confirms it in the
 * review page (CLAUDE.md section 6).
 */
final readonly class AusleseVorschlag
{
    /**
     * Below this confidence the review page marks a field yellow
     * (docs/spec/03-erfassung-und-ki.md section 6 "Prüfansicht").
     */
    public const float KONFIDENZ_GRENZE = 0.7;

    /**
     * @param array<string, mixed> $extraktion
     * @param list<string> $hinweise German, without values of the receipt
     */
    public function __construct(
        public array $extraktion,
        public array $hinweise,
    ) {
    }

    /**
     * The `confidence` keys (`supplier`, `total_gross`, `invoice_date`,
     * `category`) the model is unsure about. A key without a number counts
     * as unsure - a missing confidence is no confidence.
     *
     * @param array<string, mixed> $extraktion
     *
     * @return list<string>
     */
    public static function unsicher(array $extraktion): array
    {
        $konfidenz = is_array($extraktion['confidence'] ?? null) ? $extraktion['confidence'] : [];
        $unsicher = [];
        foreach (['supplier', 'total_gross', 'invoice_date', 'category'] as $schluessel) {
            $wert = $konfidenz[$schluessel] ?? null;
            if ((!is_int($wert) && !is_float($wert)) || $wert < self::KONFIDENZ_GRENZE) {
                $unsicher[] = $schluessel;
            }
        }

        return $unsicher;
    }
}
