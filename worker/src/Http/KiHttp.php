<?php

declare(strict_types=1);

namespace App\Worker\Http;

use App\Service\Ki\ChatHttp;
use App\Service\Ki\ChatHttpAntwort;
use App\Service\Ki\KiVerbindungsfehler;

/**
 * The AI providers' transport on the worker: the web app's
 * App\Service\Ki\OpenAiCompatibleClient over the worker's own HTTP, so a
 * stop request cuts a long AI call off as well. https:// only, like
 * App\Service\Ki\CurlChatHttp - the API key never leaves in the clear.
 */
final readonly class KiHttp implements ChatHttp
{
    public function __construct(private WorkerHttp $http)
    {
    }

    public function post(string $url, #[\SensitiveParameter] string $apiKey, string $jsonBody, int $timeoutS): ChatHttpAntwort
    {
        if (!str_starts_with(strtolower($url), 'https://')) {
            throw KiVerbindungsfehler::verbindung('nur https:// ist erlaubt');
        }
        $header = ['Content-Type: application/json', 'Accept: application/json'];
        if ($apiKey !== '') {
            $header[] = 'Authorization: Bearer ' . $apiKey;
        }
        try {
            $antwort = $this->http->anfrage('POST', $url, $header, $jsonBody, $timeoutS);
        } catch (HttpFehler $e) {
            throw $e->zeitueberschreitung ? KiVerbindungsfehler::zeitueberschreitung($timeoutS) : KiVerbindungsfehler::verbindung($e->getMessage());
        }

        return new ChatHttpAntwort($antwort->status, $antwort->body);
    }
}
