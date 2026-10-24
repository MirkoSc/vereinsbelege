<?php

declare(strict_types=1);

namespace App\Service\Export\Nextcloud;

/**
 * WebDAV over ext-curl (issue #95) - no process calls (CLAUDE.md section 1).
 *
 * - HTTPS only: the app password goes with every request (checked again
 *   here, not only in the form).
 * - Redirects are NOT followed: the credentials would go along to wherever
 *   the redirect points; the admin corrects the address instead.
 * - The upload streams: CURLOPT_READFUNCTION pulls the decrypted chunks
 *   from the generator, nothing is written to disk.
 * - Short timeouts: a step of the chain is a short request.
 * - No curl_close(): deprecated since PHP 8.5 and without effect since 8.0
 *   (same as App\Service\Ki\CurlChatHttp).
 */
final readonly class CurlWebDav implements WebDav
{
    private const int CONNECT_TIMEOUT_S = 10;

    private const int TIMEOUT_S = 20;

    /** A single file's upload; the step starts no new file after its budget. */
    private const int UPLOAD_TIMEOUT_S = 25;

    public function eigenschaften(NextcloudZiel $ziel, string $pfad): ?DavEintrag
    {
        [$status, $body] = $this->anfrage($ziel, 'PROPFIND', $pfad, [
            CURLOPT_POSTFIELDS => DavAntwort::PROPFIND,
            CURLOPT_HTTPHEADER => ['Depth: 0', 'Content-Type: application/xml; charset=utf-8'],
        ], self::TIMEOUT_S);

        if ($status === 404) {
            return null;
        }
        if ($status !== 207) {
            throw WebDavFehler::status($status, 'beim Lesen');
        }

        return DavAntwort::lesen($body);
    }

    public function lesen(NextcloudZiel $ziel, string $pfad, int $maxBytes): string
    {
        [$status, $body] = $this->anfrage($ziel, 'GET', $pfad, [
            // Stop as soon as the body outgrows what we compare with.
            CURLOPT_MAXFILESIZE => $maxBytes,
        ], self::UPLOAD_TIMEOUT_S);

        if ($status !== 200) {
            throw WebDavFehler::status($status, 'beim Lesen');
        }
        if (strlen($body) > $maxBytes) {
            throw WebDavFehler::antwort('Datei größer als erwartet');
        }

        return $body;
    }

    public function ordnerAnlegen(NextcloudZiel $ziel, string $pfad): void
    {
        [$status] = $this->anfrage($ziel, 'MKCOL', $pfad, [], self::TIMEOUT_S);

        // 405: the folder exists already.
        if ($status !== 201 && $status !== 405) {
            throw WebDavFehler::status($status, 'beim Anlegen eines Ordners');
        }
    }

    public function hochladen(NextcloudZiel $ziel, string $pfad, iterable $inhalt, int $groesse, string $sha1): void
    {
        $quelle = (static fn(): \Generator => yield from $inhalt)();
        $puffer = '';
        $fehler = null;

        $lesen = static function ($handle, $datei, int $laenge) use ($quelle, &$puffer, &$fehler): string {
            try {
                while (strlen($puffer) < $laenge && $quelle->valid()) {
                    $puffer .= (string) $quelle->current();
                    $quelle->next();
                }
            } catch (\Throwable $e) {
                // Cut the body short: Nextcloud refuses a PUT shorter than
                // its Content-Length, and the error is raised below.
                $fehler = $e;

                return '';
            }
            $stueck = substr($puffer, 0, $laenge);
            $puffer = substr($puffer, strlen($stueck));

            return $stueck;
        };

        try {
            [$status] = $this->anfrage($ziel, 'PUT', $pfad, [
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILESIZE => $groesse,
                CURLOPT_READFUNCTION => $lesen,
                CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', 'OC-Checksum: SHA1:' . $sha1, 'Expect:'],
            ], self::UPLOAD_TIMEOUT_S);
        } catch (WebDavFehler $e) {
            // The cut-short body is the cause, not the connection.
            throw $fehler ?? $e;
        }

        if ($fehler !== null) {
            throw $fehler;
        }
        if ($status !== 201 && $status !== 204) {
            throw WebDavFehler::status($status, 'beim Hochladen');
        }
    }

    /**
     * @param array<int, mixed> $optionen
     *
     * @return array{int, string} status and body
     */
    private function anfrage(NextcloudZiel $ziel, string $methode, string $pfad, array $optionen, int $timeoutS): array
    {
        $url = $ziel->adresse($pfad);
        if (!str_starts_with(strtolower($url), 'https://')) {
            throw WebDavFehler::verbindung('nur https:// ist erlaubt');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw WebDavFehler::verbindung('ungültige Adresse');
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $methode,
            CURLOPT_USERPWD => $ziel->benutzer . ':' . $ziel->passwort,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_S,
            CURLOPT_TIMEOUT => $timeoutS,
            CURLOPT_USERAGENT => 'Vereinsbelege',
        ] + $optionen);

        $body = curl_exec($handle);
        $fehlernummer = curl_errno($handle);
        $fehler = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if ($body === false || ($fehlernummer !== 0 && $status === 0)) {
            throw $fehlernummer === CURLE_OPERATION_TIMEDOUT
                ? WebDavFehler::verbindung('Zeitüberschreitung')
                // curl names the host at most, never the path.
                : WebDavFehler::verbindung($fehler !== '' ? $fehler : 'unbekannter Fehler');
        }

        return [$status, is_string($body) ? $body : ''];
    }
}
