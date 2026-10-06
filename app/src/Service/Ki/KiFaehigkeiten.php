<?php

declare(strict_types=1);

namespace App\Service\Ki;

/**
 * What the model of a provider profile can do (`ai_provider.caps`,
 * docs/spec/03-erfassung-und-ki.md section 6 "Client"):
 *
 * - `vision`: takes images as `image_url` with a data URL;
 * - `jsonSchema`: understands `response_format` with a JSON schema -
 *   without it the schema goes into the prompt (M7-2);
 * - `maxImages`: pages sent as images per call, the rest only as text;
 * - `maxTokens`: upper bound for the answer.
 */
final readonly class KiFaehigkeiten
{
    public const int MAX_IMAGES_GRENZE = 20;

    public const int MAX_TOKENS_GRENZE = 100000;

    public function __construct(
        public bool $vision,
        public bool $jsonSchema,
        public int $maxImages,
        public int $maxTokens,
    ) {
    }

    public function toJson(): string
    {
        return json_encode([
            'vision' => $this->vision,
            'json_schema' => $this->jsonSchema,
            'max_images' => $this->maxImages,
            'max_tokens' => $this->maxTokens,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Lenient on purpose: a missing or broken key falls back to "cannot",
     * so a damaged row never claims a capability it was not given.
     */
    public static function fromJson(string $json): self
    {
        $werte = json_decode($json, true);
        if (!is_array($werte)) {
            $werte = [];
        }

        return new self(
            vision: ($werte['vision'] ?? false) === true,
            jsonSchema: ($werte['json_schema'] ?? false) === true,
            maxImages: is_int($werte['max_images'] ?? null) ? $werte['max_images'] : 0,
            maxTokens: is_int($werte['max_tokens'] ?? null) ? $werte['max_tokens'] : 0,
        );
    }
}
