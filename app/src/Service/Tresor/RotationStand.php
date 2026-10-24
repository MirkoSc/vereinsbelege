<?php

declare(strict_types=1);

namespace App\Service\Tresor;

/**
 * Where a vault key rotation stands (issue #90) - derived from the database
 * on every call, never stored: the generations in `vault` and how many rows
 * still carry a data key of the old one.
 */
final readonly class RotationStand
{
    /**
     * @param array<string, int> $offenJeTabelle rows still sealed to the old generation
     */
    public function __construct(
        public bool $laeuft,
        public ?int $alteVersion,
        public int $neueVersion,
        public bool $schluesselBestaetigt,
        public array $offenJeTabelle = [],
        public int $defekt = 0,
        public bool $durchlaufen = false,
    ) {
    }

    public function offen(): int
    {
        return array_sum($this->offenJeTabelle);
    }

    /**
     * Every row sealed to the new generation, or the rest was tried and does
     * not open - those were unreadable before the rotation already.
     */
    public function umgeschluesselt(): bool
    {
        return $this->laeuft && ($this->offen() === 0 || ($this->durchlaufen && $this->offen() <= $this->defekt));
    }

    public function kannAbschliessen(): bool
    {
        return $this->umgeschluesselt() && $this->schluesselBestaetigt;
    }

    /**
     * @return array<string, mixed> the JSON the step chain's browser side reads
     */
    public function toArray(): array
    {
        return [
            'laeuft' => $this->laeuft,
            'alteVersion' => $this->alteVersion,
            'neueVersion' => $this->neueVersion,
            'schluesselBestaetigt' => $this->schluesselBestaetigt,
            'offen' => $this->offen(),
            'defekt' => $this->defekt,
            'umgeschluesselt' => $this->umgeschluesselt(),
            'kannAbschliessen' => $this->kannAbschliessen(),
        ];
    }
}
