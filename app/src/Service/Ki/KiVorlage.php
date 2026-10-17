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
 * (M7-1b, through its OpenAI-compatible endpoint) next; the self-hosted
 * llama.cpp server (M7-1c) is the third case.
 *
 * The capabilities of a template are a starting point. "Verbindung testen"
 * (App\Service\Ki\Verbindungstest) finds out what the model really does
 * and writes `vision` and `json_schema` into the profile - Anthropic's
 * compatible endpoint, for one, ignores `response_format` without an error.
 *
 * The llama.cpp base URL is a placeholder: the association hosts that server
 * itself, and the application only talks https (KiAnbieterService::
 * normalisiereBaseUrl), so it is reached through a reverse proxy with TLS
 * and an API key (docs/llama-cpp-reverse-proxy.md).
 */
enum KiVorlage: string
{
    case OpenAi = 'openai';
    case Anthropic = 'anthropic';
    case LlamaCpp = 'llamacpp';

    public function label(): string
    {
        return match ($this) {
            self::OpenAi => 'OpenAI',
            self::Anthropic => 'Anthropic',
            self::LlamaCpp => 'llama.cpp-Server',
        };
    }

    public function baseUrl(): string
    {
        return match ($this) {
            self::OpenAi => 'https://api.openai.com/v1',
            self::Anthropic => 'https://api.anthropic.com/v1',
            self::LlamaCpp => 'https://llm.example.org/v1',
        };
    }

    public function modell(): string
    {
        return match ($this) {
            self::OpenAi => 'gpt-6-luna',
            self::Anthropic => 'claude-sonnet-5-5',
            // llama-server serves the one model it was started with and does
            // not check the name; a vision model needs its --mmproj file.
            self::LlamaCpp => 'default',
        };
    }

    public function faehigkeiten(): KiFaehigkeiten
    {
        return match ($this) {
            self::OpenAi => new KiFaehigkeiten(vision: true, jsonSchema: true, maxImages: 4, maxTokens: 4000),
            // No json_schema: the compatible endpoint ignores response_format,
            // so the schema goes into the prompt until the test says otherwise.
            self::Anthropic => new KiFaehigkeiten(vision: true, jsonSchema: false, maxImages: 4, maxTokens: 4000),
            // llama-server turns response_format into a grammar. Fewer pages
            // than the cloud profiles: a local vision model has a small context.
            self::LlamaCpp => new KiFaehigkeiten(vision: true, jsonSchema: true, maxImages: 2, maxTokens: 4000),
        };
    }

    public function timeoutS(): int
    {
        return match ($this) {
            self::OpenAi, self::Anthropic => 60,
            // A Raspberry Pi or a small VPS needs minutes for a page.
            self::LlamaCpp => 180,
        };
    }
}
