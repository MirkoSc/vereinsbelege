<?php

declare(strict_types=1);

namespace App\Service\Ki;

/**
 * The one way to an AI model (CLAUDE.md section 6,
 * docs/spec/03-erfassung-und-ki.md section 6 "Client"): an OpenAI-compatible
 * `POST {base_url}/chat/completions` for every provider. Production uses
 * OpenAiCompatibleClient; tests use tests/Support/FakeLlmClient.php with
 * recorded answers - no test talks to a real provider.
 *
 * One call, no interpretation of the text: extracting and validating the
 * JSON and the repair call are KiAbfrage's business. Framework-free (no
 * Http, Session or Repository), so the web app and the worker (M7b) run
 * the same code.
 */
interface LlmClient
{
    /**
     * @throws LlmFehler when no usable answer came back (connection,
     *         timeout, HTTP error, no chat completion with text)
     */
    public function chat(KiAnbieter $profil, #[\SensitiveParameter] string $apiKey, LlmAnfrage $anfrage): LlmAntwort;
}
