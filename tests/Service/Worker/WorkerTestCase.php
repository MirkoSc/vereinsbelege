<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Api\WorkerApiController;
use App\Domain\Worker;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Response;
use App\Repository\AuditLogRepository;
use App\Repository\JobRepository;
use App\Repository\RateLimitRepository;
use App\Repository\VaultRepository;
use App\Repository\WorkerNonceRepository;
use App\Repository\WorkerRepository;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\ServerCrypto;
use App\Service\Migration\Migrator;
use App\Service\RateLimiter;
use App\Service\Worker\WorkerAuth;
use App\Service\Worker\WorkerKopplung;
use App\Service\Worker\WorkerProtokoll;
use App\Tests\Support\DatabaseTestCase;

/**
 * Shared wiring for the worker tests (issue #51/M7b-1): the real
 * repositories, server key, rate limiters and audit log - the same pieces
 * app/src/bootstrap.php puts together.
 */
abstract class WorkerTestCase extends DatabaseTestCase
{
    protected ServerCrypto $crypto;
    protected WorkerRepository $workers;
    protected JobRepository $jobs;
    protected WorkerKopplung $kopplung;
    protected WorkerAuth $auth;
    protected WorkerApiController $api;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->crypto = new ServerCrypto(str_repeat('t', ServerCrypto::KEY_BYTES));
        $this->workers = new WorkerRepository($this->pdo());
        $this->jobs = new JobRepository($this->pdo());
        $this->kopplung = new WorkerKopplung(
            $this->workers,
            $this->jobs,
            $this->crypto,
            new RateLimiter(new RateLimitRepository($this->pdo()), WorkerKopplung::FEHLER_FENSTER_SEKUNDEN),
            new AuditLog(new AuditLogRepository($this->pdo()), new VaultRepository($this->pdo()), $this->crypto),
        );
        $this->auth = new WorkerAuth(
            $this->workers,
            new WorkerNonceRepository($this->pdo()),
            $this->crypto,
            new RateLimiter(new RateLimitRepository($this->pdo()), WorkerAuth::FEHLER_FENSTER_SEKUNDEN),
            new RateLimiter(new RateLimitRepository($this->pdo()), WorkerAuth::ANFRAGEN_FENSTER_SEKUNDEN),
        );
        $this->api = new WorkerApiController($this->kopplung, $this->workers, $this->jobs);
    }

    /**
     * Pairs a worker the way the API does and returns id and key.
     *
     * @return array{int, string}
     */
    protected function koppeln(bool $bestaetigen = true): array
    {
        $now = new \DateTimeImmutable();
        $code = $this->kopplung->codeErzeugen(null, '', $now);
        self::assertNotNull($code);
        $ergebnis = $this->kopplung->koppeln($code, base64_encode(sodium_crypto_box_publickey(sodium_crypto_box_keypair())), 'Pi', WorkerProtokoll::VERSION, '1.0.0', '203.0.113.5', $now);
        self::assertTrue($ergebnis->istGekoppelt());
        if ($bestaetigen) {
            self::assertTrue($this->kopplung->bestaetigen((int) $ergebnis->workerId, null, '', $now));
        }

        return [(int) $ergebnis->workerId, (string) $ergebnis->schluessel];
    }

    /**
     * @param array<string, string> $header extra or overriding headers
     */
    protected function signiert(
        int $workerId,
        string $schluessel,
        string $pfad,
        string $body = '',
        ?int $zeit = null,
        ?string $nonce = null,
        array $header = [],
        HttpMethod $methode = HttpMethod::Post,
        string $ip = '198.51.100.7',
    ): Request {
        $kopf = WorkerProtokoll::header(
            $workerId,
            $schluessel,
            $methode->value,
            $pfad,
            $body,
            $zeit ?? time(),
            $nonce ?? WorkerProtokoll::neueNonce(),
        );

        return new Request($methode, $pfad, headers: array_merge($kopf, $header), ip: $ip, rawBody: $body);
    }

    /**
     * Runs a request through the signature check into a handler, like the
     * route table does.
     *
     * @param \Closure(Request, array<string, string>, Worker): Response $handler
     * @param array<string, string> $params
     */
    protected function aufruf(Request $request, \Closure $handler, array $params = []): Response
    {
        $antwort = $this->auth->ausfuehren($request, $params, $handler);
        self::assertInstanceOf(Response::class, $antwort);

        return $antwort;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function daten(Response $antwort): array
    {
        $daten = json_decode($antwort->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($daten);

        return $daten;
    }
}
