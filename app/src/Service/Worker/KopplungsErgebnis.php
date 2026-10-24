<?php

declare(strict_types=1);

namespace App\Service\Worker;

/**
 * Outcome of App\Service\Worker\WorkerKopplung::koppeln(): either the new
 * worker with its access key (handed out exactly once) or a refusal.
 */
final readonly class KopplungsErgebnis
{
    private function __construct(
        public ?int $workerId,
        public ?string $schluessel,
        public ?string $fingerabdruck,
        public ?string $fehler,
        public int $status,
    ) {
    }

    public static function gekoppelt(int $workerId, string $schluessel, string $fingerabdruck): self
    {
        return new self($workerId, $schluessel, $fingerabdruck, null, 200);
    }

    public static function abgelehnt(string $fehler, int $status): self
    {
        return new self(null, null, null, $fehler, $status);
    }

    public function istGekoppelt(): bool
    {
        return $this->workerId !== null;
    }
}
