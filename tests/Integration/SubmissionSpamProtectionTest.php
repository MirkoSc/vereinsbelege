<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Api\EinreichungUploadController;
use App\Domain\BlobStorage;
use App\Http\HttpMethod;
use App\Http\Request;
use App\PublicPages\EinreichungController;
use App\Repository\AuditLogRepository;
use App\Repository\BlobRepository;
use App\Repository\CostCenterRepository;
use App\Repository\DocumentRepository;
use App\Repository\JobRepository;
use App\Repository\MailQueueRepository;
use App\Repository\RateLimitRepository;
use App\Repository\SettingRepository;
use App\Repository\SubmissionRepository;
use App\Repository\SubmissionUploadRepository;
use App\Repository\VaultRepository;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Mail\MailSettingsRepository;
use App\Service\Mail\MailTemplates;
use App\Service\Mail\Mailer;
use App\Service\Migration\Migrator;
use App\Service\RateLimiter;
use App\Service\Submission\EinreichungsEinstellungen;
use App\Service\Submission\FormToken;
use App\Service\Submission\FormTokenData;
use App\Service\Submission\ProofOfWork;
use App\Service\Submission\Spamschutz;
use App\Service\Submission\SubmissionService;
use App\Service\Submission\SubmissionUploadStore;
use App\Service\Upload\UploadService;
use App\Service\Upload\UploadStore;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Tests\Support\DatabaseTestCase;
use App\View\View;

/**
 * The public submission's spam defence end to end (docs/spec/
 * 01-sicherheit.md section 5, issue #25/M4-3, App\Service\Submission\
 * Spamschutz): rate limit, proof of work, honeypot, minimum fill time,
 * size limits and the pause switch. App\Tests\Integration\
 * PublicSubmissionTest covers the happy path and field validation; this
 * file covers everything that turns a request away before that.
 */
final class SubmissionSpamProtectionTest extends DatabaseTestCase
{
    private const string IP_A = '203.0.113.10';
    private const string IP_B = '203.0.113.20';

    private Vault $vault;
    private ServerCrypto $serverKey;
    private string $blobDir;
    private string $uploadDir;

    /** The body of the next chunk request (App\Tests\Integration\ChunkUploadTest's pattern). */
    private string $body = '';

    /** @var array<string, string> */
    private array $powLoesungen = [];

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->vault = Vault::create();
        new VaultRepository($this->pdo())->insert($this->vault->publicKey());
        $this->serverKey = new ServerCrypto(str_repeat('t', ServerCrypto::KEY_BYTES));

        $this->blobDir = sys_get_temp_dir() . '/vb_spamschutz_blobs_' . uniqid('', true);
        $this->uploadDir = sys_get_temp_dir() . '/vb_spamschutz_tmp_' . uniqid('', true);
        mkdir($this->blobDir, 0775, true);
        mkdir($this->uploadDir, 0775, true);
    }

    protected function tearDown(): void
    {
        self::removeDir($this->blobDir);
        self::removeDir($this->uploadDir);
        parent::tearDown();
    }

    public function testAPausedFormShowsOnlyANoticeAndRefusesEverything(): void
    {
        EinreichungsEinstellungen::setzePausiert($this->settings(), true);

        $seite = $this->einreichung()->formular(new Request(HttpMethod::Get, '/einreichen'));
        self::assertStringContainsString('vorübergehend nicht möglich', $seite->body);

        $token = $this->issuedToken();
        $antwort = $this->einreichung()->absenden($this->postRequest($token, $this->minimalAngaben([1]), self::IP_A));
        self::assertSame(503, $antwort->status);

        $upload = $this->uploadController()->create($this->uploadRequest($token, ['groesse' => 8], self::IP_A));
        self::assertSame(503, $upload->status);
    }

    public function testMissingProofOfWorkIsRejectedOnUploadAndSubmit(): void
    {
        $token = $this->issuedToken();

        $upload = $this->uploadController()->create(new Request(
            HttpMethod::Post,
            '/einreichen/upload',
            post: ['groesse' => 8],
            headers: ['x-csrf-token' => $token],
            ip: self::IP_A,
        ));
        self::assertSame(403, $upload->status);

        $antwort = $this->einreichung()->absenden(new Request(
            HttpMethod::Post,
            '/einreichen',
            post: $this->minimalAngaben([1]),
            headers: ['x-csrf-token' => $token],
            ip: self::IP_A,
        ));
        self::assertSame(403, $antwort->status);
    }

    public function testAWrongProofOfWorkSolutionIsRejected(): void
    {
        $token = $this->issuedToken();
        $falsch = (string) ((int) $this->powLoesung($token) + 1);

        $antwort = $this->einreichung()->absenden(new Request(
            HttpMethod::Post,
            '/einreichen',
            post: $this->minimalAngaben([1]),
            headers: ['x-csrf-token' => $token, 'x-pow-loesung' => $falsch],
            ip: self::IP_A,
        ));
        self::assertSame(403, $antwort->status);
    }

    public function testAFilledHoneypotIsRejectedAndCountsAgainstTheIpLimit(): void
    {
        $this->settings()->set(EinreichungsEinstellungen::SETTING_LIMIT_IP_STUNDE, '1');

        $botToken = $this->issuedToken();
        $angabenMitKoeder = $this->minimalAngaben([1]) + ['webseite' => 'https://bot.example'];
        $antwort = $this->einreichung()->absenden($this->postRequest($botToken, $angabenMitKoeder, self::IP_A));
        self::assertSame(422, $antwort->status);

        // The bot check already used up the one-per-hour IP budget - a
        // genuine attempt right after it is turned away too.
        $token = $this->issuedToken();
        $antwort2 = $this->einreichung()->absenden($this->postRequest($token, $this->minimalAngaben([1]), self::IP_A));
        self::assertSame(429, $antwort2->status);
    }

    public function testASubmissionWithinSecondsOfTheTokenIsRejected(): void
    {
        $frisch = new FormToken(str_repeat('t', 32))->ausstellen(new \DateTimeImmutable());

        $antwort = $this->einreichung()->absenden($this->postRequest($frisch, $this->minimalAngaben([1]), self::IP_A));
        self::assertSame(422, $antwort->status);
    }

    public function testAFieldErrorDoesNotConsumeTheRateLimit(): void
    {
        $this->settings()->set(EinreichungsEinstellungen::SETTING_LIMIT_IP_STUNDE, '1');

        $tokenLeer = $this->issuedToken();
        $leer = $this->minimalAngaben([1]);
        $leer['name'] = '';
        $fehlerAntwort = $this->einreichung()->absenden($this->postRequest($tokenLeer, $leer, self::IP_A));
        self::assertSame(422, $fehlerAntwort->status);

        // The field error above must not have cost the one-per-hour budget.
        $token = $this->issuedToken();
        $seite = $this->hochladen($token, '%PDF-1.7 x', self::IP_A);
        $antwort = $this->absenden($token, $this->minimalAngaben([$seite]), self::IP_A);
        self::assertSame(201, $antwort->status);
    }

    public function testTheIpRateLimitBlocksAfterTheConfiguredNumberOfSubmissions(): void
    {
        $this->settings()->set(EinreichungsEinstellungen::SETTING_LIMIT_IP_STUNDE, '1');

        $token1 = $this->issuedToken();
        $seite1 = $this->hochladen($token1, '%PDF-1.7 eins', self::IP_A);
        self::assertSame(201, $this->absenden($token1, $this->minimalAngaben([$seite1]), self::IP_A)->status);

        $token2 = $this->issuedToken();
        $seite2 = $this->hochladen($token2, '%PDF-1.7 zwei', self::IP_A);
        self::assertSame(429, $this->absenden($token2, $this->minimalAngaben([$seite2]), self::IP_A)->status);

        // A different IP has its own budget.
        $token3 = $this->issuedToken();
        $seite3 = $this->hochladen($token3, '%PDF-1.7 drei', self::IP_B);
        self::assertSame(201, $this->absenden($token3, $this->minimalAngaben([$seite3]), self::IP_B)->status);
    }

    public function testTheGlobalRateLimitBlocksAcrossDifferentIps(): void
    {
        $this->settings()->set(EinreichungsEinstellungen::SETTING_LIMIT_GESAMT_STUNDE, '1');

        $token1 = $this->issuedToken();
        $seite1 = $this->hochladen($token1, '%PDF-1.7 eins', self::IP_A);
        self::assertSame(201, $this->absenden($token1, $this->minimalAngaben([$seite1]), self::IP_A)->status);

        $token2 = $this->issuedToken();
        $seite2 = $this->hochladen($token2, '%PDF-1.7 zwei', self::IP_B);
        self::assertSame(429, $this->absenden($token2, $this->minimalAngaben([$seite2]), self::IP_B)->status);
    }

    public function testTheUploadBudgetScalesWithTheMaxPagesSetting(): void
    {
        $this->settings()->set(EinreichungsEinstellungen::SETTING_LIMIT_IP_STUNDE, '1');
        $this->settings()->set(EinreichungsEinstellungen::SETTING_MAX_SEITEN, '2');

        $token = $this->issuedToken();
        // Budget is 1 * 2 = 2 uploads for this IP.
        self::assertSame(201, $this->uploadCreate($token, 8, self::IP_A)->status);
        self::assertSame(201, $this->uploadCreate($token, 8, self::IP_A)->status);
        self::assertSame(429, $this->uploadCreate($token, 8, self::IP_A)->status);
    }

    public function testAFileOverTheConfiguredSizeIsRejectedBeforeAnyByteIsSent(): void
    {
        $this->settings()->set(EinreichungsEinstellungen::SETTING_MAX_DATEI_MB, '1');

        $token = $this->issuedToken();
        $antwort = $this->uploadCreate($token, 2 * 1024 * 1024, self::IP_A);

        self::assertSame(413, $antwort->status);
    }

    public function testASubmissionOverTheTotalSizeLimitIsRefusedAsAFieldError(): void
    {
        $this->settings()->set(EinreichungsEinstellungen::SETTING_MAX_EINREICHUNG_MB, '1');

        $token = $this->issuedToken();
        $tokenData = new FormToken(str_repeat('t', 32))->pruefen($token);
        self::assertNotNull($tokenData);

        // Two blobs whose declared size alone exceeds the 1 MB limit - no
        // real bytes need to move for this rule, only the `size` column.
        $blobs = new BlobRepository($this->pdo());
        $uploads = new SubmissionUploadRepository($this->pdo());
        $seiten = [];
        foreach ([700_000, 700_000] as $groesse) {
            $id = $blobs->insertDraft(BlobStorage::Db, null, 'x', 'x');
            $blobs->complete($id, $groesse, str_repeat('a', 32), null);
            $uploads->record($id, $tokenData->hash(), new \DateTimeImmutable());
            $seiten[] = $id;
        }

        $antwort = $this->absenden($token, $this->minimalAngaben($seiten), self::IP_A);
        $daten = json_decode($antwort->body, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(422, $antwort->status);
        self::assertArrayHasKey('seiten', $daten['fehler']);
    }

    // ------------------------------------------------------------------ Hilfe

    private function settings(): SettingRepository
    {
        return new SettingRepository($this->pdo());
    }

    private function formToken(): FormToken
    {
        return new FormToken(str_repeat('t', 32));
    }

    private function proofOfWork(): ProofOfWork
    {
        return new ProofOfWork(str_repeat('t', 32));
    }

    private function einstellungen(): EinreichungsEinstellungen
    {
        return EinreichungsEinstellungen::fromSettings($this->settings());
    }

    private function spamschutz(): Spamschutz
    {
        return new Spamschutz(
            new RateLimiter(new RateLimitRepository($this->pdo()), RateLimiter::SUBMISSION_WINDOW_SECONDS),
            $this->proofOfWork(),
            $this->einstellungen(),
        );
    }

    /** Issued 10 s in the past - see App\Tests\Integration\PublicSubmissionTest::issuedToken(). */
    private function issuedToken(): string
    {
        return $this->formToken()->ausstellen(new \DateTimeImmutable('-10 seconds'));
    }

    private function powLoesung(string $token): string
    {
        if (isset($this->powLoesungen[$token])) {
            return $this->powLoesungen[$token];
        }

        $tokenData = $this->formToken()->pruefen($token);
        self::assertInstanceOf(FormTokenData::class, $tokenData);

        $challenge = $this->proofOfWork()->challenge($tokenData);
        for ($zahl = 0; $zahl <= $challenge['max']; $zahl++) {
            if (hash('sha256', $challenge['salt'] . $zahl) === $challenge['challenge']) {
                return $this->powLoesungen[$token] = (string) $zahl;
            }
        }

        self::fail('No proof-of-work solution found for the test token.');
    }

    /**
     * @param list<int> $blobIds
     * @return array<string, mixed>
     */
    private function minimalAngaben(array $blobIds): array
    {
        return [
            'blobs' => $blobIds,
            'name' => 'Max Muster',
            'erstattung' => 'keine',
            'freitext' => 'Sportgeräte',
            'datenschutz' => true,
        ];
    }

    private function postRequest(string $token, array $post, string $ip): Request
    {
        return new Request(HttpMethod::Post, '/einreichen', post: $post, headers: [
            'x-csrf-token' => $token,
            'x-pow-loesung' => $this->powLoesung($token),
        ], ip: $ip);
    }

    private function uploadRequest(string $token, array $post, string $ip): Request
    {
        return new Request(HttpMethod::Post, '/einreichen/upload', post: $post, headers: [
            'x-csrf-token' => $token,
            'x-pow-loesung' => $this->powLoesung($token),
        ], ip: $ip);
    }

    private function absenden(string $token, array $angaben, string $ip): \App\Http\Response
    {
        return $this->einreichung()->absenden($this->postRequest($token, $angaben, $ip));
    }

    private function uploadCreate(string $token, int $groesse, string $ip): \App\Http\Response
    {
        return $this->uploadController()->create($this->uploadRequest($token, ['groesse' => $groesse], $ip));
    }

    /** Opens, chunks and finishes one small page; returns the blob id. */
    private function hochladen(string $token, string $inhalt, string $ip): int
    {
        $controller = $this->uploadController();
        $offen = $this->json($controller->create($this->uploadRequest($token, ['groesse' => strlen($inhalt)], $ip)));
        self::assertSame(200, $controller->chunk(
            $this->chunkRequest($token, $ip, $inhalt),
            ['id' => $offen['id'], 'n' => '0'],
        )->status);
        $antwort = $this->json($controller->finish($this->uploadRequest($token, ['name' => 'seite.bin'], $ip), ['id' => $offen['id']]));

        return (int) $antwort['blob_id'];
    }

    private function chunkRequest(string $token, string $ip, string $body): Request
    {
        $this->body = $body;

        return $this->uploadRequest($token, [], $ip);
    }

    private function uploadController(): EinreichungUploadController
    {
        return new EinreichungUploadController(
            $this->formToken(),
            new UploadService($this->uploadDir, 4096),
            fn(): Spamschutz => $this->spamschutz(),
            fn(): SubmissionUploadStore => new SubmissionUploadStore(
                new UploadStore($this->blobs(), new VaultRepository($this->pdo()), new SettingRepository($this->pdo())),
                new SubmissionUploadRepository($this->pdo()),
            ),
            function (): mixed {
                $stream = fopen('php://temp', 'r+b');
                fwrite($stream, $this->body);
                rewind($stream);

                return $stream;
            },
        );
    }

    private function einreichung(): EinreichungController
    {
        $pdo = $this->pdo();
        $kostenstellen = new CostCenterRepository($pdo);
        $mailer = new Mailer(
            new MailQueueRepository($pdo, $this->serverKey),
            new MailSettingsRepository(new SettingRepository($pdo), $this->serverKey),
            new MailTemplates(dirname(__DIR__, 2) . '/app/views/mail'),
        );
        $audit = new AuditLog(new AuditLogRepository($pdo), new VaultRepository($pdo), $this->serverKey);
        $einstellungen = $this->einstellungen();

        return new EinreichungController(
            new View(dirname(__DIR__, 2) . '/app/views', '0.0.0-test'),
            $this->formToken(),
            $this->proofOfWork(),
            new VaultRepository($pdo),
            $kostenstellen,
            new SubmissionService(
                $pdo,
                new SubmissionRepository($pdo),
                new DocumentRepository($pdo),
                new SubmissionUploadRepository($pdo),
                new BlobRepository($pdo),
                $kostenstellen,
                $mailer,
                $audit,
                $einstellungen,
                new JobRepository($pdo),
            ),
            $this->spamschutz(),
            $einstellungen,
        );
    }

    private function blobs(): BlobService
    {
        $repository = new BlobRepository($this->pdo());

        return new BlobService($repository, new DbBlobBackend($repository), new FsBlobBackend($this->blobDir));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(\App\Http\Response $response): array
    {
        $daten = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($daten);
        self::assertSame(201, $response->status, (string) json_encode($daten));

        return $daten;
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
