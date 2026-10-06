<?php

declare(strict_types=1);

namespace App\Service\Ki;

/**
 * ChatHttp over ext-curl (a hard requirement in composer.json; the M0
 * hosting check confirmed it and outbound HTTPS).
 *
 * - HTTPS only: the request carries the API key.
 * - Redirects are NOT followed: the Bearer header would go along to
 *   wherever the redirect points. A provider that redirects is answered
 *   with its 3xx status, and the admin corrects the base URL.
 * - No curl_close(): deprecated since PHP 8.5 and without effect since 8.0
 *   (same as App\Service\Update\ReleaseDownloader).
 */
final readonly class CurlChatHttp implements ChatHttp
{
    private const int CONNECT_TIMEOUT_S = 10;

    public function post(string $url, #[\SensitiveParameter] string $apiKey, string $jsonBody, int $timeoutS): ChatHttpAntwort
    {
        // Checked here as well as in the profile form: the key must never
        // leave over plain HTTP. (CURLOPT_PROTOCOLS_STR would need curl
        // 7.85, which the target host is not known to have.)
        if (!str_starts_with(strtolower($url), 'https://')) {
            throw KiVerbindungsfehler::verbindung('nur https:// ist erlaubt');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw KiVerbindungsfehler::verbindung('ungültige Adresse');
        }

        $header = ['Content-Type: application/json', 'Accept: application/json'];
        if ($apiKey !== '') {
            $header[] = 'Authorization: Bearer ' . $apiKey;
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_HTTPHEADER => $header,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(self::CONNECT_TIMEOUT_S, $timeoutS),
            CURLOPT_TIMEOUT => $timeoutS,
            CURLOPT_USERAGENT => 'Vereinsbelege',
        ]);

        $body = curl_exec($handle);
        $fehlernummer = curl_errno($handle);
        $fehler = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if ($body === false || $body === true) {
            throw $fehlernummer === CURLE_OPERATION_TIMEDOUT
                ? KiVerbindungsfehler::zeitueberschreitung($timeoutS)
                : KiVerbindungsfehler::verbindung($fehler);
        }

        return new ChatHttpAntwort($status, $body);
    }
}
