<?php

declare(strict_types=1);

namespace App\Service\MasterData;

use App\Domain\SupplierKeyKind;

/**
 * The result of resolving one receipt's supplier (issue #48/M7-6,
 * docs/spec/03-erfassung-und-ki.md section 7) - which step decided, by
 * which value, for which supplier. Stored encrypted as the document's
 * `lieferant` artifact (App\Service\Document\LieferantenAufloesung), so the
 * review page can say how the supplier was found ("nachvollziehbar").
 *
 * Only ids, the step and the similarity - no name, no IBAN: the names live
 * with the suppliers and are read from there when the page shows them.
 */
final readonly class Aufloesung
{
    public const string SCHEMA = 'lieferant-v1';

    /**
     * @param int $stufe the step that decided, 1-6 (section 7); 0 for Ohne
     *        without anything to look up
     * @param ?SupplierKeyKind $merkmal the kind of value that matched (steps 1-4)
     * @param ?int $supplierId the supplier found, suggested first, or created
     * @param list<array{id: int, aehnlichkeit: float}> $kandidaten step 5's
     *        suggestions, best first, and step 4's suppliers of the same name
     * @param list<string> $felder the fields a created supplier got (step 6)
     */
    public function __construct(
        public AufloesungsArt $art,
        public int $stufe,
        public ?SupplierKeyKind $merkmal = null,
        public ?int $supplierId = null,
        public array $kandidaten = [],
        public array $felder = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'art' => $this->art->value,
            'stufe' => $this->stufe,
            'merkmal' => $this->merkmal?->value,
            'supplier_id' => $this->supplierId,
            'kandidaten' => $this->kandidaten,
            'felder' => $this->felder,
        ];
    }

    /**
     * @param array<mixed> $daten
     */
    public static function fromArray(array $daten): ?self
    {
        $art = AufloesungsArt::tryFrom(is_string($daten['art'] ?? null) ? $daten['art'] : '');
        $stufe = $daten['stufe'] ?? null;
        if ($art === null || !is_int($stufe) || $stufe < 0 || $stufe > 6) {
            return null;
        }
        $kandidaten = [];
        foreach (is_array($daten['kandidaten'] ?? null) ? $daten['kandidaten'] : [] as $kandidat) {
            $wert = is_array($kandidat) ? ($kandidat['aehnlichkeit'] ?? null) : null;
            if (is_array($kandidat) && is_int($kandidat['id'] ?? null) && (is_float($wert) || is_int($wert))) {
                $kandidaten[] = ['id' => $kandidat['id'], 'aehnlichkeit' => (float) $wert];
            }
        }

        return new self(
            $art,
            $stufe,
            SupplierKeyKind::tryFrom(is_string($daten['merkmal'] ?? null) ? $daten['merkmal'] : ''),
            is_int($daten['supplier_id'] ?? null) ? $daten['supplier_id'] : null,
            $kandidaten,
            array_values(array_filter(is_array($daten['felder'] ?? null) ? $daten['felder'] : [], is_string(...))),
        );
    }

    /**
     * The step in words, for the review page: "Stufe 1 – USt-ID".
     */
    public function stufeText(): string
    {
        return match ($this->stufe) {
            1, 2, 3 => sprintf('Stufe %d – %s', $this->stufe, $this->merkmal?->label() ?? 'Kennung'),
            4 => 'Stufe 4 – Name',
            5 => 'Stufe 5 – ähnlicher Name',
            6 => 'Stufe 6 – neu angelegt',
            default => 'keine Angaben zum Lieferanten',
        };
    }
}
