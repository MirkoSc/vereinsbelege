<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Api\UploadController;
use App\Domain\BlobStorage;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Repository\BlobRepository;
use App\Repository\SettingRepository;
use App\Repository\SubmissionUploadRepository;
use App\Repository\VaultRepository;
use App\Service\Crypto\Vault;
use App\Service\Migration\Migrator;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Service\Submission\InterneErfassung;
use App\Service\Upload\UploadService;
use App\Service\Upload\UploadStore;
use App\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The whole way a receipt takes: open the upload, send the chunks, close it -
 * and what comes out the other end is an encrypted blob that only an unlocked
 * vault can read back (docs/spec/03-erfassung-und-ki.md section 4).
 *
 * Against a real database and both storage backends, because the point of the
 * closing request is that it hands the chunks straight to the storage layer.
 */
final class ChunkUploadTest extends DatabaseTestCase
{
    private const int CHUNK = 4;

    private string $blobDir;

    private string $uploadDir;

    private Vault $vault;

    private Session $session;

    /** The body of the next chunk request. */
    private string $body = '';

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->blobDir = sys_get_temp_dir() . '/vb_upload_blobs_' . uniqid('', true);
        $this->uploadDir = sys_get_temp_dir() . '/vb_upload_tmp_' . uniqid('', true);
        mkdir($this->blobDir, 0775, true);
        mkdir($this->uploadDir, 0775, true);

        $this->vault = Vault::create();
        new VaultRepository($this->pdo())->insert($this->vault->publicKey());

        $this->session = new Session();
        $this->session->start();
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id']);
        self::removeDir($this->blobDir);
        self::removeDir($this->uploadDir);
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
    public function testAFileArrivesInPiecesAndLeavesAsAnEncryptedBlob(BlobStorage $storage): void
    {
        new SettingRepository($this->pdo())->set(BlobService::SETTING_BACKEND, $storage->value);
        $inhalt = '%PDF-1.7' . "\n" . 'Beleg vom Sommerfest' . "\n%%EOF\n";

        $upload = $this->open(strlen($inhalt));
        // Backwards on purpose: the order the chunks arrive in must not
        // matter, only the index they carry.
        foreach (array_reverse(self::pieces($inhalt)) as $index => $stueck) {
            self::assertSame(200, $this->chunk($upload['id'], $stueck['index'], $stueck['daten'])->status);
        }

        $antwort = $this->json($this->finish($upload['id'], 'C:\\Users\\Kasse\\Rechnung Getränke.pdf'), 201);
        self::assertSame('application/pdf', $antwort['typ']);
        self::assertSame(strlen($inhalt), $antwort['groesse']);

        $blob = $this->blobs()->find((int) $antwort['blob_id']);
        self::assertNotNull($blob);
        self::assertSame($storage, $blob->storage);
        self::assertSame($inhalt, $this->read($blob->id));

        // The file name is business data: encrypted in the blob metadata,
        // and stripped of the path the browser sent along.
        $meta = $this->blobs()->meta($blob, $this->vault);
        self::assertSame('Rechnung Getränke.pdf', $meta?->originalName);
        self::assertSame('application/pdf', $meta?->mimeType);

        self::assertSame([], self::entries($this->uploadDir), 'the plaintext chunks are gone');
    }

    public function testASingleChunkFileWorksToo(): void
    {
        $inhalt = "\xFF\xD8\xFF\xE0";

        $upload = $this->open(strlen($inhalt));
        $this->chunk($upload['id'], 0, $inhalt);
        $antwort = $this->json($this->finish($upload['id'], 'foto.jpg'), 201);

        self::assertSame('image/jpeg', $antwort['typ']);
        self::assertSame($inhalt, $this->read((int) $antwort['blob_id']));
    }

    public function testTheChunkAnswerNamesWhatIsStillMissing(): void
    {
        $upload = $this->open(12);

        $antwort = $this->json($this->chunk($upload['id'], 0, '%PDF'), 200);
        self::assertSame([1, 2], $antwort['fehlend']);
        self::assertFalse($antwort['vollstaendig']);

        $this->chunk($upload['id'], 1, '-1.7');
        $antwort = $this->json($this->chunk($upload['id'], 2, "\n%%E"), 200);
        self::assertSame([], $antwort['fehlend']);
        self::assertTrue($antwort['vollstaendig']);
    }

    public function testClosingAnIncompleteUploadIsRefusedAndKeepsWhatIsThere(): void
    {
        $upload = $this->open(12);
        $this->chunk($upload['id'], 0, '%PDF');
        $this->chunk($upload['id'], 2, "\n%%E");

        $antwort = $this->json($this->finish($upload['id'], 'beleg.pdf'), 409);
        self::assertSame([1], $antwort['fehlend']);
        self::assertSame(0, $this->countBlobs(), 'nothing half written reaches the storage');
        self::assertNotSame([], self::entries($this->uploadDir), 'the chunks stay so the browser can retry');
    }

    public function testAFileThatIsNoReceiptIsRefusedByItsFirstBytes(): void
    {
        $zip = "PK\x03\x04Backup";

        $upload = $this->open(strlen($zip));
        foreach (self::pieces($zip) as $stueck) {
            $this->chunk($upload['id'], $stueck['index'], $stueck['daten']);
        }

        $antwort = $this->json($this->finish($upload['id'], 'backup.zip'), 415);
        self::assertSame('Nur JPEG, PNG und PDF sind möglich.', $antwort['fehler']);
        self::assertSame(0, $this->countBlobs());
        self::assertSame([], self::entries($this->uploadDir), 'a refused upload is not left for the cron');
    }

    public function testAnAnnouncedSizeAboveTheLimitNeverOpensAnUpload(): void
    {
        $antwort = $this->json(
            $this->controller()->create($this->request(['groesse' => UploadService::MAX_FILE_BYTES + 1])),
            413,
        );

        self::assertSame('Die Datei ist zu groß.', $antwort['fehler']);
        self::assertSame([], self::entries($this->uploadDir));
    }

    public function testAnOversizedChunkIsRefused(): void
    {
        $upload = $this->open(8);

        $antwort = $this->json($this->chunk($upload['id'], 0, 'viel zu lang'), 413);
        self::assertSame('Der Abschnitt ist zu groß.', $antwort['fehler']);
    }

    public function testAnUnknownUploadIsNotFound(): void
    {
        $id = str_repeat('a', 32);

        self::assertSame(404, $this->chunk($id, 0, 'abcd')->status);
        self::assertSame(404, $this->finish($id, 'beleg.pdf')->status);
    }

    public function testWithoutAVaultNothingIsStored(): void
    {
        $this->pdo()->exec('DELETE FROM vault');
        $inhalt = '%PDF-1.7';

        $upload = $this->open(strlen($inhalt));
        foreach (self::pieces($inhalt) as $stueck) {
            $this->chunk($upload['id'], $stueck['index'], $stueck['daten']);
        }

        $antwort = $this->json($this->finish($upload['id'], 'beleg.pdf'), 503);
        self::assertSame('Der Tresor ist noch nicht eingerichtet.', $antwort['fehler']);
        self::assertSame(0, $this->countBlobs());
    }

    public function testEveryRouteRefusesARequestWithoutACsrfToken(): void
    {
        $ohneToken = new Request(HttpMethod::Post, '/api/upload', post: ['groesse' => 8]);
        $id = str_repeat('a', 32);

        foreach ([
            $this->controller()->create($ohneToken),
            $this->controller()->chunk($ohneToken, ['id' => $id, 'n' => '0']),
            $this->controller()->finish($ohneToken, ['id' => $id]),
            $this->controller()->abort($ohneToken, ['id' => $id]),
        ] as $antwort) {
            self::assertSame(403, $antwort->status);
        }
    }

    public function testAbortGivesTheChunksBackRightAway(): void
    {
        $upload = $this->open(8);
        $this->chunk($upload['id'], 0, '%PDF');

        self::assertSame(200, $this->controller()->abort($this->request([]), ['id' => $upload['id']])->status);
        self::assertSame([], self::entries($this->uploadDir));

        // The browser may have sent abort twice, or the cron was faster.
        self::assertSame(200, $this->controller()->abort($this->request([]), ['id' => $upload['id']])->status);
    }

    /**
     * The internal capture (issue #28/M4-6) sends its capture id along: the
     * finished blob is recorded for exactly this account and page load.
     */
    public function testACaptureIdRecordsTheBlobForAccountAndPageLoad(): void
    {
        $_SESSION['user_id'] = 7;
        $erfassung = str_repeat('ab', 16);

        $upload = $this->open(4);
        $this->chunk($upload['id'], 0, "\xFF\xD8\xFF\xE0");
        $antwort = $this->json($this->finish($upload['id'], 'foto.jpg', $erfassung), 201);

        $vermerke = new SubmissionUploadRepository($this->pdo());
        self::assertSame([(int) $antwort['blob_id']], $vermerke->blobIdsForFormHash(InterneErfassung::uploadHash(7, $erfassung)));
        self::assertSame([], $vermerke->blobIdsForFormHash(InterneErfassung::uploadHash(8, $erfassung)), 'another account');
        self::assertSame([], $vermerke->blobIdsForFormHash(InterneErfassung::uploadHash(7, str_repeat('cd', 16))), 'another page load');
    }

    public function testAMalformedCaptureIdIsRefusedBeforeAnythingIsStored(): void
    {
        $_SESSION['user_id'] = 7;

        $upload = $this->open(4);
        $this->chunk($upload['id'], 0, "\xFF\xD8\xFF\xE0");
        $this->json($this->finish($upload['id'], 'foto.jpg', '../../etc'), 422);

        self::assertSame(0, $this->countBlobs());
    }

    public function testWithoutACaptureIdNothingIsRecorded(): void
    {
        $upload = $this->open(4);
        $this->chunk($upload['id'], 0, "\xFF\xD8\xFF\xE0");
        $this->json($this->finish($upload['id'], 'foto.jpg'), 201);

        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM submission_upload')?->fetchColumn());
    }

    // ------------------------------------------------------------------ Hilfe

    /**
     * @return array<string, mixed>
     */
    private function open(int $groesse): array
    {
        return $this->json($this->controller()->create($this->request(['groesse' => $groesse])), 201);
    }

    private function chunk(string $id, int $index, string $daten): Response
    {
        $this->body = $daten;

        return $this->controller()->chunk($this->request([]), ['id' => $id, 'n' => (string) $index]);
    }

    private function finish(string $id, string $name, ?string $erfassung = null): Response
    {
        return $this->controller()->finish($this->request(['name' => $name], $erfassung), ['id' => $id]);
    }

    private function controller(): UploadController
    {
        return new UploadController(
            $this->session,
            new UploadService($this->uploadDir, self::CHUNK),
            fn(): UploadStore => new UploadStore(
                $this->blobs(),
                new VaultRepository($this->pdo()),
                new SettingRepository($this->pdo()),
            ),
            function (): mixed {
                $stream = fopen('php://temp', 'r+b');
                fwrite($stream, $this->body);
                rewind($stream);

                return $stream;
            },
            vermerke: fn(): SubmissionUploadRepository => new SubmissionUploadRepository($this->pdo()),
        );
    }

    private function blobs(): BlobService
    {
        $repository = new BlobRepository($this->pdo());

        return new BlobService($repository, new DbBlobBackend($repository), new FsBlobBackend($this->blobDir));
    }

    /**
     * @param array<string, mixed> $post
     */
    private function request(array $post, ?string $erfassung = null): Request
    {
        $headers = ['x-csrf-token' => $this->session->csrfToken()];
        if ($erfassung !== null) {
            $headers['x-erfassung'] = $erfassung;
        }

        return new Request(HttpMethod::Post, '/api/upload', post: $post, headers: $headers);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response, int $status): array
    {
        $daten = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($daten);
        self::assertSame($status, $response->status, (string) ($daten['fehler'] ?? ''));

        return $daten;
    }

    private function read(int $blobId): string
    {
        $blob = $this->blobs()->find($blobId);
        self::assertNotNull($blob);

        $inhalt = '';
        foreach ($this->blobs()->openRead($blob, $this->vault) as $stueck) {
            $inhalt .= $stueck;
        }

        return $inhalt;
    }

    private function countBlobs(): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM file_blob')?->fetchColumn();
    }

    /**
     * @return list<array{index: int, daten: string}>
     */
    private static function pieces(string $inhalt): array
    {
        $stuecke = [];
        foreach (str_split($inhalt, self::CHUNK) as $index => $daten) {
            $stuecke[] = ['index' => $index, 'daten' => $daten];
        }

        return $stuecke;
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
