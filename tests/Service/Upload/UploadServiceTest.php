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
        self::assertSame($inhalt, self::lesen($dienst, $ticket->id));
    }

    public function testResendingAChunkOverwritesIt(): void
    {
        $dienst = $this->service();
        $ticket = $dienst->create(8);

        $dienst->writeChunk($ticket->id, 0, self::stream('XXXX'));
        $dienst->writeChunk($ticket->id, 1, self::stream('5678'));
        $dienst->writeChunk($ticket->id, 0, self::stream('1234'));

        self::assertSame('12345678', self::lesen($dienst, $ticket->id));
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
        self::lesen($dienst, $ticket->id);
    }

    // ------------------------------------------------------------------ Rest

    public function testTheHeadOfTheFileComesFromTheFirstChunk(): void
    {
        $dienst = $this->service();
        $ticket = $dienst->create(8);
        $dienst->writeChunk($ticket->id, 0, self::stream('%PDF'));
        $dienst->writeChunk($ticket->id, 1, self::stream('-1.7'));

        $offen = $dienst->oeffnen($ticket->id);
        self::assertSame('%P', $offen->kopf(2));
        self::assertSame('%PDF-1', $offen->kopf(6), 'across the chunk boundary');
        self::assertSame('%PDF-1.7', self::collect($offen->chunks()), 'the head is handed out once, not twice');
        $offen->schliessen();
    }

    // ------------------------------------------------ TOCTOU (issue #242, N-13)

    /**
     * A chunk sent again after the file was opened - between the magic byte
     * check and the blob - must not change what is stored: the bytes that
     * were checked are the bytes that come out.
     */
    public function testAChunkResentAfterOpeningChangesNeitherTheCheckNorTheStoredBytes(): void
    {
        $dienst = $this->service();
        $ticket = $dienst->create(8);
        $dienst->writeChunk($ticket->id, 0, self::stream('%PDF'));
        $dienst->writeChunk($ticket->id, 1, self::stream('-1.7'));

        $offen = $dienst->oeffnen($ticket->id);
        self::assertSame('%PDF', $offen->kopf(4));

        $dienst->writeChunk($ticket->id, 0, self::stream('<svg'));
        $dienst->writeChunk($ticket->id, 1, self::stream(' on>'));

        self::assertSame('%PDF-1.7', self::collect($offen->chunks()));
        $offen->schliessen();
    }

    public function testAChunkOfTheWrongLengthCannotBeOpened(): void
    {
        $dienst = $this->service();
        $ticket = $dienst->create(8);
        $dienst->writeChunk($ticket->id, 0, self::stream('1234'));
        $dienst->writeChunk($ticket->id, 1, self::stream('56'));

        $this->expectUploadError(UploadError::Incomplete);
        $dienst->oeffnen($ticket->id);
    }

    public function testAnOpenedUploadIsReadOnce(): void
    {
        $dienst = $this->service();
        $ticket = $dienst->create(4);
        $dienst->writeChunk($ticket->id, 0, self::stream('1234'));
        $offen = $dienst->oeffnen($ticket->id);
        self::collect($offen->chunks());

        $this->expectException(\LogicException::class);
        self::collect($offen->chunks());
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

        self::assertSame(1, $dienst->cleanup(self::hoursAgo(24)));
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

        self::assertSame(0, $dienst->cleanup(self::hoursAgo(24)));
        self::assertDirectoryExists($this->dir . '/' . $ticket->id);
    }

    public function testCleanupIgnoresWhatIsNotAnUpload(): void
    {
        touch($this->dir . '/fremde-datei', time() - 100 * 3600);
        mkdir($this->dir . '/kein-upload');
        touch($this->dir . '/kein-upload', time() - 100 * 3600);

        self::assertSame(0, $this->service()->cleanup(self::hoursAgo(24)));
        self::assertFileExists($this->dir . '/fremde-datei');
        self::assertDirectoryExists($this->dir . '/kein-upload');
    }

    public function testCleanupOnAnEmptyInstallationDoesNothing(): void
    {
        $dienst = new UploadService($this->dir . '/gibt-es-nicht');

        self::assertSame(0, $dienst->cleanup(self::hoursAgo(24)));
    }

    // ------------------------------------------- Archiv-Import (#77, M12-3)

    public function testALargerLimitIsTheArchiveImportsAlone(): void
    {
        $archiv = new UploadService($this->dir, self::CHUNK, UploadService::MAX_FILE_BYTES * 10);

        self::assertSame(UploadService::MAX_FILE_BYTES + 1, $archiv->create(UploadService::MAX_FILE_BYTES + 1)->groesse);
        $this->expectUploadError(UploadError::TooLarge);
        $this->service()->create(UploadService::MAX_FILE_BYTES + 1);
    }

    public function testItReadsAnyRangeAcrossChunkBoundaries(): void
    {
        $dienst = $this->service();
        $inhalt = 'abcdefghijklmnopqrstuvw';
        $ticket = $dienst->create(strlen($inhalt));
        foreach (str_split($inhalt, self::CHUNK) as $i => $stueck) {
            $dienst->writeChunk($ticket->id, $i, self::stream($stueck));
        }

        self::assertSame(strlen($inhalt), $dienst->groesse($ticket->id));
        foreach ([[0, 4], [3, 2], [3, 10], [7, 1], [20, 100], [0, 23]] as [$offset, $laenge]) {
            self::assertSame(substr($inhalt, $offset, $laenge), $dienst->lesen($ticket->id, $offset, $laenge), $offset . '+' . $laenge);
        }
        self::assertSame('', $dienst->lesen($ticket->id, 23, 5), 'nothing after the end');
        self::assertSame('', $dienst->lesen($ticket->id, 3, 0));
    }

    public function testReadingAMissingChunkFails(): void
    {
        $dienst = $this->service();
        $ticket = $dienst->create(8);
        $dienst->writeChunk($ticket->id, 0, self::stream('abcd'));

        $this->expectUploadError(UploadError::Incomplete);
        $dienst->lesen($ticket->id, 2, 4);
    }

    public function testTouchingKeepsAnUploadFromTheCleanup(): void
    {
        $dienst = $this->service();
        $ticket = $dienst->create(4);
        $dienst->writeChunk($ticket->id, 0, self::stream('abcd'));
        foreach (['meta.json', '0.part'] as $datei) {
            touch($this->dir . '/' . $ticket->id . '/' . $datei, time() - 2 * 86400);
        }
        touch($this->dir . '/' . $ticket->id, time() - 2 * 86400);

        $dienst->beruehren($ticket->id);

        self::assertSame(0, $dienst->cleanup(self::hoursAgo(24)));
        self::assertTrue($dienst->vorhanden($ticket->id));
        $dienst->discard($ticket->id);
        self::assertFalse($dienst->vorhanden($ticket->id));
        $dienst->beruehren($ticket->id);
        self::assertFalse($dienst->vorhanden($ticket->id), 'touching never brings one back');
        self::assertFalse($dienst->vorhanden('../etwas'));
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
    private static function lesen(UploadService $dienst, string $id): string
    {
        $offen = $dienst->oeffnen($id);
        try {
            return self::collect($offen->chunks());
        } finally {
            $offen->schliessen();
        }
    }

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

    /** Real hours, not wall-clock hours - those differ across a clock change. */
    private static function hoursAgo(int $stunden): \DateTimeImmutable
    {
        return new \DateTimeImmutable()->setTimestamp(time() - $stunden * 3600);
    }
}
