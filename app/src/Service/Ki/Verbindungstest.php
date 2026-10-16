<?php

declare(strict_types=1);

namespace App\Service\Ki;

/**
 * "Verbindung testen" on the admin page (docs/spec/03-erfassung-und-ki.md
 * section 6): checks that endpoint, key and model answer - and finds out
 * what the model can do, so the profile can take it over (M7-1b, issue #42).
 * Providers differ in a way an error code does not show: Anthropic's
 * OpenAI-compatible endpoint, for one, ignores `response_format` without a
 * word. So the test looks at the answers.
 *
 * Two probes, at most three calls:
 *
 * 1. A text question that is to be answered in a sentence, sent with a
 *    `response_format` whose schema allows nothing but JSON. A server that
 *    enforces the schema answers with bare JSON, one that ignores it with
 *    the sentence: `json_schema` yes or no. A server that rejects the
 *    parameter (HTTP 400/422) gets the question once more without it and
 *    counts as "no". This probe is also the connection test proper.
 * 2. A generated red picture: the model must name the colour; that is
 *    `vision`. A rejected picture (HTTP 400/422) or a wrong answer is "no".
 *
 * Both are always asked, whatever the profile claims - the result is what
 * the model does, not what the form says. `max_images` and `max_tokens`
 * cannot be probed and stay. Any other failure (key, address, quota, time)
 * ends the test without a result: the profile is left as it is.
 *
 * No request may run long (CLAUDE.md section 1): each call is capped at
 * MAX_TIMEOUT_S and the profile's own timeout, all calls together at
 * GESAMT_S.
 *
 * The requests carry no `max_tokens` and no `temperature`: newer OpenAI
 * models reject both under these names, and the test only has to show that
 * the endpoint, the key and the model answer. How the real extraction
 * passes limits is the client's business (OpenAiCompatibleClient).
 *
 * Nothing here logs anything. Text from the provider (error messages, the
 * colour the model saw) is shortened and stripped of the API key before it
 * reaches the page - providers do echo keys back, partially masked or not.
 */
final readonly class Verbindungstest
{
    /** Longest wait for one call. */
    public const int MAX_TIMEOUT_S = 20;

    /** All calls of one test together - below max_execution_time (06 "Hosting"). */
    public const int GESAMT_S = 24;

    private const int BILD_KANTE = 64;

    public function __construct(private ChatHttp $http)
    {
    }

    public function pruefe(KiAnbieter $anbieter, #[\SensitiveParameter] string $apiKey): VerbindungstestErgebnis
    {
        if ($apiKey === '') {
            return new VerbindungstestErgebnis(false, 'Für dieses Profil ist kein API-Key hinterlegt.');
        }

        $start = hrtime(true);
        $ende = $start + self::GESAMT_S * 1_000_000_000;

        try {
            return $this->proben($anbieter, $apiKey, $start, $ende);
        } catch (KiVerbindungsfehler $e) {
            return new VerbindungstestErgebnis(false, KiAnbieterText::bereinigt($e->getMessage(), $apiKey), [], self::dauerMs($start));
        }
    }

    /**
     * @throws KiVerbindungsfehler
     */
    private function proben(KiAnbieter $anbieter, #[\SensitiveParameter] string $apiKey, int $start, int $ende): VerbindungstestErgebnis
    {
        // Probe 1: text, with a schema that only JSON can satisfy.
        $antwort = $this->senden($anbieter, $apiKey, self::textAnfrage($anbieter, mitSchema: true), $ende);
        $jsonSchema = null;
        if (self::abgelehnt($antwort->status)) {
            $antwort = $this->senden($anbieter, $apiKey, self::textAnfrage($anbieter, mitSchema: false), $ende);
            $jsonSchema = false;
        }
        $chat = self::chat($antwort);
        if (is_string($chat)) {
            return self::fehlschlag($chat, $antwort, $apiKey, [], $start);
        }
        $details = [
            'Modell: ' . KiAnbieterText::bereinigt($chat['modell'] ?? $anbieter->model, $apiKey),
        ];
        if ($chat['tokens'] !== null) {
            $details[] = 'Tokens: ' . $chat['tokens'];
        }
        $jsonSchema ??= self::schemaEingehalten($chat['inhalt']);

        // Probe 2: a picture the model has to name the colour of.
        $antwort = $this->senden($anbieter, $apiKey, self::bildAnfrage($anbieter), $ende);
        if (self::abgelehnt($antwort->status)) {
            $vision = false;
        } else {
            $chat = self::chat($antwort);
            if (is_string($chat)) {
                return self::fehlschlag($chat, $antwort, $apiKey, $details, $start);
            }
            $klein = mb_strtolower($chat['inhalt']);
            $vision = str_contains($klein, 'rot') || str_contains($klein, 'red');
        }

        $details[] = 'Bilder: ' . ($vision ? 'ja' : 'nein');
        $details[] = 'JSON-Schema: ' . ($jsonSchema ? 'ja' : 'nein');
        $aktuell = $anbieter->faehigkeiten;

        return new VerbindungstestErgebnis(
            true,
            'Verbindung erfolgreich.',
            $details,
            self::dauerMs($start),
            new KiFaehigkeiten(
                vision: $vision,
                jsonSchema: $jsonSchema,
                // A profile with pictures sends at least one (KiAnbieterService).
                maxImages: $vision ? max(1, $aktuell->maxImages) : $aktuell->maxImages,
                maxTokens: $aktuell->maxTokens,
            ),
        );
    }

    /**
     * @throws KiVerbindungsfehler also when the time budget is used up
     */
    private function senden(KiAnbieter $anbieter, #[\SensitiveParameter] string $apiKey, string $anfrage, int $ende): ChatHttpAntwort
    {
        $uebrigS = intdiv($ende - hrtime(true), 1_000_000_000);
        if ($uebrigS < 1) {
            throw KiVerbindungsfehler::zeitueberschreitung(self::GESAMT_S);
        }
        $timeout = max(1, min($anbieter->timeoutS, self::MAX_TIMEOUT_S, $uebrigS));

        return $this->http->post($anbieter->endpunkt(), $apiKey, $anfrage, $timeout);
    }

    /**
     * HTTP 400/422: the provider understood the request and refuses a part
     * of it - for the probes that is an answer ("cannot"), not a failure.
     */
    private static function abgelehnt(int $status): bool
    {
        return $status === 400 || $status === 422;
    }

    /**
     * @return array{inhalt: string, modell: ?string, tokens: ?int}|string
     *         the answer of a successful call - or, as a string, the
     *         message why there is none
     */
    private static function chat(ChatHttpAntwort $antwort): array|string
    {
        if ($antwort->status < 200 || $antwort->status >= 300) {
            return KiAnbieterText::httpMeldung($antwort->status);
        }

        $json = json_decode($antwort->body, true);
        $inhalt = is_array($json) ? ($json['choices'][0]['message']['content'] ?? null) : null;
        if (!is_array($json) || !is_string($inhalt)) {
            return 'Die Antwort ist keine Chat-Completions-Antwort – Basis-URL prüfen.';
        }

        return [
            'inhalt' => $inhalt,
            'modell' => is_string($json['model'] ?? null) ? $json['model'] : null,
            'tokens' => is_int($json['usage']['total_tokens'] ?? null) ? $json['usage']['total_tokens'] : null,
        ];
    }

    /**
     * @param list<string> $details
     */
    private static function fehlschlag(string $meldung, ChatHttpAntwort $antwort, #[\SensitiveParameter] string $apiKey, array $details, int $start): VerbindungstestErgebnis
    {
        if ($antwort->status < 200 || $antwort->status >= 300) {
            $text = KiAnbieterText::fehlertext($antwort->body, $apiKey);
            if ($text !== '') {
                $details[] = 'Antwort des Anbieters: „' . $text . '“';
            }
        }

        return new VerbindungstestErgebnis(false, $meldung, $details, self::dauerMs($start));
    }

    /**
     * Whether the answer is the bare JSON object of the probe schema -
     * only a server that enforces `response_format` gets there, because
     * the question asks for a sentence.
     */
    private static function schemaEingehalten(string $inhalt): bool
    {
        $daten = json_decode(trim($inhalt), true);

        return is_array($daten) && is_bool($daten['ok'] ?? null) && is_string($daten['farbe'] ?? null);
    }

    /**
     * OpenAI chat completions, the subset every compatible server
     * understands.
     */
    private static function textAnfrage(KiAnbieter $anbieter, bool $mitSchema): string
    {
        $anfrage = [
            'model' => $anbieter->model,
            'messages' => [[
                'role' => 'user',
                'content' => 'Verbindungstest der Software Vereinsbelege. '
                    . 'Beantworte in einem kurzen deutschen Satz: Welche Farbe hat der Himmel an einem klaren Tag?',
            ]],
        ];

        if ($mitSchema) {
            $anfrage['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'verbindungstest',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'ok' => ['type' => 'boolean'],
                            'farbe' => ['type' => 'string'],
                        ],
                        'required' => ['ok', 'farbe'],
                        'additionalProperties' => false,
                    ],
                ],
            ];
        }

        return self::json($anfrage);
    }

    private static function bildAnfrage(KiAnbieter $anbieter): string
    {
        return self::json([
            'model' => $anbieter->model,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Verbindungstest der Software Vereinsbelege. '
                            . 'Welche Farbe hat das beigefügte Bild? Antworte mit einem einzigen deutschen Wort.',
                    ],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . base64_encode(self::testbild())]],
                ],
            ]],
        ]);
    }

    /**
     * @param array<string, mixed> $anfrage
     */
    private static function json(array $anfrage): string
    {
        return json_encode($anfrage, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * A plain red square: the colour check needs no OCR and no luck.
     */
    private static function testbild(): string
    {
        $bild = imagecreatetruecolor(self::BILD_KANTE, self::BILD_KANTE);
        imagefill($bild, 0, 0, (int) imagecolorallocate($bild, 220, 20, 20));
        $strom = fopen('php://memory', 'r+');
        if ($strom === false) {
            return '';
        }
        imagejpeg($bild, $strom, 80);
        rewind($strom);

        return (string) stream_get_contents($strom);
    }

    private static function dauerMs(int|float $start): int
    {
        return (int) round((hrtime(true) - $start) / 1_000_000);
    }
}
