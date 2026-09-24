<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Blob;
use App\Domain\BlobMeta;
use App\Domain\BlobStorage;
use App\Domain\DocumentSource;
use App\Domain\Job;
use App\Domain\JobExecutor;
use App\Domain\JobStatus;
use App\Repository\BlobRepository;
use App\Repository\DocumentRepository;
use App\Repository\JobRepository;
use App\Repository\SubmissionRepository;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\Vault;
use App\Service\Document\PdfErzeugung;
use App\Service\Migration\Migrator;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Service\Upload\MagicBytes;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FakeJpeg;
use App\Tests\Support\PdfStruktur;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The `pdf_erzeugen` job end to end (issue #26/M4-4, docs/spec/03-erfassung-
 * und-ki.md section 3), against a real database and a real directory, once
 * per storage backend: the PDF working copy is built without ever touching
 * the originals, only images produce one, and a locked vault stops the job
 * before it writes anything at all.
 *
 * No job runner exists yet (that is M4-7's `POST /api/jobs/step`), so this
 * drives App\Service\Job\JobHandler::schritt() the way the future runner
 * will: call it, thread `step`/`state` from the result into the next call,
 * stop once the job leaves `offen`.
 */
final class PdfErzeugungTest extends DatabaseTestCase
{
    private string $blobDir;
    private Vault $vault;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->blobDir = sys_get_temp_dir() . '/vb_pdf_blobs_' . uniqid('', true);
        mkdir($this->blobDir, 0775, true);
        $this->vault = Vault::create();
    }

    protected function tearDown(): void
    {
        self::removeDir($this->blobDir);
        parent::tearDown();
    }

    /**
     * @return array<string, array{BlobStorage}>
     */
    public static function backends(): array
    {
        return ['Dateisystem' => [BlobStorage::Fs], 'Datenbank' => [BlobStorage::Db]];
    }

    #[DataProvider('backends')]
    public function testTwoJpegsAndOnePngProduceAThreePagePdfWithTheReferenceAsTitle(BlobStorage $storage): void
    {
        $blobs = new BlobRepository($this->pdo());
        $documents = new DocumentRepository($this->pdo());
        $submissions = new SubmissionRepository($this->pdo());
        $blobService = $this->blobService($storage);
        $handler = new PdfErzeugung($documents, $blobs, $blobService, $submissions, new JobRepository($this->pdo()));

        $jpeg1 = FakeJpeg::bauen(breite: 400, hoehe: 300);
        $jpeg2 = FakeJpeg::bauen(breite: 200, hoehe: 200);
        $png = self::pngBytes(100, 50);

        $blob1 = $blobService->storeString($jpeg1, new BlobMeta(MagicBytes::JPEG), $this->vault);
        $blob2 = $blobService->storeString($jpeg2, new BlobMeta(MagicBytes::JPEG), $this->vault);
        $blob3 = $blobService->storeString($png, new BlobMeta(MagicBytes::PNG), $this->vault);

        $now = new \DateTimeImmutable();
        $submissionId = $submissions->insertDraft(random_bytes(32), $now);
        $submissions->complete($submissionId, 'R-2026-0099', $this->vault->sealDataKey(DataKey::generate()), 'x');

        $originale = [$blob1->id, $blob2->id, $blob3->id];
        $documentId = $documents->insert(
            DocumentSource::Einreichung,
            $submissionId,
            $originale,
            $this->vault->sealDataKey(DataKey::generate()),
            $now,
        );

        ['status' => $status, 'letzterState' => $letzterState] = self::runJob($handler, $documentId, $this->vault, $now);
        self::assertSame(JobStatus::Fertig, $status);
        self::assertOnlyIntegers($letzterState);

        $document = $documents->find($documentId);
        self::assertNotNull($document);
        self::assertNotNull($document->pdfBlobId);
        self::assertSame($originale, $document->originalBlobIds, 'die Originalliste bleibt unverändert');

        $pdfBlob = $blobs->find($document->pdfBlobId);
        self::assertNotNull($pdfBlob);
        $struktur = PdfStruktur::analysiere(self::lesen($blobService, $pdfBlob, $this->vault));
        self::assertCount(3, $struktur->seiten);
        self::assertSame('R-2026-0099', $struktur->titel);

        // Die Originale bleiben byte-identisch erhalten (E-10).
        self::assertSame($jpeg1, self::lesen($blobService, $blobs->find($blob1->id), $this->vault));
        self::assertSame($jpeg2, self::lesen($blobService, $blobs->find($blob2->id), $this->vault));
        self::assertSame($png, self::lesen($blobService, $blobs->find($blob3->id), $this->vault));

        // 3 Originale + 1 PDF - der PNG-Zwischenschritt-Blob wurde gelöscht.
        self::assertSame(4, self::blobAnzahl());

        // Ein zweiter Lauf ist sofort fertig, ohne einen weiteren Blob.
        ['status' => $zweiterStatus] = self::runJob($handler, $documentId, $this->vault, $now);
        self::assertSame(JobStatus::Fertig, $zweiterStatus);
        self::assertSame(4, self::blobAnzahl());
    }

    #[DataProvider('backends')]
    public function testASingleUploadedPdfBecomesItsOwnWorkingCopy(BlobStorage $storage): void
    {
        $blobs = new BlobRepository($this->pdo());
        $documents = new DocumentRepository($this->pdo());
        $submissions = new SubmissionRepository($this->pdo());
        $blobService = $this->blobService($storage);
        $handler = new PdfErzeugung($documents, $blobs, $blobService, $submissions, new JobRepository($this->pdo()));

        $pdfBlob = $blobService->storeString('irgendein PDF-Inhalt', new BlobMeta(MagicBytes::PDF), $this->vault);
        $now = new \DateTimeImmutable();
        $documentId = $documents->insert(
            DocumentSource::Einreichung,
            null,
            [$pdfBlob->id],
            $this->vault->sealDataKey(DataKey::generate()),
            $now,
        );

        ['status' => $status] = self::runJob($handler, $documentId, $this->vault, $now);

        self::assertSame(JobStatus::Fertig, $status);
        self::assertSame($pdfBlob->id, $documents->find($documentId)?->pdfBlobId);
        self::assertSame(1, self::blobAnzahl(), 'kein zusätzlicher Blob wurde erzeugt');
    }

    #[DataProvider('backends')]
    public function testMixedImageAndPdfPagesAreSkipped(BlobStorage $storage): void
    {
        $blobs = new BlobRepository($this->pdo());
        $documents = new DocumentRepository($this->pdo());
        $submissions = new SubmissionRepository($this->pdo());
        $blobService = $this->blobService($storage);
        $handler = new PdfErzeugung($documents, $blobs, $blobService, $submissions, new JobRepository($this->pdo()));

        $bild = $blobService->storeString(FakeJpeg::bauen(breite: 100, hoehe: 100), new BlobMeta(MagicBytes::JPEG), $this->vault);
        $pdf = $blobService->storeString('pdf-inhalt', new BlobMeta(MagicBytes::PDF), $this->vault);
        $now = new \DateTimeImmutable();
        $documentId = $documents->insert(
            DocumentSource::Einreichung,
            null,
            [$bild->id, $pdf->id],
            $this->vault->sealDataKey(DataKey::generate()),
            $now,
        );

        ['status' => $status] = self::runJob($handler, $documentId, $this->vault, $now);

        self::assertSame(JobStatus::Uebersprungen, $status);
        self::assertNull($documents->find($documentId)?->pdfBlobId);
        self::assertSame(2, self::blobAnzahl());
    }

    #[DataProvider('backends')]
    public function testMoreThanOneUploadedPdfIsSkipped(BlobStorage $storage): void
    {
        $blobs = new BlobRepository($this->pdo());
        $documents = new DocumentRepository($this->pdo());
        $submissions = new SubmissionRepository($this->pdo());
        $blobService = $this->blobService($storage);
        $handler = new PdfErzeugung($documents, $blobs, $blobService, $submissions, new JobRepository($this->pdo()));

        $pdf1 = $blobService->storeString('erstes PDF', new BlobMeta(MagicBytes::PDF), $this->vault);
        $pdf2 = $blobService->storeString('zweites PDF', new BlobMeta(MagicBytes::PDF), $this->vault);
        $now = new \DateTimeImmutable();
        $documentId = $documents->insert(
            DocumentSource::Einreichung,
            null,
            [$pdf1->id, $pdf2->id],
            $this->vault->sealDataKey(DataKey::generate()),
            $now,
        );

        ['status' => $status] = self::runJob($handler, $documentId, $this->vault, $now);

        self::assertSame(JobStatus::Uebersprungen, $status);
        self::assertNull($documents->find($documentId)?->pdfBlobId);
        self::assertSame(2, self::blobAnzahl());
    }

    /**
     * issue #30/M4-8: a single uploaded PDF has no page images yet, so
     * pdf_erzeugen queues App\Service\Document\PdfRasterung's job for it -
     * even though the upload itself already becomes the working copy.
     */
    #[DataProvider('backends')]
    public function testASingleUploadedPdfQueuesARenderPagesJob(BlobStorage $storage): void
    {
        $blobs = new BlobRepository($this->pdo());
        $documents = new DocumentRepository($this->pdo());
        $submissions = new SubmissionRepository($this->pdo());
        $jobs = new JobRepository($this->pdo());
        $blobService = $this->blobService($storage);
        $handler = new PdfErzeugung($documents, $blobs, $blobService, $submissions, $jobs);

        $pdfBlob = $blobService->storeString('irgendein PDF-Inhalt', new BlobMeta(MagicBytes::PDF), $this->vault);
        $now = new \DateTimeImmutable();
        $documentId = $documents->insert(DocumentSource::Einreichung, null, [$pdfBlob->id], $this->vault->sealDataKey(DataKey::generate()), $now);

        self::runJob($handler, $documentId, $this->vault, $now);

        $renderJob = $this->findRenderPagesJob($documentId);
        self::assertNotNull($renderJob);
        self::assertSame(JobExecutor::Browser, $renderJob->executor);
        self::assertSame('document', $renderJob->refType);
        self::assertSame($documentId, $renderJob->refId);
        self::assertSame(JobStatus::Offen, $renderJob->status);
    }

    /** Mixed pages leave the originals as the only representation, but the
     * PDF among them still needs rendering for a preview/for the KI later. */
    #[DataProvider('backends')]
    public function testMixedImageAndPdfQueuesARenderPagesJob(BlobStorage $storage): void
    {
        $blobs = new BlobRepository($this->pdo());
        $documents = new DocumentRepository($this->pdo());
        $submissions = new SubmissionRepository($this->pdo());
        $jobs = new JobRepository($this->pdo());
        $blobService = $this->blobService($storage);
        $handler = new PdfErzeugung($documents, $blobs, $blobService, $submissions, $jobs);

        $bild = $blobService->storeString(FakeJpeg::bauen(breite: 100, hoehe: 100), new BlobMeta(MagicBytes::JPEG), $this->vault);
        $pdf = $blobService->storeString('pdf-inhalt', new BlobMeta(MagicBytes::PDF), $this->vault);
        $now = new \DateTimeImmutable();
        $documentId = $documents->insert(DocumentSource::Einreichung, null, [$bild->id, $pdf->id], $this->vault->sealDataKey(DataKey::generate()), $now);

        self::runJob($handler, $documentId, $this->vault, $now);

        self::assertNotNull($this->findRenderPagesJob($documentId));
    }

    /** All-image documents already have their page images (the originals
     * themselves) - rendering the generated PDF back into images would be
     * redundant (docs/spec/03-erfassung-und-ki.md section 5). */
    #[DataProvider('backends')]
    public function testAllImagesDoesNotQueueARenderPagesJob(BlobStorage $storage): void
    {
        $blobs = new BlobRepository($this->pdo());
        $documents = new DocumentRepository($this->pdo());
        $submissions = new SubmissionRepository($this->pdo());
        $jobs = new JobRepository($this->pdo());
        $blobService = $this->blobService($storage);
        $handler = new PdfErzeugung($documents, $blobs, $blobService, $submissions, $jobs);

        $bild = $blobService->storeString(FakeJpeg::bauen(breite: 100, hoehe: 100), new BlobMeta(MagicBytes::JPEG), $this->vault);
        $now = new \DateTimeImmutable();
        $documentId = $documents->insert(DocumentSource::Einreichung, null, [$bild->id], $this->vault->sealDataKey(DataKey::generate()), $now);

        self::runJob($handler, $documentId, $this->vault, $now);

        self::assertNull($this->findRenderPagesJob($documentId));
    }

    /** A repeated pruefen() for the same document (defence in depth, class
     * docblock) must not queue a second render_pages job. */
    #[DataProvider('backends')]
    public function testPruefenNeverQueuesASecondRenderPagesJob(BlobStorage $storage): void
    {
        $blobs = new BlobRepository($this->pdo());
        $documents = new DocumentRepository($this->pdo());
        $submissions = new SubmissionRepository($this->pdo());
        $jobs = new JobRepository($this->pdo());
        $blobService = $this->blobService($storage);
        $handler = new PdfErzeugung($documents, $blobs, $blobService, $submissions, $jobs);

        $pdfBlob = $blobService->storeString('irgendein PDF-Inhalt', new BlobMeta(MagicBytes::PDF), $this->vault);
        $now = new \DateTimeImmutable();
        $documentId = $documents->insert(DocumentSource::Einreichung, null, [$pdfBlob->id], $this->vault->sealDataKey(DataKey::generate()), $now);

        $job = new Job(
            id: 0,
            typ: PdfErzeugung::JOB_TYP,
            refType: 'document',
            refId: $documentId,
            executor: JobExecutor::Session,
            status: JobStatus::Offen,
            step: '',
            state: [],
            attempts: 0,
            lastError: null,
            lockedBy: null,
            lockedUntil: null,
            createdAt: $now,
        );
        $handler->schritt($job, $this->vault, $now);
        $handler->schritt($job, $this->vault, $now);

        self::assertSame(
            1,
            (int) $this->pdo()->query("SELECT COUNT(*) FROM job WHERE typ = 'render_pages'")->fetchColumn(),
        );
    }

    private function findRenderPagesJob(int $documentId): ?Job
    {
        $stmt = $this->pdo()->prepare("SELECT id FROM job WHERE typ = 'render_pages' AND ref_type = 'document' AND ref_id = ?");
        $stmt->execute([$documentId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : new JobRepository($this->pdo())->find((int) $id);
    }

    public function testALockedVaultThrowsBeforeAnythingIsWritten(): void
    {
        $blobs = new BlobRepository($this->pdo());
        $documents = new DocumentRepository($this->pdo());
        $submissions = new SubmissionRepository($this->pdo());
        $blobService = $this->blobService(BlobStorage::Fs);
        $handler = new PdfErzeugung($documents, $blobs, $blobService, $submissions, new JobRepository($this->pdo()));

        $bild = $blobService->storeString(FakeJpeg::bauen(breite: 50, hoehe: 50), new BlobMeta(MagicBytes::JPEG), $this->vault);
        $now = new \DateTimeImmutable();
        $documentId = $documents->insert(
            DocumentSource::Einreichung,
            null,
            [$bild->id],
            $this->vault->sealDataKey(DataKey::generate()),
            $now,
        );

        $gesperrterTresor = Vault::locked($this->vault->publicKey());
        $job = new Job(
            id: 0,
            typ: PdfErzeugung::JOB_TYP,
            refType: 'document',
            refId: $documentId,
            executor: JobExecutor::Session,
            status: JobStatus::Offen,
            step: '',
            state: [],
            attempts: 0,
            lastError: null,
            lockedBy: null,
            lockedUntil: null,
            createdAt: $now,
        );

        $this->expectException(CryptoException::class);

        try {
            $handler->schritt($job, $gesperrterTresor, $now);
        } finally {
            self::assertSame(1, self::blobAnzahl());
            self::assertNull($documents->find($documentId)?->pdfBlobId);
        }
    }

    private function blobService(BlobStorage $default): BlobService
    {
        $repository = new BlobRepository($this->pdo());

        return new BlobService($repository, new DbBlobBackend($repository), new FsBlobBackend($this->blobDir), $default);
    }

    private function blobAnzahl(): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM file_blob')->fetchColumn();
    }

    private static function pngBytes(int $breite, int $hoehe): string
    {
        $bild = imagecreatetruecolor($breite, $hoehe);
        imagefill($bild, 0, 0, imagecolorallocate($bild, 200, 50, 50));
        ob_start();
        imagepng($bild);
        $png = ob_get_clean();

        return $png;
    }

    private static function lesen(BlobService $blobs, Blob $blob, Vault $vault): string
    {
        $inhalt = '';
        foreach ($blobs->openRead($blob, $vault) as $stueck) {
            $inhalt .= $stueck;
        }

        return $inhalt;
    }

    /**
     * Runs App\Service\Job\JobHandler::schritt() the way the future runner
     * (M4-7) will, threading `step`/`state` from one call's
     * App\Service\Job\JobSchrittErgebnis into the next Job value object -
     * nothing here touches the `job` table itself.
     *
     * @return array{status: JobStatus, letzterState: array<string, mixed>}
     */
    private static function runJob(PdfErzeugung $handler, int $documentId, Vault $vault, \DateTimeImmutable $now): array
    {
        $step = '';
        $state = [];

        for ($i = 0; $i < 50; $i++) {
            $job = new Job(
                id: 0,
                typ: PdfErzeugung::JOB_TYP,
                refType: 'document',
                refId: $documentId,
                executor: JobExecutor::Session,
                status: JobStatus::Offen,
                step: $step,
                state: $state,
                attempts: 0,
                lastError: null,
                lockedBy: null,
                lockedUntil: null,
                createdAt: $now,
            );

            $vorherigerState = $state;
            $ergebnis = $handler->schritt($job, $vault, $now);
            if ($ergebnis->status !== JobStatus::Offen) {
                return ['status' => $ergebnis->status, 'letzterState' => $vorherigerState];
            }

            $step = $ergebnis->step;
            $state = $ergebnis->state;
        }

        self::fail('Job kam nach 50 Schritten nicht zum Abschluss.');
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
