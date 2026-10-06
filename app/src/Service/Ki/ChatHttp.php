<?php

declare(strict_types=1);

namespace App\Service\Ki;

/**
 * The wire under every AI call: one JSON POST to an OpenAI-compatible
 * endpoint (docs/spec/03-erfassung-und-ki.md section 6). Production uses
 * CurlChatHttp; tests replace it with recorded answers, so no test ever
 * talks to a real provider (CLAUDE.md section 6).
 *
 * Deliberately below the chat level: M7-2's LlmClient builds prompts,
 * schema handling and the repair call on top of this, the connection test
 * (Verbindungstest) uses it directly.
 */
interface ChatHttp
{
    /**
     * @param string $apiKey sent as Bearer token; '' sends no
     *        Authorization header at all
     *
     * @throws KiVerbindungsfehler when no HTTP answer arrived (network,
     *         TLS, timeout) - an HTTP error status is an answer, not an
     *         exception
     */
    public function post(string $url, #[\SensitiveParameter] string $apiKey, string $jsonBody, int $timeoutS): ChatHttpAntwort;
}
