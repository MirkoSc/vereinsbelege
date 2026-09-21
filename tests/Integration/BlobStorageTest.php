<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Blob;
use App\Domain\BlobMeta;
use App\Domain\BlobStorage;
use App\Repository\BlobRepository;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\Vault;
use App\Service\Migration\Migrator;
use App\Service\Storage\BlobException;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Blob storage against a real database and a real directory, once per backend
 * (issue #10: "Tests je Backend"; docs/spec/02-datenmodell.md "Dateien").
 *
 * What is being checked is not only "the file comes back": writing has to
 * work with a locked vault (the public submission has no secret), reading
 * must not, and nothing readable may be left on disk or in the dump.
 */
final class BlobStorageTest extends DatabaseTestCase
{
    private const string CONTENT = 'Rechnung Getraenkemarkt Mueller, 119,00 EUR, Sommerfest E-Jugend';

    private string $blobDir;

    private Vault $vault;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->blobDir = sys_get_temp_dir() . '/vb_blobs_' . uniqid('', true);
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
        return [
            'db' => [BlobStorage::Db],
            'fs' => [BlobStorage::Fs],
        ];
    }

    /**
     * @return array<string, array{BlobStorage, int}>
     */
    public static function backendsAndSizes(): array
    {
        $cases = [];
        foreach (self::backends() as $name => [$storage]) {
            // Around and well over the 64 KiB block size, so multi-block
            // streams and both chunk backends are actually exercised.
            foreach (['empty' => 0, 'small' => 64, 'one block' => 65536, 'several MiB' => 3 * 1024 * 1024 + 123] as $label => $size) {
                $cases[$name . ', ' . $label] = [$storage, $size];
            }
        }

        return $cases;
    }

    #[DataProvider('backendsAndSizes')]
    public function testRoundTrip(BlobStorage $storage, int $size): void
    {
        $content = self::binaryContent($size);
        $service = $this->service();

        $blob = $service->storeString($content, new BlobMeta('image/jpeg', 'beleg.jpg'), $this->vault, $storage);

        self::assertSame($storage, $blob->storage);
        self::assertSame($size, $blob->size);
        self::assertTrue($blob->isComplete());
        self::assertSame($content, self::collect($service->openRead($blob, $this->vault)));
        // Once more from the stored row, not from the object store() built.
        self::assertSame($content, self::collect($service->openRead($this->reload($blob->id), $this->vault)));
    }

    #[DataProvider('backends')]
    public function testStreamRoundTripWithoutAPlaintextTempFile(BlobStorage $storage): void
    {
        $content = self::binaryContent(200_000);
        $service = $this->service();
        $source = fopen('php://memory', 'w+b');
        self::assertIsResource($source);
        fwrite($source, $content);
        rewind($source);

        $blob = $service->storeStream($source, new BlobMeta('application/pdf'), $this->vault, $storage);
        fclose($source);

        $before = self::allFiles($this->blobDir);
        $pieces = [];
        foreach ($service->openRead($blob, $this->vault) as $piece) {
            $pieces[] = $piece;
        }

        self::assertSame($content, implode('', $pieces));
        self::assertGreaterThan(1, count($pieces), 'the plaintext arrives in pieces, not in one go');
        // Reading creates nothing: no plaintext file next to the ciphertext,
        // no temporary copy of the receipt.
        self::assertSame($before, self::allFiles($this->blobDir));
    }

    /**
     * The point of the sealed data key: the public submission encrypts
     * without holding a secret, and cannot read back what it wrote.
     */
    #[DataProvider('backends')]
    public function testLockedVaultCanWriteButNotRead(BlobStorage $storage): void
    {
        $service = $this->service();
        $locked = Vault::locked($this->vault->publicKey());

        $blob = $service->storeString(self::CONTENT, new BlobMeta('image/jpeg'), $locked, $storage);

        self::assertTrue($blob->isComplete());

        try {
            self::collect($service->openRead($blob, $locked));
            self::fail('a locked vault must not be able to read a blob');
        } catch (CryptoException $e) {
            self::assertStringContainsString('locked', $e->getMessage());
        }

        // The same blob, in a session whose vault is unlocked.
        self::assertSame(self::CONTENT, self::collect($service->openRead($blob, $this->vault)));
    }

    #[DataProvider('backends')]
    public function testMetadataIsEncryptedAndBoundToItsRow(BlobStorage $storage): void
    {
        $service = $this->service();

        $blob = $service->storeString(
            self::CONTENT,
            new BlobMeta('application/pdf', 'Rechnung_Getraenkemarkt.pdf', pages: 3),
            $this->vault,
            $storage,
        );

        $meta = $service->meta($blob, $this->vault);
        self::assertNotNull($meta);
        self::assertSame('application/pdf', $meta->mimeType);
        self::assertSame('Rechnung_Getraenkemarkt.pdf', $meta->originalName);
        self::assertSame(3, $meta->pages);

        $stored = (string) $this->pdo()->query('SELECT meta_enc FROM file_blob')->fetchColumn();
        self::assertStringNotContainsString('Getraenkemarkt', $stored);

        // Moved to another row it no longer decrypts (AAD binding).
        $key = $this->vault->openDataKey($blob->dekSealed);
        $this->expectException(CryptoException::class);
        FieldCipher::decrypt($key, $stored, new FieldContext('file_blob', $blob->id + 1, 'meta_enc'));
    }

    #[DataProvider('backends')]
    public function testNothingReadableIsStored(BlobStorage $storage): void
    {
        $service = $this->service();

        $blob = $service->storeString(self::CONTENT, new BlobMeta('text/plain', 'beleg.txt'), $this->vault, $storage);

        $stored = implode('', iterator_to_array($service->readCipher($blob), false));
        self::assertStringNotContainsString('Getraenkemarkt', $stored);
        self::assertStringNotContainsString('Sommerfest', $stored);

        $dump = $this->dumpOfAllTables();
        self::assertStringNotContainsString('Getraenkemarkt', $dump);
        self::assertStringNotContainsString('beleg.txt', $dump);
    }

    public function testFileNamesAreRandomIdsOnly(): void
    {
        $service = $this->service();

        $blob = $service->storeString(
            self::CONTENT,
            new BlobMeta('image/jpeg', 'Rechnung_Getraenkemarkt.jpg'),
            $this->vault,
            BlobStorage::Fs,
        );

        self::assertNotNull($blob->fsName);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $blob->fsName);

        $files = self::allFiles($this->blobDir);
        self::assertCount(1, $files);
        self::assertStringNotContainsString('Rechnung', $files[0]);
        self::assertStringNotContainsString('.jpg', $files[0]);
        // Sharded by the first two characters, not a flat directory.
        self::assertSame(substr($blob->fsName, 0, 2) . '/' . $blob->fsName, $files[0]);
    }

    public function testDatabaseBackendKeepsChunksBelowTheRowLimit(): void
    {
        $service = $this->service();

        $blob = $service->storeString(self::binaryContent(700_000), new BlobMeta('application/pdf'), $this->vault, BlobStorage::Db);

        $sizes = $this->pdo()
            ->query('SELECT LENGTH(data) FROM file_blob_chunk WHERE blob_id = ' . $blob->id . ' ORDER BY seq')
            ->fetchAll(\PDO::FETCH_COLUMN);
        self::assertGreaterThan(1, count($sizes));
        foreach ($sizes as $size) {
            self::assertLessThanOrEqual(DbBlobBackend::CHUNK_BYTES, (int) $size);
        }
        self::assertEmpty(self::allFiles($this->blobDir), 'the db backend writes no files');
    }

    #[DataProvider('backends')]
    public function testVerifyDetectsManipulatedCiphertext(BlobStorage $storage): void
    {
        $service = $this->service();
        $blob = $service->storeString(self::binaryContent(100_000), new BlobMeta('application/pdf'), $this->vault, $storage);

        self::assertTrue($service->verify($blob));

        $this->flipAByte($storage, $blob->id, $blob->fsName);

        self::assertFalse($service->verify($this->reload($blob->id)));
        $this->expectException(CryptoException::class);
        self::collect($service->openRead($this->reload($blob->id), $this->vault));
    }

    #[DataProvider('backends')]
    public function testDeleteRemovesRowAndContent(BlobStorage $storage): void
    {
        $service = $this->service();
        $blob = $service->storeString(self::CONTENT, new BlobMeta('image/jpeg'), $this->vault, $storage);
        self::assertTrue($service->exists($blob));

        $service->delete($blob);

        self::assertNull(new BlobRepository($this->pdo())->find($blob->id));
        self::assertFalse($service->exists($blob));
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM file_blob_chunk')->fetchColumn());
        self::assertEmpty(self::allFiles($this->blobDir));
    }

    /**
     * A write that breaks off leaves nothing behind that later looks like a
     * readable receipt.
     */
    #[DataProvider('backends')]
    public function testFailedWriteLeavesNoBlob(BlobStorage $storage): void
    {
        $service = $this->service();
        $source = (function (): \Generator {
            yield self::binaryContent(300_000);

            throw new \RuntimeException('Verbindung abgebrochen');
        })();

        try {
            $service->store($source, new BlobMeta('application/pdf'), $this->vault, $storage);
            self::fail('the broken write should have thrown');
        } catch (\RuntimeException $e) {
            self::assertSame('Verbindung abgebrochen', $e->getMessage());
        }

        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM file_blob')->fetchColumn());
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM file_blob_chunk')->fetchColumn());
        self::assertEmpty(self::allFiles($this->blobDir));
    }

    public function testDraftWithoutChecksumCannotBeRead(): void
    {
        $repository = new BlobRepository($this->pdo());
        $id = $repository->insertDraft(
            BlobStorage::Db,
            null,
            $this->vault->sealDataKey(DataKey::generate()),
            str_repeat("\x01", 25),
        );

        $blob = $repository->find($id);
        self::assertNotNull($blob);
        self::assertFalse($blob->isComplete());

        $this->expectException(BlobException::class);
        self::collect($this->service()->openRead($blob, $this->vault));
    }

    public function testConfiguredStorageFallsBackToTheDefault(): void
    {
        self::assertSame(BlobStorage::Fs, BlobStorage::default());
        self::assertSame(BlobStorage::Fs, BlobStorage::fromSetting(''));
        self::assertSame(BlobStorage::Fs, BlobStorage::fromSetting('irgendwas'));
        self::assertSame(BlobStorage::Db, BlobStorage::fromSetting('db'));
    }

    private function reload(int $id): Blob
    {
        $blob = $this->service()->find($id);
        self::assertNotNull($blob);

        return $blob;
    }

    private function service(): BlobService
    {
        $repository = new BlobRepository($this->pdo());

        return new BlobService(
            $repository,
            new DbBlobBackend($repository),
            new FsBlobBackend($this->blobDir),
        );
    }

    private function flipAByte(BlobStorage $storage, int $id, ?string $fsName): void
    {
        if ($storage === BlobStorage::Fs) {
            $path = $this->blobDir . '/' . substr((string) $fsName, 0, 2) . '/' . $fsName;
            $content = (string) file_get_contents($path);
            $content[50] = chr(ord($content[50]) ^ 0x01);
            file_put_contents($path, $content);

            return;
        }

        $data = (string) $this->pdo()
            ->query('SELECT data FROM file_blob_chunk WHERE blob_id = ' . $id . ' AND seq = 0')
            ->fetchColumn();
        $data[50] = chr(ord($data[50]) ^ 0x01);
        $stmt = $this->pdo()->prepare('UPDATE file_blob_chunk SET data = ? WHERE blob_id = ? AND seq = 0');
        $stmt->bindValue(1, $data, \PDO::PARAM_LOB);
        $stmt->bindValue(2, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    private function dumpOfAllTables(): string
    {
        $dump = '';
        foreach (['file_blob', 'file_blob_chunk'] as $table) {
            foreach ($this->pdo()->query('SELECT * FROM ' . $table)->fetchAll() as $row) {
                $dump .= implode('|', array_map(strval(...), $row));
            }
        }

        return $dump;
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
        if ($size === 0) {
            return '';
        }

        // Binary, with the bytes a naive implementation trips over: NUL,
        // 0xFF, newlines.
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
