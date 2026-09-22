<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Blob;
use App\Domain\BlobMeta;
use App\Domain\BlobStorage;
use App\Repository\BlobRepository;
use App\Repository\SettingRepository;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\Vault;
use App\Service\Migration\Migrator;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Service\Storage\StorageSwitchService;
use App\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Switching the blob backend against a real database and a real directory
 * (issue #12 / M2-5, docs/spec/02-datenmodell.md "Dateien").
 *
 * The chain moves ciphertext, so none of this needs an unlocked vault - the
 * vault appears in one place only: to prove that a receipt still decrypts
 * after its move.
 */
final class StorageSwitchTest extends DatabaseTestCase
{
    private const string CONTENT = 'Rechnung Getraenkemarkt Mueller, 119,00 EUR, Sommerfest E-Jugend';

    private string $blobDir;

    private Vault $vault;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->blobDir = sys_get_temp_dir() . '/vb_switch_' . uniqid('', true);
        mkdir($this->blobDir, 0775, true);
        $this->vault = Vault::create();
    }

    protected function tearDown(): void
    {
        self::removeDir($this->blobDir);
        parent::tearDown();
    }

    /**
     * @return array<string, array{BlobStorage, BlobStorage}>
     */
    public static function directions(): array
    {
        return [
            'db to fs' => [BlobStorage::Db, BlobStorage::Fs],
            'fs to db' => [BlobStorage::Fs, BlobStorage::Db],
        ];
    }

    /**
     * The whole point of the issue: everything that was stored stays readable
     * after the switch, and nothing of it is left on the old shelf.
     */
    #[DataProvider('directions')]
    public function testSwitchMovesEverythingAndKeepsItReadable(BlobStorage $from, BlobStorage $to): void
    {
        $contents = [
            self::CONTENT,
            self::binaryContent(300_000),
            '',
        ];
        $blobs = [];
        foreach ($contents as $content) {
            $blobs[] = $this->blobs()->storeString($content, new BlobMeta('application/pdf'), $this->vault, $from);
        }

        $switch = $this->switch();
        $switch->setTarget($to);
        $this->runChain($switch);

        foreach ($blobs as $index => $blob) {
            $moved = $this->reload($blob->id);
            self::assertSame($to, $moved->storage, 'every blob lies on the target backend');
            self::assertSame($blob->cipherSha256, $moved->cipherSha256, 'the ciphertext is copied, not rewritten');
            self::assertTrue($this->blobs()->verify($moved));
            self::assertSame($contents[$index], self::collect($this->blobs()->openRead($moved, $this->vault)));
        }

        $this->assertNothingLeftOn($from);
    }

    /** After the move the source shelf is empty, in both directions. */
    private function assertNothingLeftOn(BlobStorage $from): void
    {
        if ($from === BlobStorage::Db) {
            self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM file_blob_chunk')->fetchColumn());

            return;
        }

        self::assertSame([], self::allFiles($this->blobDir), 'no ciphertext file is left behind');
        self::assertSame(
            0,
            (int) $this->pdo()->query('SELECT COUNT(*) FROM file_blob WHERE fs_name IS NOT NULL')->fetchColumn(),
            'a blob in the database keeps no file name',
        );
    }

    /**
     * The setting takes effect immediately, so an upload during the move
     * already lands on the target - and is not moved twice.
     */
    public function testNewBlobsGoToTheTargetWhileTheChainRuns(): void
    {
        $this->blobs()->storeString(self::CONTENT, new BlobMeta('image/jpeg'), $this->vault, BlobStorage::Db);

        $switch = $this->switch();
        $switch->setTarget(BlobStorage::Fs);
        self::assertSame(BlobStorage::Fs, BlobService::configuredStorage($this->settings()));

        $waehrenddessen = $this->blobs()->storeString(
            'zweiter Beleg',
            new BlobMeta('image/jpeg'),
            $this->vault,
            BlobService::configuredStorage($this->settings()),
        );
        self::assertSame(BlobStorage::Fs, $waehrenddessen->storage);
        self::assertSame(1, $switch->state()->offen, 'only the old blob has to move');

        $this->runChain($switch);

        self::assertSame(0, $switch->state()->offen);
        self::assertSame('zweiter Beleg', self::collect(
            $this->blobs()->openRead($this->reload($waehrenddessen->id), $this->vault),
        ));
    }

    /**
     * A move interrupted halfway is simply continued: the chain remembers
     * nothing, it counts what is left.
     */
    public function testAnInterruptedMoveIsResumed(): void
    {
        $ids = [];
        foreach (range(1, 5) as $n) {
            $ids[] = $this->blobs()
                ->storeString('Beleg ' . $n, new BlobMeta('application/pdf'), $this->vault, BlobStorage::Fs)
                ->id;
        }

        $switch = $this->switch();
        $switch->setTarget(BlobStorage::Db);

        // One request, then "the tab is closed": a fresh service, as the
        // next request would build it.
        $state = $switch->move();
        self::assertGreaterThan(0, $state->verschoben);

        $this->runChain($this->switch());

        foreach ($ids as $index => $id) {
            $blob = $this->reload($id);
            self::assertSame(BlobStorage::Db, $blob->storage);
            self::assertSame('Beleg ' . ($index + 1), self::collect($this->blobs()->openRead($blob, $this->vault)));
        }
        self::assertSame([], self::allFiles($this->blobDir));
    }

    /** Every step may be repeated - that is what makes a retry safe. */
    public function testStepsAreIdempotent(): void
    {
        $blob = $this->blobs()->storeString(self::CONTENT, new BlobMeta('image/jpeg'), $this->vault, BlobStorage::Db);

        $switch = $this->switch();
        $switch->setTarget(BlobStorage::Fs);
        $this->runChain($switch);

        $after = $this->reload($blob->id);
        $files = self::allFiles($this->blobDir);

        $switch->move();
        $switch->cleanUp();
        $switch->move();

        $again = $this->reload($blob->id);
        self::assertSame($after->storage, $again->storage);
        self::assertSame($after->fsName, $again->fsName);
        self::assertSame($files, self::allFiles($this->blobDir));
        self::assertSame(self::CONTENT, self::collect($this->blobs()->openRead($again, $this->vault)));
    }

    /**
     * An upload that broke off has no checksum, so its move could not be
     * verified. It stays where it is and is reported instead.
     */
    public function testUnfinishedBlobsStayAndAreCounted(): void
    {
        $repository = $this->repository();
        $draft = $repository->insertDraft(
            BlobStorage::Db,
            null,
            $this->vault->sealDataKey(DataKey::generate()),
            str_repeat("\x01", 25),
        );
        $fertig = $this->blobs()->storeString(self::CONTENT, new BlobMeta('image/jpeg'), $this->vault, BlobStorage::Db);

        $switch = $this->switch();
        $switch->setTarget(BlobStorage::Fs);
        $state = $switch->state();
        self::assertSame(1, $state->entwuerfe);
        self::assertSame(1, $state->offen, 'the draft is not part of the work list');

        $this->runChain($switch);

        self::assertSame(BlobStorage::Db, $this->reload($draft)->storage);
        self::assertSame(BlobStorage::Fs, $this->reload($fertig->id)->storage);
        self::assertSame(1, $switch->state()->entwuerfe);
    }

    /**
     * A run that dies between the flip and the delete leaves a leftover -
     * never a missing blob. Cleaning up is what the chain does about it.
     */
    public function testCleanUpRemovesLeftoversOfAnInterruptedMove(): void
    {
        $repository = $this->repository();

        // Looks like "moved to fs, chunks not deleted yet".
        $imDateisystem = $this->blobs()->storeString(self::CONTENT, new BlobMeta('image/jpeg'), $this->vault, BlobStorage::Fs);
        $repository->insertChunk($imDateisystem->id, 0, 'Rest eines abgebrochenen Laufs');

        // Looks like "moved to db, file not deleted yet": its own ciphertext
        // lies in the chunks, the row already says `db`, the file is still
        // there and the name still in the row.
        $inDerDatenbank = $this->blobs()->storeString('zweiter Beleg', new BlobMeta('image/jpeg'), $this->vault, BlobStorage::Fs);
        $pfad = $this->path((string) $inDerDatenbank->fsName);
        $seq = 0;
        foreach ($this->blobs()->readCipher($inDerDatenbank) as $piece) {
            $repository->insertChunk($inDerDatenbank->id, $seq++, $piece);
        }
        $repository->setStorage($inDerDatenbank->id, BlobStorage::Db);

        $this->switch()->cleanUp();

        self::assertSame(
            0,
            (int) $this->pdo()
                ->query('SELECT COUNT(*) FROM file_blob_chunk WHERE blob_id = ' . $imDateisystem->id)
                ->fetchColumn(),
            'chunks of a blob that lives in the file system are gone',
        );
        self::assertFileDoesNotExist($pfad);
        self::assertNull($this->reload($inDerDatenbank->id)->fsName);
        // Both blobs are readable afterwards - cleaning up removes copies,
        // never content.
        self::assertSame(self::CONTENT, self::collect(
            $this->blobs()->openRead($this->reload($imDateisystem->id), $this->vault),
        ));
        self::assertSame('zweiter Beleg', self::collect(
            $this->blobs()->openRead($this->reload($inDerDatenbank->id), $this->vault),
        ));
    }

    /** The integrity check the issue asks for, over everything that is stored. */
    public function testIntegrityCheckWalksEverythingAndFindsDamage(): void
    {
        $heil = $this->blobs()->storeString(self::CONTENT, new BlobMeta('image/jpeg'), $this->vault, BlobStorage::Fs);
        $kaputt = $this->blobs()->storeString(
            self::binaryContent(100_000),
            new BlobMeta('application/pdf'),
            $this->vault,
            BlobStorage::Fs,
        );

        $switch = $this->switch();
        $switch->startCheck();
        $state = $switch->verify();

        self::assertTrue($state->pruefung->fertig);
        self::assertSame(2, $state->pruefung->geprueft);
        self::assertSame([], $state->pruefung->beschaedigt);

        $this->flipAByte((string) $kaputt->fsName);
        $switch->startCheck();
        $state = $switch->verify();

        self::assertTrue($state->pruefung->fertig);
        self::assertSame([$kaputt->id], $state->pruefung->beschaedigt, 'only the row id, nothing about the file');
        self::assertTrue($this->blobs()->verify($this->reload($heil->id)));
    }

    /**
     * A blob whose source cannot be read stays where it is: a failed move
     * must not cost the only copy, and it must not stall the chain either.
     */
    public function testABlobThatCannotBeMovedStaysOnItsSource(): void
    {
        $kaputt = $this->blobs()->storeString(self::CONTENT, new BlobMeta('image/jpeg'), $this->vault, BlobStorage::Fs);
        $heil = $this->blobs()->storeString('zweiter Beleg', new BlobMeta('image/jpeg'), $this->vault, BlobStorage::Fs);
        unlink($this->path((string) $kaputt->fsName));

        $switch = $this->switch();
        $switch->setTarget(BlobStorage::Db);
        $state = $switch->move();

        self::assertSame(1, $state->verschoben);
        self::assertSame([$kaputt->id], $state->misslungen);
        self::assertSame(1, $state->offen, 'the unreadable blob is still open');
        self::assertSame(BlobStorage::Fs, $this->reload($kaputt->id)->storage);
        self::assertSame(BlobStorage::Db, $this->reload($heil->id)->storage);
        self::assertSame(
            0,
            (int) $this->pdo()
                ->query('SELECT COUNT(*) FROM file_blob_chunk WHERE blob_id = ' . $kaputt->id)
                ->fetchColumn(),
            'the failed copy leaves no half-written chunks',
        );
    }

    /**
     * A copy that does not match the checksum is thrown away, and the blob
     * keeps its verified source.
     */
    public function testACopyThatDoesNotMatchTheChecksumIsDiscarded(): void
    {
        $blob = $this->blobs()->storeString(self::CONTENT, new BlobMeta('image/jpeg'), $this->vault, BlobStorage::Fs);
        // Manipulate the source: the copy is then faithful to a broken
        // original, and the checksum in the row is what notices.
        $this->flipAByte((string) $blob->fsName);

        $switch = $this->switch();
        $switch->setTarget(BlobStorage::Db);
        $state = $switch->move();

        self::assertSame(0, $state->verschoben);
        self::assertSame([$blob->id], $state->misslungen);
        self::assertSame(BlobStorage::Fs, $this->reload($blob->id)->storage);
        self::assertSame(
            0,
            (int) $this->pdo()->query('SELECT COUNT(*) FROM file_blob_chunk')->fetchColumn(),
            'the rejected copy is removed again',
        );
    }

    /** Nothing in the chain's state may describe a receipt. */
    public function testTheStoredStateCarriesOnlyNumbers(): void
    {
        $this->blobs()->storeString(
            self::CONTENT,
            new BlobMeta('application/pdf', 'Rechnung_Getraenkemarkt.pdf'),
            $this->vault,
            BlobStorage::Fs,
        );

        $switch = $this->switch();
        $switch->setTarget(BlobStorage::Db);
        $this->runChain($switch);
        $switch->startCheck();
        $state = $switch->verify();

        $gespeichert = $this->settings()->get(StorageSwitchService::SETTING_CHECK);
        self::assertStringNotContainsString('Getraenkemarkt', $gespeichert);
        self::assertStringNotContainsString('.pdf', $gespeichert);
        self::assertMatchesRegularExpression('/^[\x20-\x7e]*$/', $gespeichert, 'plain JSON of numbers');

        $meldungen = json_encode($state->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Getraenkemarkt', $meldungen);
    }

    /** Runs the chain to the end, like the admin page's JavaScript does. */
    private function runChain(StorageSwitchService $switch): void
    {
        for ($i = 0; $i < 50; $i++) {
            $state = $switch->move();
            if ($state->offen === 0 || $state->verschoben === 0) {
                break;
            }
        }
        $switch->cleanUp();
    }

    private function switch(): StorageSwitchService
    {
        return new StorageSwitchService($this->repository(), $this->blobs(), $this->settings());
    }

    private function blobs(): BlobService
    {
        $repository = $this->repository();

        return new BlobService(
            $repository,
            new DbBlobBackend($repository),
            new FsBlobBackend($this->blobDir),
        );
    }

    private function repository(): BlobRepository
    {
        return new BlobRepository($this->pdo());
    }

    private function settings(): SettingRepository
    {
        return new SettingRepository($this->pdo());
    }

    private function reload(int $id): Blob
    {
        $blob = $this->repository()->find($id);
        self::assertNotNull($blob);

        return $blob;
    }

    private function path(string $fsName): string
    {
        return $this->blobDir . '/' . substr($fsName, 0, 2) . '/' . $fsName;
    }

    private function flipAByte(string $fsName): void
    {
        $path = $this->path($fsName);
        $content = (string) file_get_contents($path);
        $content[50] = chr(ord($content[50]) ^ 0x01);
        file_put_contents($path, $content);
    }

    /**
     * @param iterable<string> $chunks
     */
    private static function collect(iterable $chunks): string
    {
        $result = '';
        foreach ($chunks as $chunk) {
            $result .= $chunk;
        }

        return $result;
    }

    private static function binaryContent(int $size): string
    {
        $pattern = "\x00\xff\x0a\x0dBeleg\x1a";

        return substr(str_repeat($pattern, (int) ($size / strlen($pattern)) + 1), 0, $size);
    }

    /**
     * @return list<string>
     */
    private static function allFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = substr($file->getPathname(), strlen($dir) + 1);
            }
        }
        sort($files);

        return $files;
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
