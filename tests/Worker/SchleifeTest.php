<?php

declare(strict_types=1);

namespace App\Tests\Worker;

use App\Service\Worker\WorkerKonfiguration;
use App\Service\Worker\WorkerProtokoll;
use App\Tests\Worker\Support\FakeWerkzeuge;
use App\Tests\Worker\Support\SkriptHttp;
use App\Worker\Abbruch;
use App\Worker\ApiClient;
use App\Worker\Arbeitsverzeichnis;
use App\Worker\Http\HttpFehler;
use App\Worker\Identitaet;
use App\Worker\KiKonfiguration;
use App\Worker\Log;
use App\Worker\Schleife;
use App\Worker\Schritt\SeitenRendern;
use PHPUnit\Framework\TestCase;

/**
 * The polling loop (docs/spec/07-worker.md section 5): outgoing polling
 * with backoff, nothing claimed before the admin confirmed the worker,
 * a job of an unknown type handed back for good.
 */
final class SchleifeTest extends TestCase
{
    private string $basis = '';

    private string $paar = '';

    private KiKonfiguration $ki;

    protected function setUp(): void
    {
        $this->basis = sys_get_temp_dir() . '/vb_schleife_' . bin2hex(random_bytes(6));
        mkdir($this->basis, 0700);
        $this->paar = sodium_crypto_box_keypair();
        $this->ki = new KiKonfiguration();
    }

    protected function tearDown(): void
    {
        @rmdir($this->basis);
    }

    public function testTheBackoffDoublesUpToFiveMinutes(): void
    {
        self::assertSame(5.0, Schleife::backoff(1));
        self::assertSame(10.0, Schleife::backoff(2));
        self::assertSame(40.0, Schleife::backoff(4));
        self::assertSame(300.0, Schleife::backoff(7));
        self::assertSame(300.0, Schleife::backoff(50));
        self::assertEqualsWithDelta(4.0, Schleife::backoff(1, 0.0), 0.0001);
        self::assertEqualsWithDelta(6.0, Schleife::backoff(1, 1.0), 0.0001);
    }

    public function testAnUnreachableWebHostIsPolledWithGrowingPausesUntilItAnswers(): void
    {
        $fehler = array_fill(0, ApiClient::VERSUCHE, new HttpFehler('Verbindungsfehler.'));
        $http = new SkriptHttp([...$fehler, ...$fehler, $this->herzschlag('aktiv', 0)]);
        $schleife = $this->schleife($http);

        $erste = $schleife->runde();
        $zweite = $schleife->runde();
        self::assertGreaterThanOrEqual(4.0, $erste);
        self::assertLessThanOrEqual(6.0, $erste);
        self::assertGreaterThanOrEqual(8.0, $zweite);
        self::assertLessThanOrEqual(12.0, $zweite);
        self::assertSame(2, $schleife->fehlerInFolge());

        self::assertSame(60.0, $schleife->runde());
        self::assertSame(0, $schleife->fehlerInFolge(), 'a good heartbeat resets the backoff');
    }

    public function testNothingIsClaimedBeforeTheAdminConfirmedTheWorker(): void
    {
        $http = new SkriptHttp([$this->herzschlag('gekoppelt', 3)]);

        self::assertSame(60.0, $this->schleife($http)->runde());

        self::assertCount(1, $http->anfragen);
        self::assertStringEndsWith('/api/worker/heartbeat', $http->anfragen[0]['url']);
        $body = json_decode($http->anfragen[0]['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(WorkerProtokoll::VERSION, $body['protokoll_version']);
        self::assertSame(['ocr', 'render', 'ai'], $body['faehigkeiten']);
        self::assertSame(0, $body['config_version']);
    }

    public function testAJobOfAnUnknownTypeIsHandedBackForGood(): void
    {
        $http = new SkriptHttp([
            $this->herzschlag('aktiv', 1),
            SkriptHttp::json(200, ['jobs' => [['id' => 5, 'typ' => 'image_cleanup', 'ref_type' => 'document', 'ref_id' => 2, 'step' => '', 'versuch' => 1, 'lock' => 'w1-abc', 'gesperrt_bis' => 0]]]),
            SkriptHttp::json(200, ['status' => 'fehler']),
        ]);

        self::assertSame(0.0, $this->schleife($http)->runde(), 'straight on after a job');

        self::assertStringEndsWith('/api/worker/jobs/5/fail', $http->anfragen[2]['url']);
        self::assertSame(['lock' => 'w1-abc', 'fehler' => 'JobTypNichtUnterstuetzt', 'endgueltig' => true], json_decode($http->anfragen[2]['body'], true, flags: JSON_THROW_ON_ERROR));
    }

    public function testAnOriginalWithoutGrantEndsTheJobForGoodWithoutAsking(): void
    {
        $http = new SkriptHttp([
            $this->herzschlag('aktiv', 1),
            SkriptHttp::json(200, ['jobs' => [['id' => 6, 'typ' => 'render_pages', 'versuch' => 1, 'lock' => 'w1-def']]]),
            SkriptHttp::json(200, [
                'dokument' => ['id' => 2, 'blobs' => [11], 'kontext' => null, 'originale' => [10, 11], 'verarbeitet' => [null, null], 'pdf' => null],
                'tresor' => ['version' => 1, 'public_key' => base64_encode(sodium_crypto_box_publickey(sodium_crypto_box_keypair()))],
                'artefakte' => ['page_image'],
                'stammdaten' => ['kategorien' => [], 'kostenstellen' => []],
            ]),
            SkriptHttp::json(200, ['status' => 'fehler']),
        ]);
        $schleife = $this->schleife($http, schritte: [new SeitenRendern(new FakeWerkzeuge())]);

        $schleife->runde();

        self::assertCount(4, $http->anfragen, 'no blob request for an original without grant');
        self::assertStringEndsWith('/api/worker/jobs/6/fail', $http->anfragen[3]['url']);
        self::assertSame(['lock' => 'w1-def', 'fehler' => 'KeinGrant', 'endgueltig' => true], json_decode($http->anfragen[3]['body'], true, flags: JSON_THROW_ON_ERROR));
    }

    public function testAnEmptyConfigurationFromTheHeartbeatDropsTheKeys(): void
    {
        $http = new SkriptHttp([
            $this->herzschlag('aktiv', 0, $this->config(1, [['id' => 1, 'name' => 'A', 'base_url' => 'https://ki.test/v1', 'model' => 'm', 'faehigkeiten' => ['vision' => true], 'timeout_s' => 30, 'standard' => true, 'fallback' => false, 'api_key' => 'sk']])),
            $this->herzschlag('aktiv', 0, $this->config(2, [])),
        ]);
        $schleife = $this->schleife($http);

        $schleife->runde();
        self::assertNotNull($this->ki->standard());
        $schleife->runde();

        self::assertNull($this->ki->standard());
        self::assertSame(1, json_decode($http->anfragen[1]['body'], true, flags: JSON_THROW_ON_ERROR)['config_version'], 'the worker tells which version it holds');
    }

    public function testAStopRequestEndsTheLoopWithoutAnotherRound(): void
    {
        $abbruch = new Abbruch();
        $abbruch->anfordern();
        $http = new SkriptHttp();

        $this->schleife($http, $abbruch)->laufen();

        self::assertSame([], $http->anfragen);
    }

    /** @param list<\App\Worker\Schritt\Schritt> $schritte */
    private function schleife(SkriptHttp $http, ?Abbruch $abbruch = null, array $schritte = []): Schleife
    {
        $abbruch ??= new Abbruch();
        $identitaet = new Identitaet(1, str_repeat('s', 32), $this->paar, 'https://belege.test', 'Pi');
        $uhr = static fn(): int => 1_800_000_000;
        $schlafen = static fn(float $s): bool => true;

        return new Schleife(
            new ApiClient($http, $identitaet, $abbruch, $uhr, $schlafen),
            $identitaet,
            $this->ki,
            new Arbeitsverzeichnis($this->basis, 'tmpfs ' . realpath($this->basis) . " tmpfs rw 0 0\n"),
            $schritte,
            ['ocr', 'render', 'ai'],
            '1.2.3',
            $abbruch,
            new Log(static function (string $zeile): void {
            }),
            $uhr,
            $schlafen,
        );
    }

    /** @param ?array<string, mixed> $config */
    private function herzschlag(string $status, int $wartend, ?array $config = null): \App\Worker\Http\HttpAntwort
    {
        return SkriptHttp::json(200, ['status' => $status, 'server_zeit' => 1_800_000_000, 'poll_sekunden' => 60, 'wartend' => $wartend, 'config' => $config]);
    }

    /**
     * @param list<array<string, mixed>> $profile
     *
     * @return array<string, mixed>
     */
    private function config(int $version, array $profile): array
    {
        $json = json_encode(['format' => WorkerKonfiguration::FORMAT, 'ki_anbieter' => $profile], JSON_THROW_ON_ERROR);

        return ['version' => $version, 'versiegelt' => base64_encode(sodium_crypto_box_seal($json, sodium_crypto_box_publickey($this->paar)))];
    }
}
