<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Api\RasterungController;
use App\Config\Paths;
use App\Domain\ArtifactKind;
use App\Domain\Blob;
use App\Domain\BlobMeta;
use App\Domain\DocumentSource;
use App\Domain\Job;
use App\Domain\JobExecutor;
use App\Domain\JobStatus;
use App\Domain\SystemRole;
use App\Http\Cookie;
use App\Http\HttpMethod;
use App\Http\Kernel;
use App\Http\LoginGuard;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Router;
use App\Http\Session;
use App\Http\StaticFileHandler;
use App\Http\StreamResponse;
use App\Repository\BlobRepository;
use App\Repository\DocumentArtifactRepository;
use App\Repository\DocumentRepository;
use App\Repository\JobRepository;
use App\Repository\RoleRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\Service\Account\SessionVault;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Document\PdfPasswortGeschuetzt;
use App\Service\Document\PdfRasterung;
use App\Service\Document\PdfRasterungFehlgeschlagen;
use App\Service\Job\JobRunner;
use App\Service\Migration\Migrator;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Service\Upload\MagicBytes;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FakeJpeg;
use App\View\View;

/**
 * The `render_pages` browser job's four routes end to end (issue #30/M4-8,
 * docs/spec/06-betrieb.md section 4): the real route table, guard,
 * controller and database, the same shape as JobStepFlowTest for the
 * session job's single route.
 */
final class RasterungFlowTest extends DatabaseTestCase
{
    private const string PDF_INHALT = 'pdf-bytes-quelle-a';

    private string $blobDir;
    private string $body = '';
    private ServerCrypto $crypto;
    private RoleRepository $rollen;
    private Vault $tresor;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->blobDir = sys_get_temp_dir() . '/vb_rasterung_blobs_' . uniqid('', true);
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

    public function testFullFlowThroughTwoPagesOfOneSource(): void
    {
        [$documentId] = $this->dokumentMitPdfs([self::PDF_INHALT]);
        $jobId = $this->enqueue($documentId);

        $aufgabe = self::json($this->naechste());
        self::assertSame('aufgabe', $aufgabe['status']);
        self::assertSame($jobId, $aufgabe['job']);
        self::assertSame(0, $aufgabe['quelle']);
        self::assertSame(1, $aufgabe['seite']);
        self::assertSame(1, $aufgabe['quellen']);
        $lock = $aufgabe['lock'];
        self::assertIsString($lock);
        self::assertNotSame('', $lock);

        $quelle = $this->quelle($jobId, $lock, 0);
        self::assertSame(200, $quelle->status);
        self::assertSame(self::PDF_INHALT, $quelle->body);
        self::assertSame('application/pdf', $quelle->headers['Content-Type']);

        $seiteEins = FakeJpeg::bauen(100, 80);
        $ersteAntwort = self::json($this->seite($jobId, $lock, 0, 1, 2, $seiteEins));
        self::assertSame('ok', $ersteAntwort['status']);
        self::assertSame(JobStatus::Laeuft, $this->job($jobId)?->status, 'the job stays claimed across pages');

        $seiteZwei = FakeJpeg::bauen(80, 100);
        $zweiteAntwort = self::json($this->seite($jobId, $lock, 0, 2, 2, $seiteZwei));
        self::assertSame('fertig', $zweiteAntwort['status']);
        self::assertSame(JobStatus::Fertig, $this->job($jobId)?->status);

        $seiten = new DocumentArtifactRepository($this->pdo())->fuerDokument($documentId, ArtifactKind::PageImage);
        self::assertCount(2, $seiten);
        self::assertSame(JobExecutor::Browser, $seiten[0]->producer);
        self::assertSame($jobId, $seiten[0]->jobId);
        $blobs = new BlobRepository($this->pdo());
        self::assertSame($seiteEins, $this->lesen($blobs->find($seiten[0]->blobId)));
        self::assertSame($seiteZwei, $this->lesen($blobs->find($seiten[1]->blobId)));

        // Nothing left to claim.
        $leer = self::json($this->naechste());
        self::assertSame('leer', $leer['status']);
        self::assertSame(0, $leer['offen']);

        self::assertOnlyIntegers($this->job($jobId)?->state ?? []);
    }

    public function testMultipleSourcesAreRenderedInOrder(): void
    {
        [$documentId] = $this->dokumentMitPdfs(['pdf-a', 'pdf-b']);
        $jobId = $this->enqueue($documentId);

        $ersteAufgabe = self::json($this->naechste());
        self::assertSame(0, $ersteAufgabe['quelle']);
        self::assertSame(2, $ersteAufgabe['quellen']);
        $lock = $ersteAufgabe['lock'];

        self::assertSame('pdf-a', $this->quelle($jobId, $lock, 0)->body);
        $antwort = self::json($this->seite($jobId, $lock, 0, 1, 1, FakeJpeg::bauen(10, 10)));
        self::assertSame('ok', $antwort['status'], 'one more source remains');
        // The server names where to continue - the same lock stays claimed
        // (App\Repository\JobRepository::fortschritt()), so a fresh
        // naechste() call would find nothing reclaimable.
        self::assertSame(1, $antwort['quelle']);
        self::assertSame(1, $antwort['seite']);
        self::assertSame(JobStatus::Laeuft, $this->job($jobId)?->status);
        self::assertSame($lock, $this->job($jobId)?->lockedBy, 'the same lock, not a new claim');

        self::assertSame('pdf-b', $this->quelle($jobId, $lock, 1)->body);
        $letzteAntwort = self::json($this->seite($jobId, $lock, 1, 1, 1, FakeJpeg::bauen(10, 10)));
        self::assertSame('fertig', $letzteAntwort['status']);

        $seiten = new DocumentArtifactRepository($this->pdo())->fuerDokument($documentId, ArtifactKind::PageImage);
        self::assertCount(2, $seiten);
        self::assertSame([0, 1], [$seiten[0]->seq, $seiten[1]->seq]);
    }

    public function testRetryingTheJustStoredPageIsIdempotent(): void
    {
        [$documentId] = $this->dokumentMitPdfs([self::PDF_INHALT]);
        $jobId = $this->enqueue($documentId);
        $aufgabe = self::json($this->naechste());
        $lock = $aufgabe['lock'];

        $seite = FakeJpeg::bauen(10, 10);
        self::json($this->seite($jobId, $lock, 0, 1, 1, $seite));
        self::assertSame(JobStatus::Fertig, $this->job($jobId)?->status);

        // The network drops the first response; the browser retries the
        // exact same upload against a job that is now already finished.
        $wiederholung = self::json($this->seite($jobId, $lock, 0, 1, 1, $seite));
        self::assertSame('fertig', $wiederholung['status']);

        self::assertCount(1, new DocumentArtifactRepository($this->pdo())->fuerDokument($documentId, ArtifactKind::PageImage));
    }

    public function testARetryMidJobIsIdempotentToo(): void
    {
        [$documentId] = $this->dokumentMitPdfs([self::PDF_INHALT]);
        $jobId = $this->enqueue($documentId);
        $aufgabe = self::json($this->naechste());
        $lock = $aufgabe['lock'];

        $seiteEins = FakeJpeg::bauen(10, 10);
        self::json($this->seite($jobId, $lock, 0, 1, 2, $seiteEins));

        // Retried before moving on to page 2 - still claimed, not finished.
        $wiederholung = self::json($this->seite($jobId, $lock, 0, 1, 2, $seiteEins));
        self::assertSame('ok', $wiederholung['status']);

        self::assertCount(1, new DocumentArtifactRepository($this->pdo())->fuerDokument($documentId, ArtifactKind::PageImage));
        self::assertSame(1, (int) ($this->job($jobId)?->state['seq'] ?? -1), 'seq was not advanced twice');
    }

    public function testAGapInPagesIsRejected(): void
    {
        [$documentId] = $this->dokumentMitPdfs([self::PDF_INHALT]);
        $jobId = $this->enqueue($documentId);
        $aufgabe = self::json($this->naechste());
        $lock = $aufgabe['lock'];

        $antwort = $this->seite($jobId, $lock, 0, 2, 2, FakeJpeg::bauen(10, 10));

        self::assertSame(409, $antwort->status);
        self::assertSame('unerwartet', self::json($antwort)['status']);
        self::assertSame([], new DocumentArtifactRepository($this->pdo())->fuerDokument($documentId, ArtifactKind::PageImage));
    }

    public function testAWrongLockIsRejected(): void
    {
        [$documentId] = $this->dokumentMitPdfs([self::PDF_INHALT]);
        $jobId = $this->enqueue($documentId);
        self::json($this->naechste());

        $antwort = $this->seite($jobId, 'browser:falsch', 0, 1, 1, FakeJpeg::bauen(10, 10));

        self::assertSame(409, $antwort->status);
        self::assertSame('verloren', self::json($antwort)['status']);
        self::assertSame([], new DocumentArtifactRepository($this->pdo())->fuerDokument($documentId, ArtifactKind::PageImage));
    }

    /** Two open tabs: the second must not touch a job the first already claimed. */
    public function testASecondTabCannotClaimAnAlreadyClaimedJob(): void
    {
        [$documentId] = $this->dokumentMitPdfs([self::PDF_INHALT]);
        $jobId = $this->enqueue($documentId);
        self::json($this->naechste());

        $zweiteAufgabe = self::json($this->naechste());

        self::assertSame('leer', $zweiteAufgabe['status']);
        self::assertSame(1, $zweiteAufgabe['offen']);
        self::assertSame(JobStatus::Laeuft, $this->job($jobId)?->status);
    }

    public function testWithoutTheRightTheRouteRefuses(): void
    {
        $this->alsRolle(SystemRole::Kassenpruefer);

        $antwort = $this->naechsteAntwort();

        self::assertSame(403, $antwort->status);
    }

    public function testWithoutCsrfTheRequestIsRefused(): void
    {
        $antwort = $this->kernel()->handle(new Request(
            HttpMethod::Post,
            '/api/rasterung/naechste',
            cookies: $this->tresorCookie(),
        ));

        self::assertInstanceOf(Response::class, $antwort);
        self::assertSame(403, $antwort->status);
    }

    public function testWithoutTheUnlockedVaultReportsGesperrtAndTouchesNothing(): void
    {
        [$documentId] = $this->dokumentMitPdfs([self::PDF_INHALT]);
        $jobId = $this->enqueue($documentId);

        $antwort = self::json($this->naechste(entsperrt: false));

        self::assertSame('gesperrt', $antwort['status']);
        self::assertSame(1, $antwort['offen']);
        self::assertSame(JobStatus::Offen, $this->job($jobId)?->status);
    }

    public function testANonJpegBodyIsRejected(): void
    {
        $documentId = $this->dokumentMitPdfs([self::PDF_INHALT])[0];
        $jobId = $this->enqueue($documentId);
        $lock = self::json($this->naechste())['lock'];

        $antwort = $this->seite($jobId, $lock, 0, 1, 1, 'kein JPEG');

        self::assertSame(415, $antwort->status);
        self::assertSame('ungueltiger_typ', self::json($antwort)['status']);
    }

    public function testAnOversizedBodyIsRejected(): void
    {
        $documentId = $this->dokumentMitPdfs([self::PDF_INHALT])[0];
        $jobId = $this->enqueue($documentId);
        $lock = self::json($this->naechste())['lock'];

        $antwort = $this->seite($jobId, $lock, 0, 1, 1, str_repeat('x', 2 * 1024 * 1024 + 1));

        self::assertSame(413, $antwort->status);
        self::assertSame('zu_gross', self::json($antwort)['status']);
    }

    public function testAbbruchDefektFailsTheJobWithOnlyTheClassName(): void
    {
        $documentId = $this->dokumentMitPdfs([self::PDF_INHALT])[0];
        $jobId = $this->enqueue($documentId);
        $lock = self::json($this->naechste())['lock'];

        $antwort = self::json($this->abbruch($jobId, $lock, 'defekt'));

        self::assertSame('ok', $antwort['status']);
        $job = $this->job($jobId);
        self::assertSame(JobStatus::Fehler, $job?->status);
        self::assertSame(PdfRasterungFehlgeschlagen::class, $job?->lastError);
    }

    public function testAbbruchPasswortFailsTheJobWithTheRightClass(): void
    {
        $documentId = $this->dokumentMitPdfs([self::PDF_INHALT])[0];
        $jobId = $this->enqueue($documentId);
        $lock = self::json($this->naechste())['lock'];

        self::json($this->abbruch($jobId, $lock, 'passwort'));

        self::assertSame(PdfPasswortGeschuetzt::class, $this->job($jobId)?->lastError);
    }

    public function testAbbruchBrowserReleasesTheJobForAnotherAttempt(): void
    {
        $documentId = $this->dokumentMitPdfs([self::PDF_INHALT])[0];
        $jobId = $this->enqueue($documentId);
        $lock = self::json($this->naechste())['lock'];

        self::json($this->abbruch($jobId, $lock, 'browser'));

        self::assertSame(JobStatus::Offen, $this->job($jobId)?->status);
        self::assertNull($this->job($jobId)?->lockedBy);

        $erneut = self::json($this->naechste());
        self::assertSame('aufgabe', $erneut['status']);
        self::assertSame($jobId, $erneut['job']);
    }

    /** A later rendering run of the same document supersedes an earlier one's pages. */
    public function testFinishingAJobDropsPageImagesFromAnEarlierJobOfTheSameDocument(): void
    {
        $documentId = $this->dokumentMitPdfs([self::PDF_INHALT])[0];
        $jobs = new JobRepository($this->pdo());
        $now = new \DateTimeImmutable();

        // A finished earlier rendering run, its page still on file.
        $altesBlob = $this->blobService()->storeString(FakeJpeg::bauen(5, 5), new BlobMeta(MagicBytes::JPEG), $this->tresor);
        $alterJobId = $jobs->enqueue(PdfRasterung::JOB_TYP, JobExecutor::Browser, 'document', $documentId, now: $now);
        $jobs->finish($alterJobId, JobStatus::Fertig, now: $now);
        new DocumentArtifactRepository($this->pdo())->insert(
            $documentId,
            ArtifactKind::PageImage,
            0,
            $altesBlob->id,
            $this->tresor->sealDataKey(DataKey::generate()),
            null,
            JobExecutor::Browser,
            $alterJobId,
            $now,
        );

        // Only now does a second run start (e.g. a future manual re-trigger) -
        // enqueue()'d after the old job so claim() picks this one.
        $neuerJobId = $this->enqueue($documentId);
        $lock = self::json($this->naechste())['lock'];
        self::json($this->seite($neuerJobId, $lock, 0, 1, 1, FakeJpeg::bauen(5, 5)));

        $seiten = new DocumentArtifactRepository($this->pdo())->fuerDokument($documentId, ArtifactKind::PageImage);
        self::assertCount(1, $seiten, "only the new run's page remains");
        self::assertSame($neuerJobId, $seiten[0]->jobId);
        self::assertNull(new BlobRepository($this->pdo())->find($altesBlob->id), 'the superseded blob is gone');
    }

    // ----------------------------------------------------------- scaffolding

    /**
     * @param list<string> $pdfBytes one original PDF blob per entry
     *
     * @return array{0: int, 1: list<int>}
     */
    private function dokumentMitPdfs(array $pdfBytes): array
    {
        $documents = new DocumentRepository($this->pdo());
        $blobService = $this->blobService();

        $blobIds = [];
        foreach ($pdfBytes as $inhalt) {
            $blobIds[] = $blobService->storeString($inhalt, new BlobMeta(MagicBytes::PDF), $this->tresor)->id;
        }

        $documentId = $documents->insert(
            DocumentSource::Einreichung,
            null,
            $blobIds,
            $this->tresor->sealDataKey(DataKey::generate()),
            new \DateTimeImmutable(),
        );

        return [$documentId, $blobIds];
    }

    private function enqueue(int $documentId): int
    {
        return new JobRepository($this->pdo())->enqueue(PdfRasterung::JOB_TYP, JobExecutor::Browser, 'document', $documentId);
    }

    private function blobService(): BlobService
    {
        $repository = new BlobRepository($this->pdo());

        return new BlobService($repository, new DbBlobBackend($repository), new FsBlobBackend($this->blobDir));
    }

    private function job(int $id): ?Job
    {
        return new JobRepository($this->pdo())->find($id);
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

    private function lesen(?Blob $blob): string
    {
        self::assertNotNull($blob);
        $inhalt = '';
        foreach ($this->blobService()->openRead($blob, $this->tresor) as $stueck) {
            $inhalt .= $stueck;
        }

        return $inhalt;
    }

    /**
     * @return array<string, string>
     */
    private function tresorCookie(): array
    {
        return [Cookie::vaultKey('x', Request::httpsFromGlobals())->name => new SessionVault()->store($this->tresor)];
    }

    private function naechste(bool $entsperrt = true): Response
    {
        $antwort = $this->naechsteAntwort($entsperrt);
        self::assertInstanceOf(Response::class, $antwort);

        return $antwort;
    }

    private function naechsteAntwort(bool $entsperrt = true): ResponseInterface
    {
        return $this->kernel()->handle(new Request(
            HttpMethod::Post,
            '/api/rasterung/naechste',
            cookies: $entsperrt ? $this->tresorCookie() : [],
            post: ['_csrf' => new Session()->csrfToken()],
        ));
    }

    /**
     * The `quelle` route answers a StreamResponse (App\App\
     * InboxController::datei() answers the same way) when it finds the file,
     * a plain Response otherwise (no vault, not found) - collected into one
     * shape so the tests do not have to care which.
     *
     * @return object{status: int, headers: array<string, string>, body: string}
     */
    private function quelle(int $job, string $lock, int $quelle): object
    {
        $antwort = $this->kernel()->handle(new Request(
            HttpMethod::Get,
            '/api/rasterung/' . $job . '/' . $lock . '/quelle/' . $quelle,
            cookies: $this->tresorCookie(),
        ));

        if ($antwort instanceof Response) {
            return (object) ['status' => $antwort->status, 'headers' => $antwort->headers, 'body' => $antwort->body];
        }

        self::assertInstanceOf(StreamResponse::class, $antwort);
        $body = '';
        foreach ($antwort->chunks as $stueck) {
            $body .= $stueck;
        }

        return (object) ['status' => $antwort->status, 'headers' => $antwort->headers, 'body' => $body];
    }

    private function seite(int $job, string $lock, int $quelle, int $seite, int $seiten, string $bytes): Response
    {
        $this->body = $bytes;
        $antwort = $this->kernel()->handle(new Request(
            HttpMethod::Post,
            '/api/rasterung/' . $job . '/' . $lock . '/seite/' . $quelle . '/' . $seite . '/' . $seiten,
            cookies: $this->tresorCookie(),
            post: ['_csrf' => new Session()->csrfToken()],
        ));
        self::assertInstanceOf(Response::class, $antwort);

        return $antwort;
    }

    private function abbruch(int $job, string $lock, string $grund): Response
    {
        $antwort = $this->kernel()->handle(new Request(
            HttpMethod::Post,
            '/api/rasterung/' . $job . '/' . $lock . '/abbruch',
            cookies: $this->tresorCookie(),
            post: ['_csrf' => new Session()->csrfToken(), 'grund' => $grund],
        ));
        self::assertInstanceOf(Response::class, $antwort);

        return $antwort;
    }

    private function kernel(): Kernel
    {
        $paths = new Paths(dirname(__DIR__, 2));
        $view = new View($paths->viewsDir(), '0.0.0-test');
        $pdo = $this->pdo();

        $blobs = new BlobRepository($pdo);
        $blobService = new BlobService($blobs, new DbBlobBackend($blobs), new FsBlobBackend($this->blobDir));
        $rasterung = new PdfRasterung(
            new JobRepository($pdo),
            new DocumentRepository($pdo),
            $blobs,
            $blobService,
            new DocumentArtifactRepository($pdo),
        );

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
        $rasterungController = fn(): RasterungController => new RasterungController(
            new Session(),
            new SessionVault(),
            $view,
            $rasterung,
            new JobRunner(new JobRepository($pdo), [], [$rasterung]),
            function (): mixed {
                $stream = fopen('php://temp', 'r+b');
                fwrite($stream, $this->body);
                rewind($stream);

                return $stream;
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
            $unerreichbar,
            $rasterungController,
        );

        return new Kernel(
            router: $router,
            staticFiles: new StaticFileHandler($paths->publicDir(), longCache: false),
            view: $view,
            debug: true,
        );
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function assertOnlyIntegers(array $state): void
    {
        array_walk_recursive($state, static function (mixed $wert): void {
            self::assertIsInt($wert);
        });
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
