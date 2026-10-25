<?php

declare(strict_types=1);

namespace App\Worker\Http;

use App\Worker\Abbruch;

/**
 * WorkerHttp over curl. https:// only (docs/spec/07-worker.md section 5:
 * access key, grants and results must not travel in the clear - the HMAC
 * protects integrity, not confidentiality); plain http:// only to the
 * local machine and only when the operator asked for it explicitly
 * (WORKER_ERLAUBE_HTTP_LOCALHOST, for tests). No redirects - a redirect
 * could lead the signed request or an API key elsewhere. The body is
 * capped; a stop request cuts a running transfer off unless the call is
 * marked as not interruptible (the final `fail` that gives a job back).
 *
 * No curl_close(): deprecated since PHP 8.5 (as App\Service\Ki\CurlChatHttp).
 */
final readonly class CurlWorkerHttp implements WorkerHttp
{
    private const int VERBINDEN_SEKUNDEN = 15;

    /** Largest answer accepted (a blob is at most 64 MiB of ciphertext). */
    public const int MAX_ANTWORT_BYTES = 80 * 1024 * 1024;

    public function __construct(
        private Abbruch $abbruch,
        private bool $httpLocalhostErlaubt = false,
        private string $benutzerAgent = 'Vereinsbelege-Worker',
    ) {
    }

    public function anfrage(string $methode, string $url, array $header, string $body, int $zeitlimitSekunden, bool $abbrechbar = true): HttpAntwort
    {
        if (!UrlRegel::erlaubt($url, $this->httpLocalhostErlaubt)) {
            throw new HttpFehler('Nur https:// ist erlaubt.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new HttpFehler('Ungültige Adresse.');
        }

        $antwortKopf = [];
        $antwortBody = '';
        $zuGross = false;
        $abbruch = $this->abbruch;
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => strtoupper($methode),
            CURLOPT_HTTPHEADER => $header,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(self::VERBINDEN_SEKUNDEN, max(1, $zeitlimitSekunden)),
            CURLOPT_TIMEOUT => max(1, $zeitlimitSekunden),
            CURLOPT_USERAGENT => $this->benutzerAgent,
            CURLOPT_HEADERFUNCTION => static function ($h, string $zeile) use (&$antwortKopf): int {
                $teile = explode(':', $zeile, 2);
                if (count($teile) === 2) {
                    $antwortKopf[strtolower(trim($teile[0]))] = trim($teile[1]);
                }

                return strlen($zeile);
            },
            CURLOPT_WRITEFUNCTION => static function ($h, string $stueck) use (&$antwortBody, &$zuGross): int {
                if (strlen($antwortBody) + strlen($stueck) > self::MAX_ANTWORT_BYTES) {
                    $zuGross = true;

                    return 0;
                }
                $antwortBody .= $stueck;

                return strlen($stueck);
            },
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => static fn(): int => $abbrechbar && $abbruch->angefordert() ? 1 : 0,
        ]);
        if ($body !== '' || strtoupper($methode) === 'POST') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $ok = curl_exec($handle);
        $nummer = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($ok === false) {
            if ($zuGross) {
                throw new HttpFehler('Antwort zu groß.');
            }
            throw match ($nummer) {
                CURLE_OPERATION_TIMEDOUT => new HttpFehler('Zeitüberschreitung.', zeitueberschreitung: true),
                CURLE_ABORTED_BY_CALLBACK => new HttpFehler('Abgebrochen.', abgebrochen: true),
                // The message names host and cause, no content.
                default => new HttpFehler('Verbindungsfehler (curl ' . $nummer . ').'),
            };
        }

        return new HttpAntwort($status, $antwortKopf, $antwortBody);
    }
}
