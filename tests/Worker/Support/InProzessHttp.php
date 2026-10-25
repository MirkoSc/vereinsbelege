<?php

declare(strict_types=1);

namespace App\Tests\Worker\Support;

use App\Api\WorkerApiController;
use App\Api\WorkerMailController;
use App\Domain\Worker;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Response;
use App\Http\StreamResponse;
use App\Service\Worker\WorkerAuth;
use App\Worker\Http\HttpAntwort;
use App\Worker\Http\WorkerHttp;

/**
 * The web host in the same process: the worker's requests go through the
 * real signature check (WorkerAuth) into the real WorkerApiController, as
 * app/src/routes.php wires them - so the worker program is tested against
 * the web host's code, not against a copy of the protocol.
 */
final class InProzessHttp implements WorkerHttp
{
    /** @var list<string> "METHOD path" of every request */
    public array $anfragen = [];

    /** @var list<string> every nonce sent */
    public array $nonces = [];

    public function __construct(
        private readonly WorkerAuth $auth,
        private readonly WorkerApiController $api,
        private readonly ?WorkerMailController $mail = null,
    ) {
    }

    public function anfrage(string $methode, string $url, array $header, string $body, int $zeitlimitSekunden, bool $abbrechbar = true): HttpAntwort
    {
        $pfad = (string) parse_url($url, PHP_URL_PATH);
        $kopf = [];
        foreach ($header as $zeile) {
            [$name, $wert] = array_map(trim(...), explode(':', $zeile, 2));
            $kopf[strtolower($name)] = $wert;
        }
        $this->anfragen[] = $methode . ' ' . $pfad;
        if (isset($kopf['x-worker-nonce'])) {
            $this->nonces[] = $kopf['x-worker-nonce'];
        }
        $request = new Request(HttpMethod::from($methode), $pfad, headers: $kopf, ip: '198.51.100.7', rawBody: $body);

        if ($pfad === '/api/worker/pair') {
            return self::antwort($this->api->pair($request));
        }
        foreach ($this->routen() as $muster => $handler) {
            if (preg_match($muster, $methode . ' ' . $pfad, $treffer) === 1) {
                $params = array_filter($treffer, is_string(...), ARRAY_FILTER_USE_KEY);

                return self::antwort($this->auth->ausfuehren($request, $params, $handler));
            }
        }

        return new HttpAntwort(404, [], '{"fehler":"route"}');
    }

    /** @return array<string, \Closure(Request, array<string, string>, Worker)> */
    private function routen(): array
    {
        $api = $this->api;
        $mail = $this->mail;
        $mailRouten = $mail === null ? [] : [
            '#^POST /api/worker/mail/pruefen$#' => static fn(Request $r, array $p, Worker $w) => $mail->pruefen($r, $w),
            '#^POST /api/worker/mail/uploads$#' => static fn(Request $r, array $p, Worker $w) => $mail->uploadAnlegen($r, $w),
            '#^POST /api/worker/mail/uploads/(?<upload>[0-9a-f]{32})/chunks/(?<n>\d+)$#' => static fn(Request $r, array $p, Worker $w) => $mail->uploadChunk($r, $p, $w),
            '#^POST /api/worker/mail/uploads/(?<upload>[0-9a-f]{32})/finish$#' => static fn(Request $r, array $p, Worker $w) => $mail->uploadAbschliessen($r, $p, $w),
            '#^POST /api/worker/mail/einreichen$#' => static fn(Request $r, array $p, Worker $w) => $mail->einreichen($r, $w),
        ];

        return $mailRouten + [
            '#^POST /api/worker/heartbeat$#' => static fn(Request $r, array $p, Worker $w) => $api->heartbeat($r, $w),
            '#^POST /api/worker/jobs/claim$#' => static fn(Request $r, array $p, Worker $w) => $api->claim($r, $w),
            '#^POST /api/worker/jobs/(?<id>\d+)/extend$#' => static fn(Request $r, array $p, Worker $w) => $api->extend($r, $p, $w),
            '#^POST /api/worker/jobs/(?<id>\d+)/complete$#' => static fn(Request $r, array $p, Worker $w) => $api->complete($r, $p, $w),
            '#^POST /api/worker/jobs/(?<id>\d+)/fail$#' => static fn(Request $r, array $p, Worker $w) => $api->fail($r, $p, $w),
            '#^GET /api/worker/jobs/(?<id>\d+)/context$#' => static fn(Request $r, array $p, Worker $w) => $api->context($r, $p, $w),
            '#^GET /api/worker/blobs/(?<id>\d+)$#' => static fn(Request $r, array $p, Worker $w) => $api->blob($r, $p, $w),
            '#^POST /api/worker/jobs/(?<id>\d+)/artifacts$#' => static fn(Request $r, array $p, Worker $w) => $api->artefaktAnmelden($r, $p, $w),
            '#^POST /api/worker/jobs/(?<id>\d+)/artifacts/(?<artefakt>\d+)/chunks/(?<index>\d+)$#' => static fn(Request $r, array $p, Worker $w) => $api->artefaktChunk($r, $p, $w),
            '#^POST /api/worker/jobs/(?<id>\d+)/artifacts/(?<artefakt>\d+)/commit$#' => static fn(Request $r, array $p, Worker $w) => $api->artefaktFestschreiben($r, $p, $w),
        ];
    }

    private static function antwort(mixed $antwort): HttpAntwort
    {
        if ($antwort instanceof StreamResponse) {
            $body = '';
            foreach ($antwort->chunks as $stueck) {
                $body .= $stueck;
            }

            return new HttpAntwort($antwort->status, array_change_key_case($antwort->headers), $body);
        }
        if (!$antwort instanceof Response) {
            throw new \LogicException('Unexpected response type.');
        }

        return new HttpAntwort($antwort->status, array_change_key_case($antwort->headers), $antwort->body);
    }
}
