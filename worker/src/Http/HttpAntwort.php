<?php

declare(strict_types=1);

namespace App\Worker\Http;

final readonly class HttpAntwort
{
    /**
     * @param array<string, string> $header lowercase names
     */
    public function __construct(
        public int $status,
        public array $header,
        public string $body,
    ) {
    }

    public function kopf(string $name): ?string
    {
        return $this->header[strtolower($name)] ?? null;
    }
}
