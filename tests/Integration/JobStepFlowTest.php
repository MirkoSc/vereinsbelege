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
use App\Service\Job\ExternerAufruf;
use App\Service\Job\ExternerJobHandler;
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
 *
 * Since issue #47/M7-5 also the three-phase step of a handler that waits on
 * an external call (App\Service\Job\ExternerJobHandler): the connection is
 * released before the call and the step finishes on a fresh one
 * (06-betrieb.md section 4), and a waiting step (JobSchrittErgebnis::
 * warten()) is not claimed again before its time.
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
        $id = $this->dokument(
            [[FakeJpeg::bauen(20, 10), MagicBytes::JPEG], [FakeJpeg::bauen(10, 20), MagicBytes::JPEG]],
            [FakeJpeg::bauen(20, 10), FakeJpeg::bauen(10, 20)],
        );
        $this->jobRepository()->enqueue(PdfErzeugung::JOB_TYP, JobExecutor::Session, 'document', $id);

        // Step 1: '' -> 'seite'. Still one job waiting - only its own step advanced.
        $erste = self::json($this->schritt());
        self::assertSame('gearbeitet', $erste['status']);
        self::assertSame(1, $erste['offen']);
        self::assertSame('seite', $this->job($id)?->step);
        self::assertSame(JobStatus::Offen, $this->job($id)?->status);

        // Step 2: 'seite' -> 'pdf' (both pages come with the scanner's JPEG,
        // so one call collects both).
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

    // ------------------------------------------------- external calls (M7-5)

    public function testAStepWaitingOnAnExternalCallReleasesTheConnectionFirstAndFinishesAfterwards(): void
    {
        $jobId = $this->jobRepository()->enqueue('extern', JobExecutor::Session);
        $ablauf = [];
        $sperreWaehrendDesAufrufs = null;
        $sessionWaehrendDesAufrufs = null;

        $handler = self::externerHandler(
            aufruf: function (Job $job) use (&$ablauf, &$sperreWaehrendDesAufrufs, &$sessionWaehrendDesAufrufs, $jobId): ExternerAufruf {
                return new ExternerAufruf(
                    function () use (&$ablauf, &$sperreWaehrendDesAufrufs, &$sessionWaehrendDesAufrufs, $jobId): string {
                        $ablauf[] = 'aufruf';
                        $sperreWaehrendDesAufrufs = $this->job($jobId)?->lockedUntil;
                        $sessionWaehrendDesAufrufs = session_status();

                        return 'antwort';
                    },
                    90,
                );
            },
            nachAufruf: function (Job $job, mixed $ergebnis) use (&$ablauf): JobSchrittErgebnis {
                $ablauf[] = 'nach:' . $ergebnis;

                return JobSchrittErgebnis::fertig();
            },
        );
        $vorher = new \DateTimeImmutable();

        $antwort = self::json($this->schritt(handler: [$handler], freigeben: function () use (&$ablauf): void {
            $ablauf[] = 'freigegeben';
        }));

        self::assertSame('gearbeitet', $antwort['status']);
        self::assertSame(0, $antwort['offen']);
        self::assertSame(['freigegeben', 'aufruf', 'nach:antwort'], $ablauf, 'connection released before the call, result stored after it');
        self::assertSame(JobStatus::Fertig, $this->job($jobId)?->status);
        self::assertNotNull($sperreWaehrendDesAufrufs);
        self::assertGreaterThanOrEqual(
            $vorher->modify('+149 seconds')->getTimestamp(),
            $sperreWaehrendDesAufrufs->getTimestamp(),
            'the lock covers the call\'s own limit plus room to store the result',
        );
        self::assertNotSame(PHP_SESSION_ACTIVE, $sessionWaehrendDesAufrufs, 'the session lock is free while the call runs');
    }

    public function testAHandlerWithoutAnExternalCallInThisStepRunsItsPlainStep(): void
    {
        $jobId = $this->jobRepository()->enqueue('extern', JobExecutor::Session);
        $freigegeben = false;

        $handler = self::externerHandler(
            aufruf: static fn(Job $job): ?ExternerAufruf => null,
            nachAufruf: static fn(Job $job, mixed $ergebnis): never => throw new \LogicException('No call, no nachAufruf().'),
            schritt: static fn(Job $job): JobSchrittErgebnis => JobSchrittErgebnis::weiter('zwei', ['n' => 1]),
        );

        self::json($this->schritt(handler: [$handler], freigeben: function () use (&$freigegeben): void {
            $freigegeben = true;
        }));

        self::assertFalse($freigegeben, 'nothing external, nothing released');
        self::assertSame('zwei', $this->job($jobId)?->step);
        self::assertSame(['n' => 1], $this->job($jobId)?->state);
    }

    public function testAThrowingExternalCallFailsTheJobWithOnlyItsClassName(): void
    {
        $jobId = $this->jobRepository()->enqueue('extern', JobExecutor::Session);
        $handler = self::externerHandler(
            aufruf: static fn(Job $job): ExternerAufruf => new ExternerAufruf(
                static fn(): never => throw new \RuntimeException('IBAN DE89370400440532013000 im Fehlertext.'),
                30,
            ),
            nachAufruf: static fn(Job $job, mixed $ergebnis): never => throw new \LogicException('Never reached.'),
        );

        self::json($this->schritt(handler: [$handler]));

        $job = $this->job($jobId);
        self::assertSame(JobStatus::Fehler, $job?->status);
        self::assertSame(\RuntimeException::class, $job?->lastError);
    }

    /**
     * A call that outlived its lock: the job expired, another tab claimed
     * it - the late result must not overwrite what that tab is doing.
     */
    public function testAResultArrivingAfterTheLockWasLostIsDropped(): void
    {
        $jobId = $this->jobRepository()->enqueue('extern', JobExecutor::Session);
        $nachAufrufGerufen = false;
        $handler = self::externerHandler(
            aufruf: fn(Job $job): ExternerAufruf => new ExternerAufruf(function () use ($jobId): string {
                $this->pdo()->prepare("UPDATE job SET locked_by = 'anderer-tab' WHERE id = ?")->execute([$jobId]);

                return 'zu spät';
            }, 30),
            nachAufruf: function (Job $job, mixed $ergebnis) use (&$nachAufrufGerufen): JobSchrittErgebnis {
                $nachAufrufGerufen = true;

                return JobSchrittErgebnis::fertig();
            },
        );

        self::json($this->schritt(handler: [$handler]));

        self::assertFalse($nachAufrufGerufen);
        $job = $this->job($jobId);
        self::assertSame(JobStatus::Laeuft, $job?->status);
        self::assertSame('anderer-tab', $job?->lockedBy);
    }

    public function testAWaitingJobIsNotClaimedAgainBeforeItsTime(): void
    {
        $jobId = $this->jobRepository()->enqueue('extern', JobExecutor::Session);
        $aufrufe = 0;
        $handler = self::externerHandler(
            aufruf: static fn(Job $job): ?ExternerAufruf => null,
            nachAufruf: static fn(Job $job, mixed $ergebnis): never => throw new \LogicException('Never reached.'),
            schritt: function (Job $job) use (&$aufrufe): JobSchrittErgebnis {
                $aufrufe++;

                return JobSchrittErgebnis::warten('', [], 30);
            },
        );
        $vorher = new \DateTimeImmutable();

        $erste = self::json($this->schritt(handler: [$handler]));
        $zweite = self::json($this->schritt(handler: [$handler]));

        self::assertSame('gearbeitet', $erste['status']);
        self::assertSame('leer', $zweite['status'], 'parked until its time');
        self::assertSame(1, $zweite['offen'], 'still counts as waiting');
        self::assertSame(1, $aufrufe);
        $job = $this->job($jobId);
        self::assertSame(JobStatus::Offen, $job?->status);
        self::assertNull($job?->lockedBy);
        self::assertSame(0, $job?->attempts, 'waiting is progress, not a crash');
        self::assertGreaterThanOrEqual($vorher->modify('+29 seconds')->getTimestamp(), $job?->lockedUntil?->getTimestamp());
    }

    // ----------------------------------------------------------- scaffolding

    /**
     * @param list<array{string, string}> $seiten bytes and MIME type
     */
    /**
     * @param list<string> $aufbereitet the scanner's processed JPEG per page
     *        (issue #34/M5-4); empty for none
     */
    private function dokument(array $seiten, array $aufbereitet = []): int
    {
        $documents = new DocumentRepository($this->pdo());
        $blobService = $this->blobService();

        $blobIds = [];
        foreach ($seiten as [$inhalt, $mime]) {
            $blobIds[] = $blobService->storeString($inhalt, new BlobMeta($mime), $this->tresor)->id;
        }
        $aufbereitetIds = [];
        foreach ($aufbereitet as $inhalt) {
            $aufbereitetIds[] = $blobService->storeString($inhalt, new BlobMeta(MagicBytes::JPEG), $this->tresor)->id;
        }

        return $documents->insert(
            DocumentSource::Einreichung,
            null,
            $blobIds,
            $this->tresor->sealDataKey(DataKey::generate()),
            new \DateTimeImmutable(),
            processedBlobIds: $aufbereitetIds,
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
     * A job type `extern` whose three hooks are the given closures (issue
     * #47/M7-5): aufruf() and nachAufruf() of App\Service\Job\
     * ExternerJobHandler, schritt() for a step without external call.
     *
     * @param \Closure(Job): ?ExternerAufruf $aufruf
     * @param \Closure(Job, mixed): JobSchrittErgebnis $nachAufruf
     * @param ?\Closure(Job): JobSchrittErgebnis $schritt
     */
    private static function externerHandler(\Closure $aufruf, \Closure $nachAufruf, ?\Closure $schritt = null): ExternerJobHandler
    {
        return new readonly class ($aufruf, $nachAufruf, $schritt) implements ExternerJobHandler {
            public function __construct(
                private \Closure $aufrufFn,
                private \Closure $nachAufrufFn,
                private ?\Closure $schrittFn,
            ) {
            }

            public function typ(): string
            {
                return 'extern';
            }

            public function recht(): Permission
            {
                return Permission::DocumentEdit;
            }

            public function aufruf(Job $job, Vault $vault, \DateTimeImmutable $now): ?ExternerAufruf
            {
                return ($this->aufrufFn)($job);
            }

            public function nachAufruf(Job $job, Vault $vault, mixed $ergebnis, \DateTimeImmutable $now): JobSchrittErgebnis
            {
                return ($this->nachAufrufFn)($job, $ergebnis);
            }

            public function schritt(Job $job, Vault $vault, \DateTimeImmutable $now): JobSchrittErgebnis
            {
                return ($this->schrittFn ?? static fn(): never => throw new \LogicException('No plain step.'))($job);
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
     * @param ?\Closure(): void $freigeben stands in for
     *        ConnectionFactory::release() - the test keeps its one connection
     */
    private function schritt(bool $entsperrt = true, array $handler = [], ?\Closure $freigeben = null): Response
    {
        // JobController closes the session after each step (issue #47/
        // M7-5); open it again before tresorCookie() writes the vault key
        // into it, or the controller's start() would load the saved state
        // over it.
        new Session()->start();
        $antwort = $this->kernel($handler, $freigeben)->handle(new Request(
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
     * @param ?\Closure(): void $freigeben
     */
    private function kernel(array $handler = [], ?\Closure $freigeben = null): Kernel
    {
        $paths = new Paths(dirname(__DIR__, 2));
        $view = new View($paths->viewsDir(), '0.0.0-test');
        $pdo = $this->pdo();

        if ($handler === []) {
            $blobs = new BlobRepository($pdo);
            $blobService = new BlobService($blobs, new DbBlobBackend($blobs), new FsBlobBackend($this->blobDir));
            $handler = [new PdfErzeugung(new DocumentRepository($pdo), $blobs, $blobService, new SubmissionRepository($pdo), new JobRepository($pdo))];
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
            static fn(): JobRunner => new JobRunner(new JobRepository($pdo), $handler),
            $freigeben ?? static function (): void {
            },
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
