<?php

declare(strict_types=1);

namespace App\Tests\Service\Upload;

use App\Service\Upload\UploadError;
use App\Service\Upload\UploadException;
use App\Service\Upload\UploadService;
use PHPUnit\Framework\TestCase;

/**
 * The chunk collection in shared/var/tmp (docs/spec/03-erfassung-und-ki.md
 * section 4). Required tests of that spec: order, missing chunks, size limit
 * (the magic byte check has its own test class).
 *
 * The tests open their uploads with a chunk size of four bytes: the logic
 * only ever reads the size from the upload's own metadata, so four bytes
 * exercise exactly the same paths as two megabytes - and a wrong boundary is
 * visible instead of drowned in a multi-megabyte file.
 */
final class UploadServiceTest extends TestCase
{
    private const int CHUNK = 4;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vb_uploads_' . uniqid('', true);
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        self::removeDir($this->dir);
    }

    // ---------------------------------------------------------------- Größe

    public function testAnEmptyFileIsRefusedBeforeAnythingIsTransferred(): void
    {
        $this->expectUploadError(UploadError::EmptyFile);
        $this->service()->create(0);
    }

    public function testAFileAboveTheLimitIsRefusedBeforeAnythingIsTransferred(): void
    {
        $this->expectUploadError(UploadError::TooLarge);
        $this->service()->create(UploadService::MAX_FILE_BYTES + 1);
    }

    public function testTheLimitItselfIsStillAllowed(): void
    {
        $ticket = $this->service()->create(UploadService::MAX_FILE_BYTES);

        self::assertSame(UploadService::MAX_FILE_BYTES, $ticket->groesse);
        self::assertSame(
            (int) ceil(UploadService::MAX_FILE_BYTES / self::CHUNK),
            $ticket->chunks,
        );
    }

    public function testAChunkLongerThanItMayBeIsRefusedAndLeavesNothingBehind(): void
    {
        $dienst = $this->service();
        $ticket = $dienst->create(10);

        try {
            $dienst->writeChunk($ticket->id, 0, self::stream('viel zu lang'));
            self::fail('an oversized chunk has to be refused');
        } catch (UploadException $e) {
            self::assertSame(UploadError::ChunkTooLarge, $e->error);
        }

        self::assertSame([0, 1, 2], $dienst->status($ticket->id)->fehlend);
        self::assertSame(
            ['meta.json'],
            self::entries($this->dir . '/' . $ticket->id),
            'the half written chunk is gone, not left as a temporary file',
        );
    }

    public function testTheLastChunkIsTheShortOne(): void
    {
        $dienst = $this->service();
        $ticket = $dienst->create(10);

        self::assertSame(3, $ticket->chunks);
        self::assertSame(4, $dienst->writeChunk($ticket->id, 0, self::stream('0123')));
        self::assertSame(2, $dienst->writeChunk($ticket->id, 2, self::stream('89')));

        // Four bytes would be too many for the last chunk of a ten byte file.
        $this->expectUploadError(UploadError::ChunkTooLarge);
        $dienst->writeChunk($ticket->id, 2, self::stream('8901'));
    }

    // ------------------------------------------------------------ Reihenfolge

    public function testChunksMayArriveInAnyOrderAndAreReadBackInOrder(): void
    {
        $dienst = $this->service();
        $inhalt = 'Beleg-Bytes!';
        $ticket = $dienst->create(strlen($inhalt));

        // Deliberately backwards - a bad mobile connection retries, and the
        // browser may well finish a later chunk first.
        foreach ([2, 0, 1] as $index) {
            $dienst->writeChunk($ticket->id, $index, self::stream(substr($inhalt, $index * self::CHUNK, self::CHUNK)));
        }

        $status = $dienst->status($ticket->id);
        self::assertTrue($status->vollstaendig());
        self::assertSame([], $status->fehlend);
        self::assertSame(strlen($inhalt), $status->empfangen);
        self::assertSame($inhalt, self::collect($dienst->chunks($ticket->id)));
    }

    public function testResendingAChunkOverwritesIt(): void
    {
        $dienst = $this->service();
        $ticket = $dienst->create(8);

        $dienst->writeChunk($ticket->id, 0, self::stream('XXXX'));
        $dienst->writeChunk($ticket->id, 1, self::stream('5678'));
        $dienst->writeChunk($ticket->id, 0, self::stream('1234'));

        self::assertSame('12345678', self::collect($dienst->chunks($ticket->id)));
    }

    public function testAnIndexOutsideTheFileIsRefused(): void
    {
        $dienst = $this->service();
        $ticket = $dienst->create(8);

        try {
            $dienst->writeChunk($ticket->id, 2, self::stream('ab'));
            self::fail('an index past the last chunk has to be refused');
        } catch (UploadException $e) {
            self::assertSame(UploadError::ChunkOutOfRange, $e->error);
        }

        $this->expectUploadError(UploadError::ChunkOutOfRange);
        $dienst->writeChunk($ticket->id, -1, self::stream('ab'));
    }

    // -------------------------------------------------------- fehlende Chunks

    public function testAMissingChunkIsNamedSoTheBrowserCanResendJustThatOne(): void
    {
        $dienst = $this->service();
        $ticket = $dienst->create(12);

        $dienst->writeChunk($ticket->id, 0, self::stream('0123'));
        $dienst->writeChunk($ticket->id, 2, self::stream('89ab'));

        $status = $dienst->status($ticket->id);
        self::assertFalse($status->vollstaendig());
        self::assertSame([1], $status->fehlend);
        self::assertSame(8, $status->empfangen);
    }

    public function testAChunkThatArrivedTooShortCountsAsMissing(): void
    {
        $dienst = $this->service();
        $ticket = $dienst->create(8);

        $dienst->writeChunk($ticket->id, 0, self::stream('12'));
        $dienst->writeChunk($ticket->id, 1, self::stream('5678'));

        self::assertSame([0], $dienst->status($ticket->id)->fehlend, 'a truncated body is not a chunk');

        $dienst->writeChunk($ticket->id, 0, self::stream('1234'));
        self::assertTrue($dienst->status($ticket->id)->vollstaendig());
    }

    public function testReadingAnUploadWhoseChunkVanishedIsAnError(): void
    {
        $dienst = $this->service();
        $ticket = $dienst->create(8);
        $dienst->writeChunk($ticket->id, 0, self::stream('1234'));

        $this->expectUploadError(UploadError::Incomplete);
        self::collect($dienst->chunks($ticket->id));
    }

    // ------------------------------------------------------------------ Rest

    public function testTheHeadOfTheFileComesFromTheFirstChunk(): void
    {
        $dienst = $this->service();
        $ticket = $dienst->create(8);

        self::assertSame('', $dienst->head($ticket->id), 'nothing has arrived yet');

        $dienst->writeChunk($ticket->id, 0, self::stream('%PDF'));
        self::assertSame('%PDF', $dienst->head($ticket->id));
        self::assertSame('%P', $dienst->head($ticket->id, 2));
    }

    public function testAnUnknownUploadIsNotADirectoryTraversal(): void
    {
        $dienst = $this->service();

        foreach (['../../etc', 'nicht-hex', '', str_repeat('a', 31), str_repeat('A', 32)] as $id) {
            try {
                $dienst->status($id);
                self::fail('an id that is not 32 hex characters must not reach the file system');
            } catch (UploadException $e) {
                self::assertSame(UploadError::UnknownUpload, $e->error);
            }
        }
    }

    public function testAnUploadThatWasNeverOpenedIsUnknown(): void
    {
        $this->expectUploadError(UploadError::UnknownUpload);
        $this->service()->status(str_repeat('a', 32));
    }

    public function testDiscardRemovesEverythingAndIsRepeatable(): void
    {
        $dienst = $this->service();
        $ticket = $dienst->create(8);
        $dienst->writeChunk($ticket->id, 0, self::stream('1234'));

        $dienst->discard($ticket->id);
        self::assertDirectoryDoesNotExist($this->dir . '/' . $ticket->id);

        // The closing request and the cron may run into each other.
        $dienst->discard($ticket->id);
        $dienst->discard('../../etc');
    }

    public function testTheTemporaryFilesCarryNoFileName(): void
    {
        $dienst = $this->service();
        $ticket = $dienst->create(8);
        $dienst->writeChunk($ticket->id, 0, self::stream('1234'));

        $meta = file_get_contents($this->dir . '/' . $ticket->id . '/meta.json');
        self::assertIsString($meta);
        self::assertSame(
            ['chunk_bytes', 'chunks', 'erstellt', 'groesse'],
            self::sortedKeys($meta),
            'the metadata holds structure only - a file name is business data',
        );
        self::assertSame(['0.part', 'meta.json'], self::entries($this->dir . '/' . $ticket->id));
    }

    // -------------------------------------------------------------- Aufräumen

    public function testCleanupRemovesOnlyUploadsNobodyTouchedInTime(): void
    {
        $dienst = $this->service();
        $alt = $dienst->create(8);
        $frisch = $dienst->create(8);
        $dienst->writeChunk($frisch->id, 0, self::stream('1234'));

        $gestern = time() - 25 * 3600;
        foreach (self::entries($this->dir . '/' . $alt->id) as $eintrag) {
            touch($this->dir . '/' . $alt->id . '/' . $eintrag, $gestern);
        }
        touch($this->dir . '/' . $alt->id, $gestern);

        self::assertSame(1, $dienst->cleanup(new \DateTimeImmutable('-24 hours')));
        self::assertDirectoryDoesNotExist($this->dir . '/' . $alt->id);
        self::assertDirectoryExists($this->dir . '/' . $frisch->id);
    }

    public function testAnUploadStillBeingWrittenToSurvivesTheCleanup(): void
    {
        $dienst = $this->service();
        $ticket = $dienst->create(8);

        // Opened yesterday, but a chunk arrived a moment ago: the newest
        // timestamp decides, so a slow upload is not swept away underneath
        // the browser.
        touch($this->dir . '/' . $ticket->id . '/meta.json', time() - 25 * 3600);
        $dienst->writeChunk($ticket->id, 0, self::stream('1234'));

        self::assertSame(0, $dienst->cleanup(new \DateTimeImmutable('-24 hours')));
        self::assertDirectoryExists($this->dir . '/' . $ticket->id);
    }

    public function testCleanupIgnoresWhatIsNotAnUpload(): void
    {
        touch($this->dir . '/fremde-datei', time() - 100 * 3600);
        mkdir($this->dir . '/kein-upload');
        touch($this->dir . '/kein-upload', time() - 100 * 3600);

        self::assertSame(0, $this->service()->cleanup(new \DateTimeImmutable('-24 hours')));
        self::assertFileExists($this->dir . '/fremde-datei');
        self::assertDirectoryExists($this->dir . '/kein-upload');
    }

    public function testCleanupOnAnEmptyInstallationDoesNothing(): void
    {
        $dienst = new UploadService($this->dir . '/gibt-es-nicht');

        self::assertSame(0, $dienst->cleanup(new \DateTimeImmutable('-24 hours')));
    }

    // ------------------------------------------------------------------ Hilfe

    private function service(): UploadService
    {
        return new UploadService($this->dir, self::CHUNK);
    }

    private function expectUploadError(UploadError $error): void
    {
        $this->expectException(UploadException::class);
        $this->expectExceptionMessage($error->value);
    }

    /**
     * @return resource
     */
    private static function stream(string $inhalt): mixed
    {
        $stream = fopen('php://temp', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, $inhalt);
        rewind($stream);

        return $stream;
    }

    /**
     * @param iterable<string> $stuecke
     */
    private static function collect(iterable $stuecke): string
    {
        $inhalt = '';
        foreach ($stuecke as $stueck) {
            $inhalt .= $stueck;
        }

        return $inhalt;
    }

    /**
     * @return list<string>
     */
    private static function entries(string $dir): array
    {
        $eintraege = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        sort($eintraege);

        return $eintraege;
    }

    /**
     * @return list<string>
     */
    private static function sortedKeys(string $json): array
    {
        $daten = json_decode($json, true);
        self::assertIsArray($daten);
        $keys = array_keys($daten);
        sort($keys);

        return $keys;
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
