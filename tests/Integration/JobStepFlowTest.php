<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Api\JobController;
use App\Config\Paths;
use App\Domain\BlobMeta;
use App\Domain\DocumentSource;
use App\Domain\Job;
use App\Domain\JobExecutor;
use App\Domain\JobStatus;
use App\Domain\Permission;
use App\Domain\SystemRole;
use App\Http\Cookie;
use App\Http\HttpMethod;
use App\Http\Kernel;
use App\Http\LoginGuard;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Http\Session;
use App\Http\StaticFileHandler;
use App\Repository\BlobRepository;
use App\Repository\DocumentRepository;
use App\Repository\JobRepository;
use App\Repository\RoleRepository;
use App\Repository\SubmissionRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\Service\Account\SessionVault;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Document\PdfErzeugung;
use App\Service\Job\JobAbgebrochen;
use App\Service\Job\JobHandler;
use App\Service\Job\JobRunner;
use App\Service\Job\JobSchrittErgebnis;
use App\Service\Migration\Migrator;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Service\Upload\MagicBytes;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FakeJpeg;
use App\View\View;

/**
 * `POST /api/jobs/step` end to end (issue #29/M4-7, docs/spec/06-betrieb.md
 * section 4): the real route table, guard, controller, JobRunner and
 * database, driving the real `pdf_erzeugen` handler plus a throwing test
 * handler that stands in for a job type crashing.
 */
final class JobStepFlowTest extends DatabaseTestCase
{
    private string $blobDir;
    private ServerCrypto $crypto;
    private RoleRepository $rollen;
    private Vault $tresor;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->blobDir = sys_get_temp_dir() . '/vb_jobstep_blobs_' . uniqid('', true);
        mkdir($this->blobDir, 0775, true);

        $this->crypto = new ServerCrypto((string) base64_decode(self::configData()['server_key'], true));
        $this->tresor = Vault::create();
        new VaultRepository($this->pdo())->insert($this->tresor->publicKey());

        $this->rollen = new RoleRepository($this->pdo());
        $this->userId = new UserRepository($this->pdo())->insert(
            $this->crypto->encrypt('finanzen@example.org'),
            random_bytes(32),
            $this->crypto->encrypt('Fritz Finanzen'),
            'hash',
            mfaRequired: false,
        );
        $this->alsRolle(SystemRole::Finanzen);

        new Session()->start();
        $jetzt = time();
        $_SESSION = ['user_id' => $this->userId, 'login_at' => $jetzt, 'last_seen_at' => $jetzt, 'session_epoch' => 0];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        self::removeDir($this->blobDir);
        parent::tearDown();
    }

    public function testOneCallRunsOneStepUntilTheJobIsFinished(): void
    {
        $id = $this->dokument([
            [FakeJpeg::bauen(20, 10), MagicBytes::JPEG],
            [FakeJpeg::bauen(10, 20), MagicBytes::JPEG],
        ]);
        $this->jobRepository()->enqueue(PdfErzeugung::JOB_TYP, JobExecutor::Session, 'document', $id);

        // Step 1: '' -> 'seite'. Still one job waiting - only its own step advanced.
        $erste = self::json($this->schritt());
        self::assertSame('gearbeitet', $erste['status']);
        self::assertSame(1, $erste['offen']);
        self::assertSame('seite', $this->job($id)?->step);
        self::assertSame(JobStatus::Offen, $this->job($id)?->status);

        // Step 2: 'seite' -> 'pdf' (both pages are JPEG, so one call collects both).
        $zweite = self::json($this->schritt());
        self::assertSame('gearbeitet', $zweite['status']);
        self::assertSame('pdf', $this->job($id)?->step);

        // Step 3: 'pdf' -> fertig.
        $dritte = self::json($this->schritt());
        self::assertSame('gearbeitet', $dritte['status']);
        self::assertSame(0, $dritte['offen'], 'the finished job no longer counts');
        self::assertSame(JobStatus::Fertig, $this->job($id)?->status);
        $documents = new DocumentRepository($this->pdo());
        self::assertNotNull($documents->find($id)?->pdfBlobId);

        // A fourth call finds nothing left to do.
        $vierte = self::json($this->schritt());
        self::assertSame('leer', $vierte['status']);
        self::assertSame(0, $vierte['offen']);
    }

    public function testWithoutTheRightNothingIsClaimed(): void
    {
        $id = $this->dokument([[FakeJpeg::bauen(10, 10), MagicBytes::JPEG]]);
        $this->jobRepository()->enqueue(PdfErzeugung::JOB_TYP, JobExecutor::Session, 'document', $id);
        $this->alsRolle(SystemRole::Kassenpruefer);

        $antwort = self::json($this->schritt());

        self::assertSame('leer', $antwort['status']);
        self::assertSame(0, $antwort['offen'], 'a right this account does not have must not even be counted');
        self::assertSame('', $this->job($id)?->step, 'the job was never touched');
    }

    public function testWithoutTheUnlockedVaultTheJobStaysUntouched(): void
    {
        $id = $this->dokument([[FakeJpeg::bauen(10, 10), MagicBytes::JPEG]]);
        $this->jobRepository()->enqueue(PdfErzeugung::JOB_TYP, JobExecutor::Session, 'document', $id);

        $antwort = self::json($this->schritt(entsperrt: false));

        self::assertSame('gesperrt', $antwort['status']);
        self::assertSame(1, $antwort['offen'], 'the header still counts what is waiting');
        self::assertSame(JobStatus::Offen, $this->job($id)?->status);
        self::assertNull($this->job($id)?->lockedBy);
    }

    public function testWithoutCsrfTheRequestIsRefused(): void
    {
        $antwort = $this->kernel()->handle(new Request(
            HttpMethod::Post,
            '/api/jobs/step',
            cookies: $this->tresorCookie(),
        ));

        self::assertInstanceOf(Response::class, $antwort);
        self::assertSame(403, $antwort->status);
    }

    /** Two open tabs: the second must not touch a job the first is already holding. */
    public function testAJobLockedByAnotherHolderIsLeftAlone(): void
    {
        $id = $this->dokument([[FakeJpeg::bauen(10, 10), MagicBytes::JPEG]]);
        $jobId = $this->jobRepository()->enqueue(PdfErzeugung::JOB_TYP, JobExecutor::Session, 'document', $id);
        $this->jobRepository()->claim(JobExecutor::Session, 'anderer-tab', 60, PdfErzeugung::JOB_TYP);

        $antwort = self::json($this->schritt());

        self::assertSame('leer', $antwort['status']);
        self::assertSame('anderer-tab', $this->jobRepository()->find($jobId)?->lockedBy);
    }

    public function testAThrowingHandlerFailsTheJobWithOnlyItsClassName(): void
    {
        $this->jobRepository()->enqueue('fehlschlaegt', JobExecutor::Session);

        $antwort = self::json($this->schritt(handler: [self::fehlschlagenderHandler()]));

        self::assertSame('gearbeitet', $antwort['status']);
        $zeile = $this->pdo()->query('SELECT status, last_error FROM job')->fetch();
        self::assertIsArray($zeile);
        self::assertSame(JobStatus::Fehler->value, $zeile['status']);
        self::assertSame(\RuntimeException::class, $zeile['last_error']);
        self::assertStringNotContainsString('DE89370400440532013000', (string) $zeile['last_error'], 'never the message');
    }

    /** A job crashing every request it touches is abandoned, not retried forever. */
    public function testAJobThatNeverFinishesAStepIsAbandonedAfterMaxVersuche(): void
    {
        $id = $this->jobRepository()->enqueue('fehlschlaegt', JobExecutor::Session);
        for ($i = 0; $i < 3; $i++) {
            $this->jobRepository()->claim(JobExecutor::Session, 'crash-' . $i, 60, 'fehlschlaegt');
            $this->jobRepository()->release($id);
        }
        self::assertSame(3, $this->job($id)?->attempts);

        $antwort = self::json($this->schritt(handler: [self::fehlschlagenderHandler()]));

        self::assertSame('gearbeitet', $antwort['status']);
        $job = $this->job($id);
        self::assertSame(JobStatus::Fehler, $job?->status);
        self::assertSame(JobAbgebrochen::class, $job?->lastError, 'the handler must not have been called at all');
    }

    // ----------------------------------------------------------- scaffolding

    /**
     * @param list<array{string, string}> $seiten bytes and MIME type
     */
    private function dokument(array $seiten): int
    {
        $documents = new DocumentRepository($this->pdo());
        $blobService = $this->blobService();

        $blobIds = [];
        foreach ($seiten as [$inhalt, $mime]) {
            $blobIds[] = $blobService->storeString($inhalt, new BlobMeta($mime), $this->tresor)->id;
        }

        return $documents->insert(
            DocumentSource::Einreichung,
            null,
            $blobIds,
            $this->tresor->sealDataKey(DataKey::generate()),
            new \DateTimeImmutable(),
        );
    }

    private function blobService(): BlobService
    {
        $repository = new BlobRepository($this->pdo());

        return new BlobService($repository, new DbBlobBackend($repository), new FsBlobBackend($this->blobDir));
    }

    private function jobRepository(): JobRepository
    {
        return new JobRepository($this->pdo());
    }

    private function job(int $id): ?Job
    {
        return $this->jobRepository()->find($id);
    }

    private function alsRolle(SystemRole $rolle): void
    {
        $id = $this->rollen->findSystem($rolle)?->id;
        assert($id !== null);
        $this->rollen->assignToUser($this->userId, [$id]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(Response $antwort): array
    {
        $daten = json_decode($antwort->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($daten);

        return $daten;
    }

    /**
     * A job type that always throws, standing in for a crashing job
     * (docs/spec/06-betrieb.md section 4). Its message carries something
     * that would never be allowed to leak into `job.last_error` - the test
     * proves it does not.
     */
    private static function fehlschlagenderHandler(): JobHandler
    {
        return new class implements JobHandler {
            public function typ(): string
            {
                return 'fehlschlaegt';
            }

            public function recht(): Permission
            {
                return Permission::DocumentEdit;
            }

            public function schritt(Job $job, Vault $vault, \DateTimeImmutable $now): JobSchrittErgebnis
            {
                throw new \RuntimeException('IBAN DE89370400440532013000 im Fehlertext.');
            }
        };
    }

    /**
     * @return array<string, string>
     */
    private function tresorCookie(): array
    {
        return [Cookie::vaultKey('x', Request::httpsFromGlobals())->name => new SessionVault()->store($this->tresor)];
    }

    /**
     * @param list<JobHandler> $handler defaults to the real pdf_erzeugen
     *        handler; a test that enqueues its own type passes its own list.
     */
    private function schritt(bool $entsperrt = true, array $handler = []): Response
    {
        $antwort = $this->kernel($handler)->handle(new Request(
            HttpMethod::Post,
            '/api/jobs/step',
            cookies: $entsperrt ? $this->tresorCookie() : [],
            post: ['_csrf' => new Session()->csrfToken()],
        ));
        self::assertInstanceOf(Response::class, $antwort);

        return $antwort;
    }

    /**
     * @param list<JobHandler> $handler
     */
    private function kernel(array $handler = []): Kernel
    {
        $paths = new Paths(dirname(__DIR__, 2));
        $view = new View($paths->viewsDir(), '0.0.0-test');
        $pdo = $this->pdo();

        if ($handler === []) {
            $blobs = new BlobRepository($pdo);
            $blobService = new BlobService($blobs, new DbBlobBackend($blobs), new FsBlobBackend($this->blobDir));
            $handler = [new PdfErzeugung(new DocumentRepository($pdo), $blobs, $blobService, new SubmissionRepository($pdo))];
        }

        $guard = fn(): LoginGuard => new LoginGuard(
            new Session(),
            $view,
            static fn(): SessionTimeouts => new SessionTimeouts(),
            function (int $id) use ($pdo): ?SessionUser {
                $user = new UserRepository($pdo)->findById($id);

                return $user === null
                    ? null
                    : new SessionUser($user, $this->crypto->decrypt($user->displayNameEnc), new UserAccessRepository($pdo)->berechtigungen($id));
            },
        );
        $jobs = fn(): JobController => new JobController(
            new Session(),
            new SessionVault(),
            $view,
            new JobRunner(new JobRepository($pdo), $handler),
        );
        $unerreichbar = static fn(): never => throw new \LogicException('Diese Route gehört nicht zu diesem Test.');

        $router = new Router();
        (require dirname(__DIR__, 2) . '/app/src/routes.php')(
            $router,
            $view,
            $unerreichbar,
            $guard,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $jobs,
        );

        return new Kernel(
            router: $router,
            staticFiles: new StaticFileHandler($paths->publicDir(), longCache: false),
            view: $view,
            debug: true,
        );
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }
}
