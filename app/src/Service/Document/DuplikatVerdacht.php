<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Domain\DuplikatGrund;
use App\Domain\DuplikatKandidat;

/**
 * The suspicion of one document as a viewer may see it (issue #40/M6-6): the
 * other documents inside the viewer's scope, with link and reference, and
 * only the number of those outside it - that a pair exists is no club data,
 * which document it is would be.
 */
final readonly class DuplikatVerdacht
{
    /**
     * @param list<DuplikatKandidat> $sichtbar
     */
    public function __construct(
        public array $sichtbar,
        public int $ausserhalb,
    ) {
    }

    public function besteht(): bool
    {
        return $this->sichtbar !== [] || $this->ausserhalb > 0;
    }

    /**
     * @return list<DuplikatGrund> every reason among the visible ones
     */
    public function gruende(): array
    {
        $gruende = [];
        foreach ($this->sichtbar as $kandidat) {
            foreach ($kandidat->gruende as $grund) {
                $gruende[$grund->value] = $grund;
            }
        }

        return array_values(array_filter(DuplikatGrund::cases(), static fn(DuplikatGrund $g): bool => isset($gruende[$g->value])));
    }
}
