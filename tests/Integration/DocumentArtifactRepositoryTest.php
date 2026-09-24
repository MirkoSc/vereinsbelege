<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\ArtifactKind;
use App\Domain\BlobMeta;
use App\Domain\DocumentSource;
use App\Domain\JobExecutor;
use App\Repository\BlobRepository;
use App\Repository\DocumentArtifactRepository;
use App\Repository\DocumentRepository;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\Vault;
use App\Service\Migration\Migrator;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Service\Upload\MagicBytes;
use App\Tests\Support\DatabaseTestCase;

/**
 * The `document_artifact` table (docs/spec/02-datenmodell.md "Fachdaten",
 * issue #30/M4-8) against a real server, on the schema the installer
 * creates (migrations/015_document_artifact.sql).
 */
final class DocumentArtifactRepositoryTest extends DatabaseTestCase
{
    private DocumentArtifactRepository $artifacts;
    private DocumentRepository $documents;
    private BlobService $blobService;
    private Vault $vault;
    private \DateTimeImmutable $t0;
    private string $blobDir;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->artifacts = new DocumentArtifactRepository($this->pdo());
        $this->documents = new DocumentRepository($this->pdo());
        $blobs = new BlobRepository($this->pdo());
        $this->blobDir = sys_get_temp_dir() . '/vb_artifact_blobs_' . uniqid('', true);
        mkdir($this->blobDir, 0775, true);
        $this->blobService = new BlobService($blobs, new DbBlobBackend($blobs), new FsBlobBackend($this->blobDir));
        $this->vault = Vault::create();
        $this->t0 = new \DateTimeImmutable('2026-03-01 12:00:00');
    }

    protected function tearDown(): void
    {
        self::removeDir($this->blobDir);
        parent::tearDown();
    }

    private function dokument(): int
    {
        return $this->documents->insert(
            DocumentSource::Intern,
            null,
            [],
            $this->vault->sealDataKey(DataKey::generate()),
            $this->t0,
        );
    }

    private function blobId(): int
    {
        return $this->blobService->storeString('seite', new BlobMeta(MagicBytes::JPEG), $this->vault)->id;
    }

    public function testInsertAndFuerDokumentRoundtrip(): void
    {
        $documentId = $this->dokument();
        $blobId = $this->blobId();

        $id = $this->artifacts->insert(
            $documentId,
            ArtifactKind::PageImage,
            0,
            $blobId,
            $this->vault->sealDataKey(DataKey::generate()),
            null,
            JobExecutor::Browser,
            42,
            $this->t0,
        );

        self::assertGreaterThan(0, $id);

        $seiten = $this->artifacts->fuerDokument($documentId, ArtifactKind::PageImage);
        self::assertCount(1, $seiten);
        self::assertSame($documentId, $seiten[0]->documentId);
        self::assertSame(ArtifactKind::PageImage, $seiten[0]->kind);
        self::assertSame(0, $seiten[0]->seq);
        self::assertSame($blobId, $seiten[0]->blobId);
        self::assertSame(JobExecutor::Browser, $seiten[0]->producer);
        self::assertSame(42, $seiten[0]->jobId);
    }

    public function testFuerDokumentOrdersBySeq(): void
    {
        $documentId = $this->dokument();
        $this->artifacts->insert($documentId, ArtifactKind::PageImage, 2, $this->blobId(), $this->vault->sealDataKey(DataKey::generate()), null, JobExecutor::Browser, 1, $this->t0);
        $this->artifacts->insert($documentId, ArtifactKind::PageImage, 0, $this->blobId(), $this->vault->sealDataKey(DataKey::generate()), null, JobExecutor::Browser, 1, $this->t0);
        $this->artifacts->insert($documentId, ArtifactKind::PageImage, 1, $this->blobId(), $this->vault->sealDataKey(DataKey::generate()), null, JobExecutor::Browser, 1, $this->t0);

        $seqs = array_map(static fn($a) => $a->seq, $this->artifacts->fuerDokument($documentId, ArtifactKind::PageImage));
        self::assertSame([0, 1, 2], $seqs);
    }

    public function testVorhandenIsScopedToDocumentKindJobAndSeq(): void
    {
        $documentId = $this->dokument();
        $this->artifacts->insert($documentId, ArtifactKind::PageImage, 0, $this->blobId(), $this->vault->sealDataKey(DataKey::generate()), null, JobExecutor::Browser, 7, $this->t0);

        self::assertTrue($this->artifacts->vorhanden($documentId, ArtifactKind::PageImage, 7, 0));
        self::assertFalse($this->artifacts->vorhanden($documentId, ArtifactKind::PageImage, 7, 1), 'anderer seq');
        self::assertFalse($this->artifacts->vorhanden($documentId, ArtifactKind::PageImage, 8, 0), 'anderer Job');
        self::assertFalse($this->artifacts->vorhanden($documentId, ArtifactKind::Text, 7, 0), 'andere Art');
    }

    /** The unique key backstops what App\Service\Document\PdfRasterung checks first. */
    public function testTheSameDocumentKindJobAndSeqCannotBeInsertedTwice(): void
    {
        $documentId = $this->dokument();
        $this->artifacts->insert($documentId, ArtifactKind::PageImage, 0, $this->blobId(), $this->vault->sealDataKey(DataKey::generate()), null, JobExecutor::Browser, 1, $this->t0);

        $this->expectException(\PDOException::class);
        $this->artifacts->insert($documentId, ArtifactKind::PageImage, 0, $this->blobId(), $this->vault->sealDataKey(DataKey::generate()), null, JobExecutor::Browser, 1, $this->t0);
    }

    public function testFremdeBlobIdsFindsOnlyOtherJobsBlobs(): void
    {
        $documentId = $this->dokument();
        $alterBlob = $this->blobId();
        $neuerBlob = $this->blobId();
        $this->artifacts->insert($documentId, ArtifactKind::PageImage, 0, $alterBlob, $this->vault->sealDataKey(DataKey::generate()), null, JobExecutor::Browser, 1, $this->t0);
        $this->artifacts->insert($documentId, ArtifactKind::PageImage, 0, $neuerBlob, $this->vault->sealDataKey(DataKey::generate()), null, JobExecutor::Browser, 2, $this->t0);

        self::assertSame([$alterBlob], $this->artifacts->fremdeBlobIds($documentId, ArtifactKind::PageImage, 2));
        self::assertSame([$neuerBlob], $this->artifacts->fremdeBlobIds($documentId, ArtifactKind::PageImage, 1));
        // A job id that owns nothing here: every artifact of this kind counts
        // as "someone else's" - correct, if unlikely to happen in practice
        // (PdfErzeugung enqueues at most one render_pages job per document).
        self::assertSame([$alterBlob, $neuerBlob], $this->artifacts->fremdeBlobIds($documentId, ArtifactKind::PageImage, 99));
    }

    /** ON DELETE CASCADE (migrations/015_document_artifact.sql): deleting the
     * blob is how App\Service\Document\PdfRasterung drops a superseded page. */
    public function testDeletingTheBlobRemovesTheArtifactRow(): void
    {
        $documentId = $this->dokument();
        $blob = $this->blobService->find($this->blobId());
        self::assertNotNull($blob);
        $this->artifacts->insert($documentId, ArtifactKind::PageImage, 0, $blob->id, $this->vault->sealDataKey(DataKey::generate()), null, JobExecutor::Browser, 1, $this->t0);

        $this->blobService->delete($blob);

        self::assertSame([], $this->artifacts->fuerDokument($documentId, ArtifactKind::PageImage));
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
