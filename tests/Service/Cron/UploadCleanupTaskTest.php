<?php

declare(strict_types=1);

namespace App\Tests\Service\Cron;

use App\Service\Cron\UploadCleanupTask;
use App\Service\Upload\UploadService;
use PHPUnit\Framework\TestCase;

/**
 * Orphaned upload chunks go after 24 h (docs/spec/03-erfassung-und-ki.md
 * section 4). No database, no decryption - which is what a CronTask is
 * allowed to do at all (CLAUDE.md section 4).
 */
final class UploadCleanupTaskTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vb_upload_cron_' . uniqid('', true);
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*/*') ?: [] as $datei) {
            unlink($datei);
        }
        foreach (glob($this->dir . '/*') ?: [] as $eintrag) {
            is_dir($eintrag) ? rmdir($eintrag) : unlink($eintrag);
        }
        rmdir($this->dir);
    }

    public function testTheTaskIsNamedInTheCronAnswer(): void
    {
        self::assertSame('uploads_aufraeumen', $this->task()->name());
    }

    public function testAnUploadNobodyFinishedIsRemovedAfterADay(): void
    {
        $dienst = new UploadService($this->dir, 4);
        $liegengeblieben = $dienst->create(8);
        $gerade = $dienst->create(8);

        $this->age($liegengeblieben->id, 25 * 3600);

        $jetzt = new \DateTimeImmutable();
        self::assertSame(1, $this->task()->run($jetzt));
        self::assertDirectoryDoesNotExist($this->dir . '/' . $liegengeblieben->id);
        self::assertDirectoryExists($this->dir . '/' . $gerade->id);
    }

    public function testAnUploadJustUnderTheLimitIsLeftAlone(): void
    {
        $dienst = new UploadService($this->dir, 4);
        $ticket = $dienst->create(8);
        $this->age($ticket->id, 23 * 3600);

        self::assertSame(0, $this->task()->run(new \DateTimeImmutable()));
        self::assertDirectoryExists($this->dir . '/' . $ticket->id);
    }

    public function testASecondRunFindsNothingLeftToDo(): void
    {
        $dienst = new UploadService($this->dir, 4);
        $ticket = $dienst->create(8);
        $this->age($ticket->id, 25 * 3600);

        $aufgabe = $this->task();
        self::assertSame(1, $aufgabe->run(new \DateTimeImmutable()));
        self::assertSame(0, $aufgabe->run(new \DateTimeImmutable()), 'the task has to be safe to repeat');
    }

    private function task(): UploadCleanupTask
    {
        return new UploadCleanupTask(new UploadService($this->dir));
    }

    private function age(string $id, int $sekunden): void
    {
        $zeit = time() - $sekunden;
        foreach (glob($this->dir . '/' . $id . '/*') ?: [] as $datei) {
            touch($datei, $zeit);
        }
        touch($this->dir . '/' . $id, $zeit);
    }
}
