<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Api\WorkerApiController;
use App\Api\WorkerMailController;
use App\Repository\SubmissionRepository;
use App\Repository\SubmissionUploadRepository;
use App\Service\Submission\EinreichungsEinstellungen;
use App\Service\Submission\MailEinreichung;
use App\Service\Upload\UploadStore;
use App\Service\Submission\SubmissionUploadStore;
use App\Service\Worker\MailEingangGrenze;
use App\Service\Worker\MailEingangSpeicher;
use App\Domain\Worker;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Response;
use App\Repository\AiProviderRepository;
use App\Repository\AuditLogRepository;
use App\Repository\BlobRepository;
use App\Repository\CategoryRepository;
use App\Repository\CostCenterRepository;
use App\Repository\DocumentArtifactRepository;
use App\Repository\DocumentRepository;
use App\Repository\JobRepository;
use App\Repository\RateLimitRepository;
use App\Repository\SettingRepository;
use App\Repository\VaultRepository;
use App\Repository\WorkerGrantRepository;
use App\Repository\WorkerNonceRepository;
use App\Repository\WorkerRepository;
use App\Repository\WorkerUploadRepository;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Migration\Migrator;
use App\Service\RateLimiter;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Service\Upload\UploadService;
use App\Service\Worker\WorkerArtefakte;
use App\Service\Worker\WorkerAuth;
use App\Service\Worker\WorkerFreigaben;
use App\Service\Worker\WorkerKonfiguration;
use App\Service\Worker\WorkerKopplung;
use App\Service\Worker\WorkerFallback;
use App\Service\Worker\WorkerProtokoll;
use App\Service\Worker\WorkerRouting;
use App\Service\Worker\WorkerUebergabe;
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

    // Issue #52/M7b-2: grants, uploads, a vault and blob storage.
    protected string $tmp = '';
    protected Vault $vault;
    protected WorkerGrantRepository $grants;
    protected WorkerUploadRepository $uploads;
    protected WorkerArtefakte $artefakte;
    protected WorkerFreigaben $freigaben;
    protected BlobService $blobService;
    protected DocumentRepository $documents;
    protected DocumentArtifactRepository $artifacts;
    protected AiProviderRepository $anbieter;
    protected bool $rotation = false;

    // Issue #53/M7b-3: module switch, routing, handover, fallback.
    protected SettingRepository $settings;
    protected WorkerRouting $routing;
    protected WorkerUebergabe $uebergabe;
    protected WorkerFallback $fallback;

    // Issue #87: the mail intake.
    protected MailEingangSpeicher $mailEingang;
    protected WorkerMailController $mailApi;

    /** The key pair of the worker koppeln() paired last - what stays on the worker. */
    protected string $workerSchluesselpaar = '';

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->crypto = new ServerCrypto(str_repeat('t', ServerCrypto::KEY_BYTES));
        $this->workers = new WorkerRepository($this->pdo());
        $this->jobs = new JobRepository($this->pdo());

        $this->tmp = sys_get_temp_dir() . '/vb_worker_' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/blobs', 0775, true);
        $this->vault = Vault::create();
        $vaults = new VaultRepository($this->pdo());
        $vaults->insert($this->vault->publicKey(), $this->vault->version);
        $blobs = new BlobRepository($this->pdo());
        $this->blobService = new BlobService($blobs, new DbBlobBackend($blobs), new FsBlobBackend($this->tmp . '/blobs'));
        $this->documents = new DocumentRepository($this->pdo());
        $this->artifacts = new DocumentArtifactRepository($this->pdo());
        $this->grants = new WorkerGrantRepository($this->pdo());
        $this->uploads = new WorkerUploadRepository($this->pdo());
        $this->anbieter = new AiProviderRepository($this->pdo(), $this->crypto);
        $this->artefakte = new WorkerArtefakte(
            $this->artifacts,
            $this->uploads,
            new UploadService($this->tmp . '/upload', UploadService::CHUNK_BYTES, WorkerArtefakte::BLOB_MAX_BYTES),
            $this->blobService,
            $vaults,
            $this->jobs,
            new SettingRepository($this->pdo()),
        );
        $this->freigaben = new WorkerFreigaben(
            $this->workers,
            $this->grants,
            $this->uploads,
            $this->jobs,
            $blobs,
            $vaults,
            fn(): bool => $this->rotation,
            $this->artefakte,
        );
        $this->settings = new SettingRepository($this->pdo());
        // The module is on in these tests unless one switches it off.
        $this->settings->set(WorkerRouting::SETTING_AKTIV, '1');
        $this->routing = new WorkerRouting($this->workers, $this->settings);
        $this->mailEingang = new MailEingangSpeicher($this->settings, $this->crypto);
        $this->uebergabe = new WorkerUebergabe($this->routing, $this->freigaben, $this->jobs, $this->documents);
        $this->fallback = new WorkerFallback($this->routing, $this->workers, $this->jobs, $this->freigaben, $this->settings, $this->artifacts);
        $this->kopplung = new WorkerKopplung(
            $this->workers,
            $this->jobs,
            $this->crypto,
            new RateLimiter(new RateLimitRepository($this->pdo()), WorkerKopplung::FEHLER_FENSTER_SEKUNDEN),
            new AuditLog(new AuditLogRepository($this->pdo()), $vaults, $this->crypto),
            $this->freigaben,
        );
        $this->auth = new WorkerAuth(
            $this->workers,
            new WorkerNonceRepository($this->pdo()),
            $this->crypto,
            new RateLimiter(new RateLimitRepository($this->pdo()), WorkerAuth::FEHLER_FENSTER_SEKUNDEN),
            new RateLimiter(new RateLimitRepository($this->pdo()), WorkerAuth::ANFRAGEN_FENSTER_SEKUNDEN),
        );
        $this->api = new WorkerApiController(
            $this->kopplung,
            $this->workers,
            $this->jobs,
            $this->grants,
            $this->freigaben,
            $this->artefakte,
            new WorkerKonfiguration($this->workers, $this->anbieter, $this->crypto, $this->mailEingang, $this->settings),
            $this->blobService,
            $vaults,
            new CategoryRepository($this->pdo()),
            new CostCenterRepository($this->pdo()),
            $this->routing,
            $this->fallback,
            $this->documents,
            $this->mailEingang,
        );
        $einstellungen = EinreichungsEinstellungen::fromSettings($this->settings);
        $this->mailApi = new WorkerMailController(
            new MailEinreichung(
                $this->pdo(),
                new SubmissionRepository($this->pdo()),
                $this->documents,
                new SubmissionUploadRepository($this->pdo()),
                $blobs,
                $vaults,
                $einstellungen,
                $this->jobs,
                new AuditLog(new AuditLogRepository($this->pdo()), $vaults, $this->crypto),
            ),
            $this->mailEingang,
            $this->routing,
            new UploadService($this->tmp . '/upload'),
            new SubmissionUploadStore(new UploadStore($this->blobService, $vaults, $this->settings), new SubmissionUploadRepository($this->pdo())),
            $einstellungen,
            new MailEingangGrenze(new RateLimiter(new RateLimitRepository($this->pdo()), RateLimiter::SUBMISSION_WINDOW_SECONDS), $this->settings),
        );
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== '') {
            self::entferne($this->tmp);
        }
        parent::tearDown();
    }

    private static function entferne(string $pfad): void
    {
        if (is_dir($pfad) && !is_link($pfad)) {
            foreach (scandir($pfad) ?: [] as $eintrag) {
                if ($eintrag !== '.' && $eintrag !== '..') {
                    self::entferne($pfad . '/' . $eintrag);
                }
            }
            @rmdir($pfad);
        } elseif (file_exists($pfad) || is_link($pfad)) {
            @unlink($pfad);
        }
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
        $this->workerSchluesselpaar = sodium_crypto_box_keypair();
        $ergebnis = $this->kopplung->koppeln($code, base64_encode(sodium_crypto_box_publickey($this->workerSchluesselpaar)), 'Pi', WorkerProtokoll::VERSION, '1.0.0', '203.0.113.5', $now);
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
