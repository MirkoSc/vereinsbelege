<?php

declare(strict_types=1);

namespace App\Service\Ki;

/**
 * One row of `ai_provider` (docs/spec/02-datenmodell.md) - without its API
 * key. Whether a key is stored is all a page or a list needs; the key
 * itself is read separately and only where a call is made
 * (App\Repository\AiProviderRepository::apiKey()), so it does not travel
 * through views, audit details or debug output by accident.
 */
final readonly class KiAnbieter
{
    public function __construct(
        public int $id,
        public string $name,
        public string $baseUrl,
        public string $model,
        public KiFaehigkeiten $faehigkeiten,
        public int $timeoutS,
        public bool $active,
        public bool $isDefault,
        public bool $keyGesetzt,
    ) {
    }

    /**
     * Whether calls can go to this profile at all: active and with a key.
     * The default profile without a key means "AI switched off" (03
     * section 6) - receipts are then captured by hand only.
     */
    public function nutzbar(): bool
    {
        return $this->active && $this->keyGesetzt;
    }

    public function endpunkt(): string
    {
        return rtrim($this->baseUrl, '/') . '/chat/completions';
    }
}
