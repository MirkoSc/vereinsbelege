<?php

declare(strict_types=1);

namespace App\Service\Upload;

/**
 * What is on disk for one upload. `fehlend` is what makes a retry cheap: the
 * browser resends those chunks instead of the whole file.
 */
final readonly class UploadStatus
{
    /**
     * @param list<int> $fehlend chunk indexes that are missing or of the
     *                           wrong length - both mean "send this one again"
     */
    public function __construct(
        public int $chunks,
        public array $fehlend,
        public int $groesse,
        public int $empfangen,
    ) {
    }

    /**
     * Every chunk is there with exactly the length it must have, so the
     * pieces add up to the announced size - no separate byte count needed.
     */
    public function vollstaendig(): bool
    {
        return $this->fehlend === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'chunks' => $this->chunks,
            'fehlend' => $this->fehlend,
            'empfangen' => $this->empfangen,
            'vollstaendig' => $this->vollstaendig(),
        ];
    }
}
