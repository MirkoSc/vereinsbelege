<?php

declare(strict_types=1);

namespace App\Tests\Service\Cron;

use App\Domain\BlobMeta;
use App\Repository\BlobRepository;
use App\Repository\SubmissionUploadRepository;
use App\Service\Cron\SubmissionUploadCleanupTask;
use App\Service\Crypto\Vault;
use App\Service\Migration\Migrator;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Tests\Support\DatabaseTestCase;

/**
 * Blobs the public submission uploaded but no submission ever claimed go
 * after 24 h, together with their `submission_upload` row (issue #24/M4-2,
 * docs/spec/03-erfassung-und-ki.md section 4). No vault involved - deleting a
 * blob is only ever ciphertext and a row (CLAUDE.md section 4).
 */
final class SubmissionUploadCleanupTaskTest extends DatabaseTestCase
{
    private string $blobDir;
    private Vault $vault;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->blobDir = sys_get_temp_dir() . '/vb_submission_upload_cron_' . uniqid('', true);
        mkdir($this->blobDir, 0775, true);
        $this->vault = Vault::create();
    }

    protected function tearDown(): void
    {
        self::removeDir($this->blobDir);
        parent::tearDown();
    }

    public function testTheTaskIsNamedInTheCronAnswer(): void
    {
        self::assertSame('einreichungs_uploads_aufraeumen', $this->task()->name());
    }

    public function testAnUnclaimedBlobIsRemovedAfterADay(): void
    {
        $repository = new SubmissionUploadRepository($this->pdo());
        $liegengeblieben = $this->blob('a');
        $frisch = $this->blob('b');

        $repository->record($liegengeblieben, str_repeat("\x01", 32), new \DateTimeImmutable('-25 hours'));
        $repository->record($frisch, str_repeat("\x02", 32), new \DateTimeImmutable());

        self::assertSame(1, $this->task()->run(new \DateTimeImmutable()));
        self::assertNull($this->blobs()->find($liegengeblieben), 'the old blob is gone');
        self::assertNotNull($this->blobs()->find($frisch), 'the fresh one is left alone');
        self::assertSame(
            [$frisch],
            new SubmissionUploadRepository($this->pdo())->blobIdsForFormHash(str_repeat("\x02", 32)),
        );
    }

    public function testABlobJustUnderTheLimitIsLeftAlone(): void
    {
        $repository = new SubmissionUploadRepository($this->pdo());
        $blob = $this->blob('c');
        $repository->record($blob, str_repeat("\x03", 32), new \DateTimeImmutable('-23 hours'));

        self::assertSame(0, $this->task()->run(new \DateTimeImmutable()));
        self::assertNotNull($this->blobs()->find($blob));
    }

    public function testASecondRunFindsNothingLeftToDo(): void
    {
        $repository = new SubmissionUploadRepository($this->pdo());
        $blob = $this->blob('d');
        $repository->record($blob, str_repeat("\x04", 32), new \DateTimeImmutable('-25 hours'));

        $aufgabe = $this->task();
        self::assertSame(1, $aufgabe->run(new \DateTimeImmutable()));
        self::assertSame(0, $aufgabe->run(new \DateTimeImmutable()), 'the task has to be safe to repeat');
    }

    public function testAClaimedBlobHasNoRowLeftToSweep(): void
    {
        // A submission's own deleteForFormHash() removes the row the moment
        // it claims the blob - this only checks the cron leaves such a blob
        // alone because there is nothing left pointing at it as "unclaimed".
        $blob = $this->blob('e');

        self::assertSame(0, $this->task()->run(new \DateTimeImmutable()));
        self::assertNotNull($this->blobs()->find($blob));
    }

    private function blob(string $inhalt): int
    {
        return $this->blobs()->storeString($inhalt, new BlobMeta('application/pdf'), $this->vault)->id;
    }

    private function blobs(): BlobService
    {
        $repository = new BlobRepository($this->pdo());

        return new BlobService($repository, new DbBlobBackend($repository), new FsBlobBackend($this->blobDir));
    }

    private function task(): SubmissionUploadCleanupTask
    {
        return new SubmissionUploadCleanupTask(new SubmissionUploadRepository($this->pdo()), $this->blobs());
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $eintrag) {
            $pfad = $dir . '/' . $eintrag;
            is_dir($pfad) ? self::removeDir($pfad) : unlink($pfad);
        }

        rmdir($dir);
    }
}
