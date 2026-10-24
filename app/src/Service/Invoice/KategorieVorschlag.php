<?php

declare(strict_types=1);

namespace App\Service\Invoice;

use App\Domain\InvoiceDirection;
use App\Domain\KategorieHerkunft;

/**
 * The category suggested for a receipt and where it comes from (M7-7,
 * issue #49, docs/spec/03-erfassung-und-ki.md section 8). Lives only in
 * the request of the review page: the rule's label is vault data.
 */
final readonly class KategorieVorschlag
{
    /**
     * @param ?string $regel the rule's label, for KategorieHerkunft::Regel
     * @param ?float $konfidenz the AI's `confidence.category`, for KategorieHerkunft::Ki
     */
    public function __construct(
        public int $kategorieId,
        public KategorieHerkunft $herkunft,
        public InvoiceDirection $richtung,
        public ?string $regel = null,
        public ?float $konfidenz = null,
    ) {
    }

    /** What the review page says next to the category, e.g. "Regel „Telekom“" or "KI 0,82". */
    public function beschreibung(): string
    {
        $partner = $this->richtung === InvoiceDirection::Ausgabe ? 'des Lieferanten' : 'des Zahlers';

        return match ($this->herkunft) {
            KategorieHerkunft::Regel => 'Regel „' . ($this->regel ?? '') . '“',
            KategorieHerkunft::Lieferant => 'Standard-Kategorie ' . $partner,
            KategorieHerkunft::Gelernt => 'wie die letzten ' . Kategorisierung::GELERNT_AB . ' geprüften Belege ' . $partner,
            KategorieHerkunft::Ki => 'KI' . ($this->konfidenz === null ? '' : ' ' . number_format($this->konfidenz, 2, ',', '')),
        };
    }
}
