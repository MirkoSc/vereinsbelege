<?php

declare(strict_types=1);

namespace App\Service\Ki;

/**
 * "Verbindung testen" on the admin page (docs/spec/03-erfassung-und-ki.md
 * section 6): one short chat call against the profile, with a fixed mini
 * prompt and - if the profile claims `vision` - a generated test picture,
 * and - if it claims `json_schema` - a `response_format` with a tiny
 * schema. One call checks everything the profile claims at once, because
 * no request may run long (CLAUDE.md section 1): the timeout is the
 * profile's, capped at MAX_TIMEOUT_S.
 *
 * The request carries no `max_tokens` and no `temperature`: newer OpenAI
 * models reject both under these names, and the test only has to show that
 * the endpoint, the key and the model answer. How the real extraction
 * passes limits is M7-2's business (LlmClient).
 *
 * Nothing here logs anything. Text from the provider (error messages, the
 * colour the model saw) is shortened and stripped of the API key before it
 * reaches the page - providers do echo keys back, partially masked or not.
 */
final readonly class Verbindungstest
{
    public const int MAX_TIMEOUT_S = 20;

    private const int TEXT_MAX = 200;

    private const int BILD_KANTE = 64;

    public function __construct(private ChatHttp $http)
    {
    }

    public function pruefe(KiAnbieter $anbieter, #[\SensitiveParameter] string $apiKey): VerbindungstestErgebnis
    {
        if ($apiKey === '') {
            return new VerbindungstestErgebnis(false, 'Für dieses Profil ist kein API-Key hinterlegt.');
        }

        $timeout = max(1, min($anbieter->timeoutS, self::MAX_TIMEOUT_S));
        $start = hrtime(true);

        try {
            $antwort = $this->http->post($anbieter->endpunkt(), $apiKey, self::anfrage($anbieter), $timeout);
        } catch (KiVerbindungsfehler $e) {
            return new VerbindungstestErgebnis(false, self::bereinigt($e->getMessage(), $apiKey), [], self::dauerMs($start));
        }

        return self::auswerten($anbieter, $antwort, $apiKey, self::dauerMs($start));
    }

    /**
     * The request body: OpenAI chat completions, the subset every
     * compatible server understands.
     */
    private static function anfrage(KiAnbieter $anbieter): string
    {
        $prompt = 'Verbindungstest der Software Vereinsbelege. Antworte ausschließlich mit einem JSON-Objekt '
            . 'der Form {"ok": true, "farbe": "…"}. '
            . ($anbieter->faehigkeiten->vision
                ? 'Setze bei "farbe" die Farbe des beigefügten Bildes als ein deutsches Wort ein.'
                : 'Setze bei "farbe" das Wort "keine" ein.');

        $inhalt = $anbieter->faehigkeiten->vision
            ? [
                ['type' => 'text', 'text' => $prompt],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . base64_encode(self::testbild())]],
            ]
            : $prompt;

        $anfrage = [
            'model' => $anbieter->model,
            'messages' => [['role' => 'user', 'content' => $inhalt]],
        ];

        if ($anbieter->faehigkeiten->jsonSchema) {
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

        return json_encode($anfrage, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function auswerten(KiAnbieter $anbieter, ChatHttpAntwort $antwort, string $apiKey, int $dauerMs): VerbindungstestErgebnis
    {
        if ($antwort->status < 200 || $antwort->status >= 300) {
            $text = self::fehlertext($antwort->body, $apiKey);

            return new VerbindungstestErgebnis(
                false,
                self::httpMeldung($antwort->status),
                $text === '' ? [] : ['Antwort des Anbieters: „' . $text . '“'],
                $dauerMs,
            );
        }

        $json = json_decode($antwort->body, true);
        $inhalt = is_array($json) ? ($json['choices'][0]['message']['content'] ?? null) : null;
        if (!is_string($inhalt)) {
            return new VerbindungstestErgebnis(
                false,
                'Die Antwort ist keine Chat-Completions-Antwort – Basis-URL prüfen.',
                [],
                $dauerMs,
            );
        }

        $details = [];
        $modell = is_string($json['model'] ?? null) ? $json['model'] : $anbieter->model;
        $details[] = 'Modell: ' . self::bereinigt($modell, $apiKey);
        $tokens = $json['usage']['total_tokens'] ?? null;
        if (is_int($tokens)) {
            $details[] = 'Tokens: ' . $tokens;
        }

        $faehigkeiten = $anbieter->faehigkeiten;
        $daten = self::jsonObjekt($inhalt, nurExakt: $faehigkeiten->jsonSchema);
        if ($daten === null) {
            return new VerbindungstestErgebnis(
                false,
                $faehigkeiten->jsonSchema
                    ? 'Verbindung steht, aber die Antwort war kein gültiges JSON – Fähigkeit „JSON-Schema“ prüfen.'
                    : 'Verbindung steht, aber die Antwort enthielt kein gültiges JSON.',
                $details,
                $dauerMs,
            );
        }
        if (($daten['ok'] ?? null) !== true) {
            return new VerbindungstestErgebnis(false, 'Verbindung steht, aber die Antwort entspricht nicht der Vorgabe.', $details, $dauerMs);
        }

        if ($faehigkeiten->vision) {
            $farbe = is_string($daten['farbe'] ?? null) ? $daten['farbe'] : '';
            $klein = mb_strtolower($farbe);
            if (!str_contains($klein, 'rot') && !str_contains($klein, 'red')) {
                return new VerbindungstestErgebnis(
                    false,
                    sprintf(
                        'Verbindung steht, aber das Testbild wurde nicht erkannt (Antwort: „%s“) – Fähigkeit „Bilder“ prüfen.',
                        self::bereinigt($farbe, $apiKey),
                    ),
                    $details,
                    $dauerMs,
                );
            }
            $details[] = 'Testbild erkannt';
        }
        if ($faehigkeiten->jsonSchema) {
            $details[] = 'JSON-Schema eingehalten';
        }

        return new VerbindungstestErgebnis(true, 'Verbindung erfolgreich.', $details, $dauerMs);
    }

    private static function httpMeldung(int $status): string
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
    private static function fehlertext(string $body, string $apiKey): string
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
     * @return array<mixed>|null the JSON object in the answer; without
     *         `json_schema` the model may wrap it in prose or a code fence,
     *         so the outermost braces are tried as well
     */
    private static function jsonObjekt(string $inhalt, bool $nurExakt): ?array
    {
        $daten = json_decode(trim($inhalt), true);
        if (is_array($daten)) {
            return $daten;
        }
        if ($nurExakt) {
            return null;
        }

        $anfang = strpos($inhalt, '{');
        $ende = strrpos($inhalt, '}');
        if ($anfang === false || $ende === false || $ende < $anfang) {
            return null;
        }
        $daten = json_decode(substr($inhalt, $anfang, $ende - $anfang + 1), true);

        return is_array($daten) ? $daten : null;
    }

    /**
     * Provider text made safe to show: one line, short, and without the
     * API key - the full key and anything shaped like an OpenAI-style key
     * (`sk-…`, also the partially masked echo of a 401) become `***`.
     */
    private static function bereinigt(string $text, #[\SensitiveParameter] string $apiKey): string
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
