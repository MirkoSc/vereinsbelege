<?php

declare(strict_types=1);

namespace App\Service\Ki;

/**
 * What a provider says, made fit for a page: the HTTP status as a German
 * hint, and the provider's own text shortened and stripped of the API key.
 * Shared by the connection test (M7-1) and the client (M7-2), so both name
 * an error the same way.
 */
final class KiAnbieterText
{
    private const int TEXT_MAX = 200;

    private function __construct()
    {
    }

    public static function httpMeldung(int $status): string
    {
        return match (true) {
            $status === 401, $status === 403 => sprintf('Zugriff verweigert (HTTP %d) – API-Key prüfen.', $status),
            $status === 404 => 'Adresse oder Modell nicht gefunden (HTTP 404) – Basis-URL und Modell prüfen.',
            $status === 429 => 'Anfragelimit oder Guthaben erschöpft (HTTP 429).',
            $status === 400 => 'Anfrage abgelehnt (HTTP 400) – Modell und Fähigkeiten (Bilder, JSON-Schema) prüfen.',
            $status >= 300 && $status < 400 => sprintf('Weiterleitung (HTTP %d) – bitte die Basis-URL direkt angeben.', $status),
            $status === 0 => 'Der Anbieter hat keinen HTTP-Status geliefert.',
            default => sprintf('Der Anbieter meldet HTTP %d.', $status),
        };
    }

    /**
     * The provider's own error text: `error.message` of the OpenAI error
     * shape, a bare `error` string, or - for an HTML page of a proxy in
     * between - the text of the body.
     */
    public static function fehlertext(string $body, #[\SensitiveParameter] string $apiKey): string
    {
        $json = json_decode($body, true);
        $text = match (true) {
            is_array($json) && is_string($json['error']['message'] ?? null) => $json['error']['message'],
            is_array($json) && is_string($json['error'] ?? null) => $json['error'],
            is_array($json) && is_string($json['message'] ?? null) => $json['message'],
            is_array($json) => '',
            // A space per tag, or "<h1>502</h1><hr>nginx" becomes "502nginx".
            default => strip_tags(str_replace('<', ' <', $body)),
        };

        return self::bereinigt($text, $apiKey);
    }

    /**
     * Provider text made safe to show: one line, short, and without the
     * API key - the full key and anything shaped like an OpenAI-style key
     * (`sk-…`, also the partially masked echo of a 401) become `***`.
     */
    public static function bereinigt(string $text, #[\SensitiveParameter] string $apiKey): string
    {
        if ($apiKey !== '') {
            $text = str_replace($apiKey, '***', $text);
        }
        $text = (string) preg_replace('/\bsk-[A-Za-z0-9_*\-.]{4,}/u', '***', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) > self::TEXT_MAX) {
            $text = rtrim(mb_substr($text, 0, self::TEXT_MAX)) . '…';
        }

        return $text;
    }
}
