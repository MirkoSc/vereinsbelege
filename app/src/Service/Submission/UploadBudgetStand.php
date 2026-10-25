<?php

declare(strict_types=1);

namespace App\Service\Submission;

/**
 * Today's byte budget and free space of the public upload (issue #242) -
 * what the admin page and the system check show. Sizes and counts only,
 * nothing about who uploaded.
 */
final readonly class UploadBudgetStand
{
    /**
     * @param ?int $freiBytes null when the host does not tell
     *        (App\Service\Submission\FreierSpeicher)
     */
    public function __construct(
        public int $verbrauchtHeute,
        public int $budgetHeute,
        public int $groessteDatei,
        public ?int $freiBytes,
        public int $minFreiBytes,
    ) {
    }

    /**
     * Whether a page of the largest allowed size would be refused right
     * now - the point from which visitors start to see the refusal.
     */
    public function budgetErreicht(): bool
    {
        return $this->budgetHeute - $this->verbrauchtHeute < $this->groessteDatei;
    }

    public function speicherKnapp(): bool
    {
        return $this->freiBytes !== null && $this->freiBytes < $this->minFreiBytes;
    }
}
