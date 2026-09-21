<?php

declare(strict_types=1);

namespace App\Service\SystemCheck;

/**
 * One line of the system check: what was probed, what came back, and whether
 * that is good enough. Never carries business data - the values are PHP ini
 * settings and server variables.
 */
final readonly class CheckResult
{
    public function __construct(
        public string $key,
        public string $label,
        public string $expected,
        public string $actual,
        public CheckStatus $status,
        public string $detail = '',
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'expected' => $this->expected,
            'actual' => $this->actual,
            'status' => $this->status->value,
            'detail' => $this->detail,
        ];
    }
}
