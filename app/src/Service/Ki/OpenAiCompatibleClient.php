<?php

declare(strict_types=1);

namespace App\Service\Ki;

/**
 * LlmClient for every provider (docs/spec/03-erfassung-und-ki.md section 6
 * "Client", E-08): OpenAI chat completions over ChatHttp - CurlChatHttp in
 * production (HTTPS only, no redirects), recorded answers in tests.
 *
 * The request follows the profile's capabilities:
 * - images only with `vision`, as `image_url` data URLs, at most
 *   `max_images` of them - the remaining pages reach the model only as text,
 *   which the caller puts into the prompt;
 * - with a schema and `json_schema`: `response_format` with the schema in
 *   strict mode; without `json_schema` the schema goes into the system
 *   prompt (app/prompts/schema-im-prompt-v1.md) and KiAbfrage digs the
 *   JSON out of whatever comes back;
 * - `max_completion_tokens` from the profile - the current name at OpenAI,
 *   also understood by Anthropic's compatible endpoint - and no
 *   `temperature`, which newer OpenAI models refuse;
 * - the timeout is the profile's: the caller runs each call in a request of
 *   its own (CLAUDE.md section 1).
 *
 * Nothing is logged; the request and the answer carry business data.
 */
final readonly class OpenAiCompatibleClient implements LlmClient
{
    public function __construct(
        private ChatHttp $http,
        private KiPrompts $prompts,
    ) {
    }

    public function chat(KiAnbieter $profil, #[\SensitiveParameter] string $apiKey, LlmAnfrage $anfrage): LlmAntwort
    {
        $body = $this->anfrage($profil, $anfrage);
        $start = hrtime(true);

        try {
            $antwort = $this->http->post($profil->endpunkt(), $apiKey, $body, $profil->timeoutS);
        } catch (KiVerbindungsfehler $e) {
            throw LlmFehler::ausVerbindungsfehler($e, $apiKey);
        }
        $dauerMs = (int) round((hrtime(true) - $start) / 1_000_000);

        if ($antwort->status < 200 || $antwort->status >= 300) {
            throw LlmFehler::http($antwort->status, $antwort->body, $apiKey);
        }

        return LlmAntwort::ausChatCompletion($antwort->body, $profil->model, $dauerMs);
    }

    private function anfrage(KiAnbieter $profil, LlmAnfrage $anfrage): string
    {
        $faehigkeiten = $profil->faehigkeiten;
        $schema = $anfrage->schema;

        $system = $anfrage->system;
        if ($schema !== null && !$faehigkeiten->jsonSchema) {
            $system = trim($system . "\n\n" . $this->prompts->text('schema-im-prompt-v1', ['schema' => $schema->alsJson()]));
        }

        $bilder = $faehigkeiten->vision ? array_slice($anfrage->bilder, 0, $faehigkeiten->maxImages) : [];
        $inhalt = $bilder === []
            ? $anfrage->text
            : [
                ['type' => 'text', 'text' => $anfrage->text],
                ...array_map(
                    static fn(string $jpeg): array => ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . base64_encode($jpeg)]],
                    $bilder,
                ),
            ];

        $nachrichten = [];
        if ($system !== '') {
            $nachrichten[] = ['role' => 'system', 'content' => $system];
        }
        $nachrichten[] = ['role' => 'user', 'content' => $inhalt];
        foreach ($anfrage->nachtrag as $nachricht) {
            $nachrichten[] = $nachricht;
        }

        $request = [
            'model' => $profil->model,
            'messages' => $nachrichten,
            'max_completion_tokens' => $faehigkeiten->maxTokens,
        ];
        if ($schema !== null && $faehigkeiten->jsonSchema) {
            $request['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => ['name' => $schema->name, 'strict' => true, 'schema' => $schema->schema],
            ];
        }

        return json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
