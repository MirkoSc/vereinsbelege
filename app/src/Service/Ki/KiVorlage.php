<?php

declare(strict_types=1);

namespace App\Service\Ki;

/**
 * Shipped provider templates (docs/spec/03-erfassung-und-ki.md section 6,
 * E-08). A template only pre-fills the form of a new profile - the profile
 * itself is an ordinary row and stays editable.
 *
 * Order of E-08: OpenAI first (and the default after the installation,
 * migrations/024_ai_provider.sql seeds exactly this case); Anthropic
 * (M7-1b) and the llama.cpp server (M7-1c) follow as further cases.
 */
enum KiVorlage: string
{
    case OpenAi = 'openai';

    public function label(): string
    {
        return match ($this) {
            self::OpenAi => 'OpenAI',
        };
    }

    public function baseUrl(): string
    {
        return match ($this) {
            self::OpenAi => 'https://api.openai.com/v1',
        };
    }

    public function modell(): string
    {
        return match ($this) {
            self::OpenAi => 'gpt-6-luna',
        };
    }

    public function faehigkeiten(): KiFaehigkeiten
    {
        return match ($this) {
            self::OpenAi => new KiFaehigkeiten(vision: true, jsonSchema: true, maxImages: 4, maxTokens: 4000),
        };
    }

    public function timeoutS(): int
    {
        return match ($this) {
            self::OpenAi => 60,
        };
    }
}
