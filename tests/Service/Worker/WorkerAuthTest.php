<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Domain\Worker;
use App\Http\Request;
use App\Http\Response;
use App\Service\Worker\WorkerAuth;
use App\Service\Worker\WorkerProtokoll;

/**
 * The HMAC check of the worker API (docs/spec/07-worker.md section 4 and
 * "Pflicht-Tests": wrong key, expired timestamp, nonce replay, manipulated
 * body; issue #51/M7b-1).
 */
final class WorkerAuthTest extends WorkerTestCase
{
    private const string PFAD = '/api/worker/heartbeat';

    public function testACorrectlySignedRequestNamesTheWorker(): void
    {
        [$id, $schluessel] = $this->koppeln();

        $ergebnis = $this->auth->pruefen($this->signiert($id, $schluessel, self::PFAD, '{"a":1}'), new \DateTimeImmutable());

        self::assertInstanceOf(Worker::class, $ergebnis);
        self::assertSame($id, $ergebnis->id);
    }

    public function testTheFallbackHeaderWorksWhenAuthorizationIsDropped(): void
    {
        [$id, $schluessel] = $this->koppeln();
        $anfrage = $this->signiert($id, $schluessel, self::PFAD);
        $header = $anfrage->headers;
        $header[WorkerProtokoll::HEADER_AUTH_FALLBACK] = $header[WorkerProtokoll::HEADER_AUTH];
        unset($header[WorkerProtokoll::HEADER_AUTH]);

        $ergebnis = $this->auth->pruefen(new Request($anfrage->method, self::PFAD, headers: $header, rawBody: ''), new \DateTimeImmutable());

        self::assertInstanceOf(Worker::class, $ergebnis);
    }

    public function testAWrongKeyIsRefused(): void
    {
        [$id] = $this->koppeln();

        $this->assertAbgewiesen($this->signiert($id, random_bytes(32), self::PFAD), 401, 'nicht_authentifiziert');
    }

    public function testAManipulatedBodyIsRefused(): void
    {
        [$id, $schluessel] = $this->koppeln();
        $anfrage = $this->signiert($id, $schluessel, self::PFAD, '{"max":1}');

        $this->assertAbgewiesen(new Request($anfrage->method, self::PFAD, headers: $anfrage->headers, rawBody: '{"max":5}'), 401, 'nicht_authentifiziert');
    }

    public function testASignatureForAnotherPathIsRefused(): void
    {
        [$id, $schluessel] = $this->koppeln();
        $anfrage = $this->signiert($id, $schluessel, self::PFAD);

        $this->assertAbgewiesen(new Request($anfrage->method, '/api/worker/jobs/claim', headers: $anfrage->headers, rawBody: ''), 401, 'nicht_authentifiziert');
    }

    public function testAnExpiredOrFutureTimestampIsRefusedWithTheServerTime(): void
    {
        [$id, $schluessel] = $this->koppeln();

        foreach ([time() - WorkerProtokoll::ZEITFENSTER_SEKUNDEN - 5, time() + WorkerProtokoll::ZEITFENSTER_SEKUNDEN + 5] as $zeit) {
            $antwort = $this->auth->pruefen($this->signiert($id, $schluessel, self::PFAD, zeit: $zeit), new \DateTimeImmutable());
            self::assertInstanceOf(Response::class, $antwort);
            self::assertSame(401, $antwort->status);
            self::assertSame('zeitstempel', self::daten($antwort)['fehler']);
            self::assertIsInt(self::daten($antwort)['server_zeit']);
        }
    }

    public function testATimestampInsideTheWindowIsAccepted(): void
    {
        [$id, $schluessel] = $this->koppeln();

        $ergebnis = $this->auth->pruefen($this->signiert($id, $schluessel, self::PFAD, zeit: time() - WorkerProtokoll::ZEITFENSTER_SEKUNDEN + 10), new \DateTimeImmutable());

        self::assertInstanceOf(Worker::class, $ergebnis);
    }

    public function testANonceIsAcceptedOnlyOnce(): void
    {
        [$id, $schluessel] = $this->koppeln();
        $anfrage = $this->signiert($id, $schluessel, self::PFAD, '{}');

        self::assertInstanceOf(Worker::class, $this->auth->pruefen($anfrage, new \DateTimeImmutable()));
        $this->assertAbgewiesen($anfrage, 401, 'nicht_authentifiziert');
    }

    public function testMalformedHeadersAreRefused(): void
    {
        [$id, $schluessel] = $this->koppeln();

        $this->assertAbgewiesen($this->signiert($id, $schluessel, self::PFAD, nonce: 'kurz'), 401, 'nicht_authentifiziert');
        $this->assertAbgewiesen($this->signiert($id, $schluessel, self::PFAD, header: [WorkerProtokoll::HEADER_AUTH => 'Bearer ' . $id]), 401, 'nicht_authentifiziert');
        $this->assertAbgewiesen(new Request(\App\Http\HttpMethod::Post, self::PFAD, rawBody: ''), 401, 'nicht_authentifiziert');
    }

    public function testAQueryStringIsRefusedBecauseItIsNotSigned(): void
    {
        [$id, $schluessel] = $this->koppeln();
        $anfrage = $this->signiert($id, $schluessel, self::PFAD);

        $this->assertAbgewiesen(new Request($anfrage->method, self::PFAD, query: ['max' => '5'], headers: $anfrage->headers, rawBody: ''), 400, 'query_nicht_erlaubt');
    }

    public function testAnUnpairedWorkerCannotSignAnymore(): void
    {
        [$id, $schluessel] = $this->koppeln();
        self::assertTrue($this->kopplung->entkoppeln($id, null, '', new \DateTimeImmutable()));

        $this->assertAbgewiesen($this->signiert($id, $schluessel, self::PFAD), 401, 'nicht_authentifiziert');
        self::assertNull($this->workers->find($id)?->secretEnc);
    }

    public function testAnUnknownWorkerIsRefused(): void
    {
        $this->assertAbgewiesen($this->signiert(999, random_bytes(32), self::PFAD), 401, 'nicht_authentifiziert');
    }

    public function testTooManyFailuresShutTheIpOut(): void
    {
        [$id, $schluessel] = $this->koppeln();
        for ($i = 0; $i < WorkerAuth::FEHLER_LIMIT_PRO_IP; ++$i) {
            $this->assertAbgewiesen($this->signiert($id, random_bytes(32), self::PFAD, ip: '192.0.2.9'), 401, 'nicht_authentifiziert');
        }

        // Even a correct signature from that address waits now ...
        $this->assertAbgewiesen($this->signiert($id, $schluessel, self::PFAD, ip: '192.0.2.9'), 429, 'zu_viele_fehlversuche');
        // ... while the worker from its own address is not locked out.
        self::assertInstanceOf(Worker::class, $this->auth->pruefen($this->signiert($id, $schluessel, self::PFAD), new \DateTimeImmutable()));
    }

    public function testRequestsPerWorkerAreLimited(): void
    {
        [$id, $schluessel] = $this->koppeln();
        $now = new \DateTimeImmutable();
        for ($i = 0; $i < WorkerAuth::ANFRAGEN_LIMIT_PRO_MINUTE; ++$i) {
            self::assertInstanceOf(Worker::class, $this->auth->pruefen($this->signiert($id, $schluessel, self::PFAD), $now));
        }

        $this->assertAbgewiesen($this->signiert($id, $schluessel, self::PFAD), 429, 'zu_viele_anfragen', $now);
    }

    public function testTheHandlerIsNotReachedWithoutAValidSignature(): void
    {
        [$id] = $this->koppeln();
        $erreicht = false;

        $antwort = $this->auth->ausfuehren($this->signiert($id, random_bytes(32), self::PFAD), [], static function () use (&$erreicht): Response {
            $erreicht = true;

            return Response::json([]);
        });

        self::assertFalse($erreicht);
        self::assertSame(401, $antwort instanceof Response ? $antwort->status : 0);
    }

    private function assertAbgewiesen(Request $anfrage, int $status, string $fehler, ?\DateTimeImmutable $now = null): void
    {
        $antwort = $this->auth->pruefen($anfrage, $now ?? new \DateTimeImmutable());

        self::assertInstanceOf(Response::class, $antwort);
        self::assertSame($status, $antwort->status);
        self::assertSame($fehler, self::daten($antwort)['fehler']);
    }
}
