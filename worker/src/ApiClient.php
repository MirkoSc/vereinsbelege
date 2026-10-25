<?php

declare(strict_types=1);

namespace App\Worker;

use App\Service\Worker\WorkerProtokoll;
use App\Worker\Http\HttpAntwort;
use App\Worker\Http\HttpFehler;
use App\Worker\Http\UrlRegel;
use App\Worker\Http\WorkerHttp;

/**
 * The worker's side of `/api/worker/*` (docs/spec/07-worker.md sections 4
 * and 5): signs every request with App\Service\Worker\WorkerProtokoll -
 * the very code the web host verifies with - and retries what may be
 * retried.
 *
 * Every attempt is signed anew: a fresh nonce and the current time, also
 * for the repetition after a timeout or a network error (section 5,
 * review of #52/#53) - the web host refuses a nonce it has seen as a
 * replay, even when the first attempt never reached the application.
 *
 * Retried, with growing pauses: no answer at all, 429, 500, 502, 503, 504.
 * Not retried: every other answer, and nothing once a stop was requested.
 * The clock: a Raspberry Pi has no battery-backed clock, so the worker
 * keeps the offset to the web host's time (`server_zeit` of the heartbeat,
 * the `Date` header of a 401) and signs with the web host's time.
 */
final class ApiClient
{
    public const string PRAEFIX = '/api/worker';

    /** Attempts per request in total. */
    public const int VERSUCHE = 4;

    private const int ZEITLIMIT_SEKUNDEN = 120;

    private const array WIEDERHOLBAR = [429, 500, 502, 503, 504];

    /** Seconds the web host's clock is ahead of ours. */
    private int $versatz = 0;

    private bool $zeitKorrigiert = false;

    /**
     * @param \Closure(): int $uhr unix time (tests pass their own)
     * @param \Closure(float): bool $schlafen pauses between attempts; false = stop requested
     */
    public function __construct(
        private readonly WorkerHttp $http,
        private readonly Identitaet $identitaet,
        private readonly Abbruch $abbruch,
        private readonly \Closure $uhr,
        private readonly \Closure $schlafen,
        private readonly bool $httpLocalhostErlaubt = false,
    ) {
        // The stored address is checked again at every start: a hand-edited
        // configuration must not turn the worker into a plain-HTTP client.
        UrlRegel::basis($identitaet->basisUrl, $httpLocalhostErlaubt);
    }

    /**
     * `POST /pair` - the one unsigned call, its code is the credential.
     * Not retried: a code is used up by the first attempt that arrives,
     * and a lost answer needs a new code anyway.
     *
     * @param array<string, mixed> $daten
     *
     * @return array<string, mixed>
     */
    public static function koppeln(WorkerHttp $http, string $basisUrl, array $daten): array
    {
        $body = json_encode($daten, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $antwort = $http->anfrage('POST', $basisUrl . self::PRAEFIX . '/pair', ['Content-Type: application/json', 'Accept: application/json'], $body, 60, false);

        return self::auswerten($antwort);
    }

    public function versatz(): int
    {
        return $this->versatz;
    }

    /** Takes over the web host's time from a heartbeat answer. */
    public function serverZeit(int $serverZeit, int $lokaleZeit): void
    {
        if ($serverZeit > 0) {
            $this->versatz = $serverZeit - $lokaleZeit;
            $this->zeitKorrigiert = true;
        }
    }

    /**
     * A signed JSON request; the answer as array.
     *
     * @param ?array<string, mixed> $daten null = empty body (GET)
     *
     * @return array<string, mixed>
     *
     * @throws ApiFehler|HttpFehler|Abgebrochen
     */
    public function json(string $methode, string $pfad, ?array $daten = null, bool $abbrechbar = true, ?int $zeitlimit = null): array
    {
        $body = $daten === null ? '' : json_encode($daten, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return self::auswerten($this->senden($methode, $pfad, $body, $daten === null ? null : 'application/json', $abbrechbar, $zeitlimit));
    }

    /**
     * A signed request with a raw body (a chunk of ciphertext).
     *
     * @return array<string, mixed>
     */
    public function roh(string $pfad, string $body): array
    {
        return self::auswerten($this->senden('POST', $pfad, $body, 'application/octet-stream', true));
    }

    /**
     * A signed GET whose body is binary (a blob). Error answers are JSON.
     *
     * @throws ApiFehler|HttpFehler|Abgebrochen
     */
    public function datei(string $pfad): HttpAntwort
    {
        $antwort = $this->senden('GET', $pfad, '', null, true);
        if ($antwort->status >= 400) {
            self::auswerten($antwort);
        }

        return $antwort;
    }

    private function senden(string $methode, string $pfad, string $body, ?string $typ, bool $abbrechbar, ?int $zeitlimit = null): HttpAntwort
    {
        $pfad = self::PRAEFIX . $pfad;
        // The signature covers the path as the web host receives it: the
        // path part of the base address, then the API path - no query.
        $basisPfad = (string) (parse_url($this->identitaet->basisUrl, PHP_URL_PATH) ?? '');
        $url = rtrim($this->identitaet->basisUrl, '/') . $pfad;
        $signiert = rtrim($basisPfad, '/') . $pfad;

        $letzterFehler = null;
        // Shutting down: one attempt, no pauses (the job is given back).
        $versuche = $this->abbruch->angefordert() ? 1 : self::VERSUCHE;
        for ($versuch = 1; $versuch <= $versuche; ++$versuch) {
            if ($versuch > 1) {
                if ($abbrechbar) {
                    $this->abbruch->pruefen();
                }
                if (!($this->schlafen)(min(30.0, 2.0 ** ($versuch - 1))) && $abbrechbar) {
                    $this->abbruch->pruefen();
                }
            }
            // A new nonce and time for EVERY attempt (section 5).
            $zeit = ($this->uhr)() + $this->versatz;
            $kopf = WorkerProtokoll::header($this->identitaet->workerId, $this->identitaet->schluessel, $methode, $signiert, $body, $zeit, WorkerProtokoll::neueNonce());
            $header = ['Accept: application/json'];
            foreach ($kopf as $name => $wert) {
                $header[] = $name . ': ' . $wert;
            }
            // Both auth headers: some hosts drop Authorization on FastCGI.
            $header[] = WorkerProtokoll::HEADER_AUTH_FALLBACK . ': ' . $kopf[WorkerProtokoll::HEADER_AUTH];
            if ($typ !== null) {
                $header[] = 'Content-Type: ' . $typ;
            }

            try {
                $antwort = $this->http->anfrage($methode, $url, $header, $body, $zeitlimit ?? self::ZEITLIMIT_SEKUNDEN, $abbrechbar);
            } catch (HttpFehler $e) {
                if ($e->abgebrochen) {
                    throw new Abgebrochen('Beenden angefordert.', 0, $e);
                }
                $letzterFehler = $e;
                continue;
            }

            if ($antwort->status === 401 && $versuch < $versuche && !$this->zeitKorrigiert && $this->korrigiereZeit($antwort)) {
                // Our clock was off: once more with the web host's time.
                continue;
            }
            if (in_array($antwort->status, self::WIEDERHOLBAR, true) && $versuch < $versuche) {
                $letzterFehler = null;
                continue;
            }

            return $antwort;
        }

        throw $letzterFehler ?? new HttpFehler('Webhoster nicht erreichbar.');
    }

    /** Adopts the offset from a `Date` header when ours is outside the window. */
    private function korrigiereZeit(HttpAntwort $antwort): bool
    {
        $datum = $antwort->kopf('date');
        $server = $datum === null ? false : strtotime($datum);
        if ($server === false) {
            return false;
        }
        $versatz = $server - ($this->uhr)();
        $this->zeitKorrigiert = true;
        if (abs($versatz - $this->versatz) < WorkerProtokoll::ZEITFENSTER_SEKUNDEN) {
            return false;
        }
        $this->versatz = $versatz;

        return true;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ApiFehler
     */
    private static function auswerten(HttpAntwort $antwort): array
    {
        $daten = json_decode($antwort->body, true);
        $daten = is_array($daten) ? $daten : [];
        if ($antwort->status >= 400 || $antwort->status < 200) {
            throw new ApiFehler(
                $antwort->status,
                is_string($daten['fehler'] ?? null) ? $daten['fehler'] : 'http_' . $antwort->status,
                is_string($daten['meldung'] ?? null) ? $daten['meldung'] : '',
            );
        }

        return $daten;
    }
}
