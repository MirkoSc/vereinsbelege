<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * What a program run left: exit code and output. The output can hold text
 * of a receipt - it is read, never logged.
 */
final readonly class ProzessErgebnis
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {
    }

    public function ok(): bool
    {
        return $this->exitCode === 0;
    }
}
