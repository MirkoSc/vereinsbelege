<?php

declare(strict_types=1);

namespace App\Tests\Service\Cron;

use App\Domain\BalanceCheck;
use App\Domain\BankAccountKind;
use App\Domain\BankImportStats;
use App\Domain\BlobMeta;
use App\Repository\BankAccountRepository;
use App\Repository\BankImportRepository;
use App\Repository\BlobRepository;
use App\Service\Cron\BankImportCleanupTask;
use App\Service\Crypto\Vault;
use App\Service\Migration\Migrator;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Tests\Support\DatabaseTestCase;

/**
 * Statement imports that stayed a preview go after a week, with their
 * encrypted file (issue #62/M9-4, docs/spec/04-bank-und-abgleich.md
 * section 4). A confirmed import is the original of its bookings and stays.
 * No vault involved - deleting is only ciphertext and rows.
 */
final class BankImportCleanupTaskTest extends DatabaseTestCase
{
    private string $blobDir;
    private Vault $vault;
    private int $konto;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->blobDir = sys_get_temp_dir() . '/vb_bank_import_cron_' . uniqid('', true);
        mkdir($this->blobDir, 0775, true);
        $this->vault = Vault::create();
        $this->konto = new BankAccountRepository($this->pdo())->insert(BankAccountKind::Bank, 'x', null, new \DateTimeImmutable('2024-01-01'), new \DateTimeImmutable());
    }

    protected function tearDown(): void
    {
        self::removeDir($this->blobDir);
        parent::tearDown();
    }

    public function testTheTaskIsNamedInTheCronAnswer(): void
    {
        self::assertSame('kontoauszug_vorschauen_aufraeumen', $this->task()->name());
    }

    public function testAPreviewNobodyConfirmedGoesAfterAWeekWithItsFile(): void
    {
        [$alt, $altBlob] = $this->vorschau(new \DateTimeImmutable('-8 days'));
        [$frisch, $frischBlob] = $this->vorschau(new \DateTimeImmutable('-6 days'));

        self::assertSame(1, $this->task()->run(new \DateTimeImmutable()));
        self::assertNull($this->importe()->find($alt));
        self::assertNull($this->blobs()->find($altBlob));
        self::assertNotNull($this->importe()->find($frisch));
        self::assertNotNull($this->blobs()->find($frischBlob));

        self::assertSame(0, $this->task()->run(new \DateTimeImmutable()), 'Safe to repeat.');
    }

    public function testAConfirmedImportStaysHoweverOld(): void
    {
        $alt = new \DateTimeImmutable('-30 days');
        [$laeuft, $laeuftBlob] = $this->vorschau($alt);
        [$fertig, $fertigBlob] = $this->vorschau($alt);
        $this->importe()->start($laeuft, null, new BankImportStats(), BalanceCheck::Ok, $alt);
        $this->importe()->start($fertig, null, new BankImportStats(), BalanceCheck::Ok, $alt);
        $this->importe()->finish($fertig, new BankImportStats(), $alt);

        self::assertSame(0, $this->task()->run(new \DateTimeImmutable()));
        self::assertNotNull($this->blobs()->find($laeuftBlob));
        self::assertNotNull($this->blobs()->find($fertigBlob));
    }

    /**
     * @return array{int, int} import id, blob id
     */
    private function vorschau(\DateTimeImmutable $am): array
    {
        $blob = $this->blobs()->storeString(':20:X', new BlobMeta('text/plain', 'auszug.sta'), $this->vault)->id;

        return [$this->importe()->insert($this->konto, 'mt940', $blob, null, null, null, null, $am), $blob];
    }

    private function importe(): BankImportRepository
    {
        return new BankImportRepository($this->pdo());
    }

    private function blobs(): BlobService
    {
        $repository = new BlobRepository($this->pdo());

        return new BlobService($repository, new DbBlobBackend($repository), new FsBlobBackend($this->blobDir));
    }

    private function task(): BankImportCleanupTask
    {
        return new BankImportCleanupTask($this->importe(), $this->blobs());
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
