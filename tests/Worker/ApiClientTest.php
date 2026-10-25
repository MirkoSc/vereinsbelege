<?php

declare(strict_types=1);

namespace App\Tests\Worker;

use App\Service\Worker\WorkerProtokoll;
use App\Tests\Worker\Support\SkriptHttp;
use App\Worker\Abbruch;
use App\Worker\Abgebrochen;
use App\Worker\ApiClient;
use App\Worker\ApiFehler;
use App\Worker\Http\HttpAntwort;
use App\Worker\Http\HttpFehler;
use App\Worker\Identitaet;
use PHPUnit\Framework\TestCase;

/**
 * The worker's signed client (docs/spec/07-worker.md sections 4 and 5).
 * Above all: every attempt of a request - also the repetition after a
 * timeout or a network error - is signed with a NEW nonce and the current
 * time (review of #52/#53).
 */
final class ApiClientTest extends TestCase
{
    private const string SCHLUESSEL = 'abcdefghijklmnopqrstuvwxyz012345';

    private int $zeit = 1_800_000_000;

    /** @var list<float> */
    private array $pausen = [];

    public function testEveryRetryGetsANewNonceAndTimestampAndAValidSignature(): void
    {
        $http = new SkriptHttp([
            new HttpFehler('Zeitüberschreitung.', zeitueberschreitung: true),
            SkriptHttp::json(503, ['fehler' => 'wartung']),
            SkriptHttp::json(200, ['status' => 'aktiv']),
        ]);
        $api = $this->client($http);

        self::assertSame(['status' => 'aktiv'], $api->json('POST', '/heartbeat', ['config_version' => 0]));

        self::assertCount(3, $http->anfragen);
        $nonces = array_map(static fn(array $a): string => $a['header']['x-worker-nonce'], $http->anfragen);
        self::assertCount(3, array_unique($nonces), 'a nonce is never reused');
        $zeiten = array_map(static fn(array $a): int => (int) $a['header']['x-worker-timestamp'], $http->anfragen);
        self::assertSame(array_values(array_unique($zeiten)), $zeiten, 'each attempt carries the time it was sent');
        foreach ($http->anfragen as $anfrage) {
            self::assertSame('https://belege.test/api/worker/heartbeat', $anfrage['url']);
            self::assertSame('Worker 7', $anfrage['header']['authorization']);
            self::assertSame('Worker 7', $anfrage['header']['x-worker-authorization']);
            self::assertSame(
                WorkerProtokoll::signatur(self::SCHLUESSEL, 'POST', '/api/worker/heartbeat', (int) $anfrage['header']['x-worker-timestamp'], $anfrage['header']['x-worker-nonce'], $anfrage['body']),
                $anfrage['header']['x-worker-signature'],
            );
        }
        self::assertSame([2.0, 4.0], $this->pausen, 'growing pauses between the attempts');
    }

    public function testAClientErrorIsNotRetried(): void
    {
        $http = new SkriptHttp([SkriptHttp::json(409, ['fehler' => 'sperre_verloren', 'meldung' => 'Weg.'])]);

        try {
            $this->client($http)->json('POST', '/jobs/1/complete', ['lock' => 'x']);
            self::fail('expected an ApiFehler');
        } catch (ApiFehler $e) {
            self::assertSame(409, $e->status);
            self::assertSame('sperre_verloren', $e->fehler);
            self::assertTrue($e->jobVerloren());
        }
        self::assertCount(1, $http->anfragen);
    }

    public function testItGivesUpAfterFourAttempts(): void
    {
        $http = new SkriptHttp(array_fill(0, 4, new HttpFehler('Verbindungsfehler.')));

        try {
            $this->client($http)->json('POST', '/heartbeat', []);
            self::fail('expected an HttpFehler');
        } catch (HttpFehler) {
        }
        self::assertCount(ApiClient::VERSUCHE, $http->anfragen);
    }

    public function testAClockThatIsOffIsCorrectedFromTheDateHeaderOnce(): void
    {
        $server = $this->zeit + 3600;
        $http = new SkriptHttp([
            new HttpAntwort(401, ['date' => gmdate('D, d M Y H:i:s \G\M\T', $server)], '{"fehler":"nicht_authentifiziert"}'),
            SkriptHttp::json(200, ['ok' => true]),
        ]);
        $api = $this->client($http, festeUhr: true);

        $api->json('POST', '/heartbeat', []);

        self::assertSame(3600, $api->versatz());
        self::assertSame($server, (int) $http->anfragen[1]['header']['x-worker-timestamp']);
        self::assertNotSame($http->anfragen[0]['header']['x-worker-nonce'], $http->anfragen[1]['header']['x-worker-nonce']);
    }

    public function testTheHeartbeatsServerTimeSetsTheOffset(): void
    {
        $http = new SkriptHttp([SkriptHttp::json(200, [])]);
        $api = $this->client($http, festeUhr: true);
        $api->serverZeit($this->zeit - 90, $this->zeit);

        $api->json('POST', '/heartbeat', []);

        self::assertSame($this->zeit - 90, (int) $http->anfragen[0]['header']['x-worker-timestamp']);
    }

    public function testAPathPrefixOfTheBaseAddressIsSignedAsSent(): void
    {
        $http = new SkriptHttp([SkriptHttp::json(200, [])]);
        $api = new ApiClient($http, $this->identitaet('https://verein.de/belege'), new Abbruch(), fn(): int => $this->zeit, fn(float $s): bool => true);

        $api->json('GET', '/jobs/3/context');

        $anfrage = $http->anfragen[0];
        self::assertSame('https://verein.de/belege/api/worker/jobs/3/context', $anfrage['url']);
        self::assertSame(
            WorkerProtokoll::signatur(self::SCHLUESSEL, 'GET', '/belege/api/worker/jobs/3/context', (int) $anfrage['header']['x-worker-timestamp'], $anfrage['header']['x-worker-nonce'], ''),
            $anfrage['header']['x-worker-signature'],
        );
    }

    public function testAPlainHttpAddressIsRefusedEvenWhenStored(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ApiClient(new SkriptHttp(), $this->identitaet('http://belege.test'), new Abbruch(), fn(): int => $this->zeit, fn(float $s): bool => true);
    }

    public function testLocalHttpOnlyWithTheTestOption(): void
    {
        $http = new SkriptHttp([SkriptHttp::json(200, [])]);
        $api = new ApiClient($http, $this->identitaet('http://localhost:8080'), new Abbruch(), fn(): int => $this->zeit, fn(float $s): bool => true, httpLocalhostErlaubt: true);

        $api->json('POST', '/heartbeat', []);

        self::assertSame('http://localhost:8080/api/worker/heartbeat', $http->anfragen[0]['url']);
    }

    public function testAStopRequestEndsTheRetries(): void
    {
        $abbruch = new Abbruch();
        $http = new SkriptHttp([
            static function () use ($abbruch): HttpFehler {
                $abbruch->anfordern();

                return new HttpFehler('Verbindungsfehler.');
            },
        ]);
        $api = new ApiClient($http, $this->identitaet(), $abbruch, fn(): int => $this->zeit, fn(float $s): bool => !$abbruch->angefordert());

        $this->expectException(Abgebrochen::class);
        try {
            $api->json('POST', '/heartbeat', []);
        } finally {
            self::assertCount(1, $http->anfragen);
        }
    }

    public function testAnErrorAnswerCarriesTheCode(): void
    {
        $http = new SkriptHttp([SkriptHttp::json(403, ['fehler' => 'kein_grant', 'meldung' => 'Kein Zugriff auf diese Datei.'])]);

        $this->expectException(ApiFehler::class);
        $this->expectExceptionMessage('Kein Zugriff auf diese Datei.');

        $this->client($http)->datei('/blobs/5');
    }

    private function client(SkriptHttp $http, bool $festeUhr = false): ApiClient
    {
        return new ApiClient(
            $http,
            $this->identitaet(),
            new Abbruch(),
            function () use ($festeUhr): int {
                return $festeUhr ? $this->zeit : $this->zeit++;
            },
            function (float $sekunden): bool {
                $this->pausen[] = $sekunden;

                return true;
            },
        );
    }

    private function identitaet(string $url = 'https://belege.test'): Identitaet
    {
        return new Identitaet(7, self::SCHLUESSEL, sodium_crypto_box_keypair(), $url, 'Pi');
    }
}
