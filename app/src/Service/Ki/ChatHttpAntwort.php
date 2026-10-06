<?php

declare(strict_types=1);

namespace App\Service\Ki;

/**
 * An HTTP answer of a provider: status and body, nothing interpreted yet.
 */
final readonly class ChatHttpAntwort
{
    public function __construct(
        public int $status,
        public string $body,
    ) {
    }
}
