<?php

declare(strict_types=1);

namespace App\Service\Worker;

use App\Domain\Worker;
use App\Domain\WorkerStatus;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Repository\WorkerNonceRepository;
use App\Repository\WorkerRepository;
use App\Service\Crypto\ServerCrypto;
use App\Service\RateLimiter;

/**
 * Authenticates a request of the optional worker (docs/spec/07-worker.md
 * section 4, issue #51/M7b-1): HMAC-SHA256 over method, path, timestamp,
 * nonce and the body's SHA-256 with the worker's access key (wire format:
 * App\Service\Worker\WorkerProtokoll), a +-5 minute window, a nonce that is
 * accepted once, a failure limit per IP and a request limit per worker.
 *
 * app/src/routes.php wraps every route declared `Zugriff::worker()` in
 * ausfuehren(), derived from the declaration - a worker route cannot forget
 * the check. The answers never say WHICH part of a signature was wrong.
 */
final readonly class WorkerAuth
{
    /** Failed signatures per IP and window before the IP is shut out. */
    public const int FEHLER_LIMIT_PRO_IP = 30;
    public const int FEHLER_FENSTER_SEKUNDEN = 900;

    /** Signed requests per worker and minute (polling, chunk uploads). */
    public const int ANFRAGEN_LIMIT_PRO_MINUTE = 300;
    public const int ANFRAGEN_FENSTER_SEKUNDEN = 60;

    /**
     * How long a nonce has to be remembered: a request is accepted while
     * its timestamp is within the window either side of the server clock,
     * so a nonce seen now can be replayed for at most twice the window.
     */
    public const int NONCE_AUFBEWAHRUNG_SEKUNDEN = 2 * WorkerProtokoll::ZEITFENSTER_SEKUNDEN + 60;

    private const string ZWECK_FEHLER = 'worker_auth';
    private const string ZWECK_ANFRAGEN = 'worker_anfrage';

    /**
     * @param RateLimiter $fehlversuche window FEHLER_FENSTER_SEKUNDEN
     * @param RateLimiter $anfragen window ANFRAGEN_FENSTER_SEKUNDEN
     */
    public function __construct(
        private WorkerRepository $workers,
        private WorkerNonceRepository $nonces,
        private ServerCrypto $crypto,
        private RateLimiter $fehlversuche,
        private RateLimiter $anfragen,
    ) {
    }

    /**
     * @param array<string, string> $params
     * @param \Closure(Request, array<string, string>, Worker): ResponseInterface $handler
     */
    public function ausfuehren(Request $request, array $params, \Closure $handler): ResponseInterface
    {
        $ergebnis = $this->pruefen($request, new \DateTimeImmutable());

        return $ergebnis instanceof Worker ? $handler($request, $params, $ergebnis) : $ergebnis;
    }

    public function pruefen(Request $request, \DateTimeImmutable $now): Worker|Response
    {
        if ($this->fehlversuche->isBlocked(self::ZWECK_FEHLER, $request->ip, self::FEHLER_LIMIT_PRO_IP, $now)) {
            return self::antwort(429, 'zu_viele_fehlversuche', 'Zu viele fehlgeschlagene Anmeldungen – bitte später erneut versuchen.');
        }

        // Nothing a route reads may sit outside the signature.
        if ($request->query !== []) {
            return self::antwort(400, 'query_nicht_erlaubt', 'Worker-Anfragen tragen keine Query-Parameter.');
        }

        $zeit = $request->header(WorkerProtokoll::HEADER_ZEIT) ?? '';
        if (preg_match('/^\d{1,12}$/', $zeit) !== 1) {
            return $this->fehlschlag($request, $now);
        }
        // A wrong clock is not an attack and not counted - the worker gets
        // the server time to see what is off.
        if (abs($now->getTimestamp() - (int) $zeit) > WorkerProtokoll::ZEITFENSTER_SEKUNDEN) {
            return Response::json([
                'fehler' => 'zeitstempel',
                'meldung' => 'Die Uhrzeit des Workers weicht mehr als 5 Minuten ab.',
                'server_zeit' => $now->getTimestamp(),
            ], 401);
        }

        $kopf = $request->header(WorkerProtokoll::HEADER_AUTH) ?? $request->header(WorkerProtokoll::HEADER_AUTH_FALLBACK) ?? '';
        $nonce = $request->header(WorkerProtokoll::HEADER_NONCE) ?? '';
        $signatur = $request->header(WorkerProtokoll::HEADER_SIGNATUR) ?? '';
        if (
            preg_match('/^Worker ([1-9]\d{0,17})$/', $kopf, $treffer) !== 1
            || preg_match(WorkerProtokoll::NONCE_MUSTER, $nonce) !== 1
            || preg_match('/^[0-9a-f]{64}$/', $signatur) !== 1
        ) {
            return $this->fehlschlag($request, $now);
        }

        $worker = $this->workers->find((int) $treffer[1]);
        if ($worker === null || $worker->status === WorkerStatus::Gesperrt || $worker->secretEnc === null) {
            return $this->fehlschlag($request, $now);
        }

        $schluessel = $this->crypto->decrypt($worker->secretEnc);
        $erwartet = WorkerProtokoll::signatur($schluessel, $request->method->value, $request->path, (int) $zeit, $nonce, $request->body());
        sodium_memzero($schluessel);
        if (!hash_equals($erwartet, $signatur)) {
            return $this->fehlschlag($request, $now);
        }

        // Only a correctly signed request reaches the nonce table, so an
        // attacker without the key cannot fill it.
        if (!$this->nonces->merken($worker->id, $nonce, $now)) {
            return $this->fehlschlag($request, $now);
        }

        $id = (string) $worker->id;
        if ($this->anfragen->isBlocked(self::ZWECK_ANFRAGEN, $id, self::ANFRAGEN_LIMIT_PRO_MINUTE, $now)) {
            return self::antwort(429, 'zu_viele_anfragen', 'Zu viele Anfragen – bitte kurz warten.');
        }
        $this->anfragen->register(self::ZWECK_ANFRAGEN, $id, $now);

        return $worker;
    }

    private function fehlschlag(Request $request, \DateTimeImmutable $now): Response
    {
        $this->fehlversuche->registerFailure(self::ZWECK_FEHLER, $request->ip, $now);

        return self::antwort(401, 'nicht_authentifiziert', 'Anfrage nicht authentifiziert.');
    }

    private static function antwort(int $status, string $fehler, string $meldung): Response
    {
        return Response::json(['fehler' => $fehler, 'meldung' => $meldung], $status);
    }
}
