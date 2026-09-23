<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Api\EinreichungUploadController;
use App\Domain\AuditAction;
use App\Domain\BlobStorage;
use App\Domain\SystemRole;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Response;
use App\PublicPages\EinreichungController;
use App\Repository\AuditLogRepository;
use App\Repository\BlobRepository;
use App\Repository\CostCenterRepository;
use App\Repository\DocumentRepository;
use App\Repository\JobRepository;
use App\Repository\MailQueueRepository;
use App\Repository\RateLimitRepository;
use App\Repository\RoleRepository;
use App\Repository\SettingRepository;
use App\Repository\SubmissionRepository;
use App\Repository\SubmissionUploadRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Mail\EinreichungBenachrichtigung;
use App\Service\Mail\MailSettingsRepository;
use App\Service\Mail\MailTemplates;
use App\Service\Mail\Mailer;
use App\Service\Migration\Migrator;
use App\Service\RateLimiter;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Service\Submission\EinreichungsEinstellungen;
use App\Service\Submission\FormToken;
use App\Service\Submission\ProofOfWork;
use App\Service\Submission\Spamschutz;
use App\Service\Submission\SubmissionService;
use App\Service\Submission\SubmissionUploadStore;
use App\Service\Upload\UploadService;
use App\Service\Upload\UploadStore;
use App\Tests\Support\DatabaseTestCase;
use App\View\View;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The public submission end to end (docs/spec/03-erfassung-und-ki.md section
 * 1, issue #24/M4-2): open the page, upload pages under the form token it
 * hands out, submit, and what lands in the database is a sealed payload only
 * an unlocked vault can read - nothing in the clear, no session anywhere.
 */
final class PublicSubmissionTest extends DatabaseTestCase
{
    private const int CHUNK = 8;

    private string $blobDir;
    private string $uploadDir;
    private Vault $vault;
    private ServerCrypto $serverKey;

    /** The body of the next chunk request (App\Tests\Integration\ChunkUploadTest's pattern). */
    private string $body = '';

    /**
     * Proof-of-work solutions are deterministic per token (issue #25/M4-3) -
     * brute-forced once per token and reused for every request under it,
     * the same as the real client solving it once per page load.
     *
     * @var array<string, string>
     */
    private array $powLoesungen = [];

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->blobDir = sys_get_temp_dir() . '/vb_einreichen_blobs_' . uniqid('', true);
        $this->uploadDir = sys_get_temp_dir() . '/vb_einreichen_tmp_' . uniqid('', true);
        mkdir($this->blobDir, 0775, true);
        mkdir($this->uploadDir, 0775, true);

        $this->vault = Vault::create();
        new VaultRepository($this->pdo())->insert($this->vault->publicKey());
        $this->serverKey = new ServerCrypto(str_repeat('t', ServerCrypto::KEY_BYTES));
    }

    protected function tearDown(): void
    {
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

    public function testTheFormRenders(): void
    {
        $seite = $this->einreichung()->formular(new Request(HttpMethod::Get, '/einreichen'));

        self::assertSame(200, $seite->status);
        self::assertStringContainsString('Beleg einreichen', $seite->body);
    }

    #[DataProvider('backends')]
    public function testAFullSubmissionIsSealedAndGetsAReference(BlobStorage $storage): void
    {
        new SettingRepository($this->pdo())->set(BlobService::SETTING_BACKEND, $storage->value);
        $token = $this->issuedToken();

        $seite1 = $this->hochladen($token, '%PDF-1.7 Seite eins');
        $seite2 = $this->hochladen($token, "\xFF\xD8\xFF\xE0 Seite zwei");

        $antwort = $this->absenden($token, [
            'blobs' => [$seite1, $seite2],
            'name' => 'Erika Musterfrau',
            'email' => 'erika@example.test',
            'erstattung' => 'ueberweisung',
            'iban' => 'DE89 3704 0044 0532 0130 00',
            'kontoinhaber' => 'Erika Musterfrau',
            'freitext' => 'Getränke Sommerfest E-Jugend',
            'datenschutz' => true,
        ]);
        $daten = $this->json($antwort, 201);
        self::assertMatchesRegularExpression('/^R-\d{4}-\d{4}$/', $daten['referenz']);

        $row = $this->pdo()->query('SELECT * FROM submission')->fetch();
        self::assertNotFalse($row);
        self::assertSame($daten['referenz'], $row['reference_code']);

        $key = $this->vault->openDataKey($row['dek_sealed']);
        $payload = json_decode(
            FieldCipher::decrypt($key, $row['payload_enc'], new FieldContext('submission', (int) $row['id'], 'payload_enc')),
            true,
        );
        self::assertSame('Erika Musterfrau', $payload['name']);
        self::assertSame('erika@example.test', $payload['email']);
        self::assertSame('Getränke Sommerfest E-Jugend', $payload['freitext']);
        self::assertSame('ueberweisung', $payload['erstattung']['art']);
        self::assertSame('DE89370400440532013000', $payload['erstattung']['iban']);
        self::assertSame('Erika Musterfrau', $payload['erstattung']['kontoinhaber']);

        $dokument = $this->pdo()->query('SELECT * FROM document')->fetch();
        self::assertNotFalse($dokument);
        self::assertSame([$seite1, $seite2], json_decode((string) $dokument['original_blob_ids'], true));
        self::assertSame('einreichung', $dokument['source']);
        self::assertSame((int) $row['id'], (int) $dokument['submission_id']);

        // The blobs are claimed - no leftover submission_upload rows.
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM submission_upload')->fetchColumn());
        self::assertSame([], self::entries($this->uploadDir), 'the plaintext chunks are gone');
    }

    public function testSubmittingQueuesExactlyOnePdfGenerationJob(): void
    {
        $token = $this->issuedToken();
        $seite = $this->hochladen($token, "\xFF\xD8\xFF\xE0 Seite");
        $this->json($this->absenden($token, $this->minimalAngaben([$seite])), 201);

        $dokument = $this->pdo()->query('SELECT id FROM document')->fetch();
        self::assertNotFalse($dokument);

        $jobs = $this->pdo()->query('SELECT * FROM job')->fetchAll();
        self::assertCount(1, $jobs);
        self::assertSame('pdf_erzeugen', $jobs[0]['typ']);
        self::assertSame('session', $jobs[0]['executor']);
        self::assertSame('document', $jobs[0]['ref_type']);
        self::assertSame((int) $dokument['id'], (int) $jobs[0]['ref_id']);
        self::assertSame('offen', $jobs[0]['status']);
    }

    public function testNoPlaintextOfTheFormLeaksIntoAnyColumn(): void
    {
        $token = $this->issuedToken();
        $seite = $this->hochladen($token, '%PDF-1.7 geheim');

        $this->json($this->absenden($token, [
            'blobs' => [$seite],
            'name' => 'Ganz Geheimer Name',
            'email' => 'geheim@example.test',
            'erstattung' => 'bar',
            'freitext' => 'Hoechst vertraulicher Verwendungszweck',
            'datenschutz' => true,
        ]), 201);

        foreach (['submission', 'document', 'file_blob', 'mail_queue', 'audit_log'] as $tabelle) {
            // Raw byte search, not JSON: several columns here are ciphertext
            // and not valid UTF-8, which json_encode() cannot be trusted with.
            $roh = '';
            foreach ($this->pdo()->query('SELECT * FROM ' . $tabelle)->fetchAll() as $zeile) {
                foreach ($zeile as $wert) {
                    if (is_string($wert)) {
                        $roh .= $wert . "\x00";
                    }
                }
            }

            self::assertStringNotContainsString('Ganz Geheimer Name', $roh, $tabelle);
            self::assertStringNotContainsString('geheim@example.test', $roh, $tabelle);
            self::assertStringNotContainsString('vertraulicher Verwendungszweck', $roh, $tabelle);
        }
    }

    public function testReferenceNumbersOfTheSameYearIncrement(): void
    {
        $token1 = $this->issuedToken();
        $seite1 = $this->hochladen($token1, '%PDF-1.7 eins');
        $ref1 = $this->json($this->absenden($token1, $this->minimalAngaben([$seite1])), 201)['referenz'];

        $token2 = $this->issuedToken();
        $seite2 = $this->hochladen($token2, '%PDF-1.7 zwei');
        $ref2 = $this->json($this->absenden($token2, $this->minimalAngaben([$seite2])), 201)['referenz'];

        self::assertNotSame($ref1, $ref2);
        [, $jahr1, $lfd1] = explode('-', $ref1);
        [, $jahr2, $lfd2] = explode('-', $ref2);
        self::assertSame($jahr1, $jahr2);
        self::assertSame((int) $lfd1 + 1, (int) $lfd2);
    }

    public function testRetryingWithTheSameTokenReturnsTheSameReferenceAndWritesOneRow(): void
    {
        $token = $this->issuedToken();
        $seite = $this->hochladen($token, '%PDF-1.7 x');
        $angaben = $this->minimalAngaben([$seite]);

        $erste = $this->json($this->absenden($token, $angaben), 201);
        $zweite = $this->json($this->absenden($token, $angaben), 201);

        self::assertSame($erste['referenz'], $zweite['referenz']);
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM submission')->fetchColumn());
    }

    public function testTheConfirmationMailCarriesOnlyTheReference(): void
    {
        $token = $this->issuedToken();
        $seite = $this->hochladen($token, '%PDF-1.7 mail');
        $angaben = $this->minimalAngaben([$seite]);
        $angaben['email'] = 'empfaenger@example.test';
        $angaben['freitext'] = 'Streng geheimer Verwendungszweck';

        $referenz = $this->json($this->absenden($token, $angaben), 201)['referenz'];

        $mails = new MailQueueRepository($this->pdo(), $this->serverKey)->recent();
        self::assertCount(1, $mails);
        self::assertSame('empfaenger@example.test', $mails[0]->to);
        self::assertStringContainsString($referenz, $mails[0]->subject . ' ' . $mails[0]->body);
        self::assertStringNotContainsString('Streng geheimer Verwendungszweck', $mails[0]->subject . ' ' . $mails[0]->body);
    }

    /**
     * Issue #27/M4-5: the chosen team becomes the document's cost center
     * (plaintext, for the inbox filter and scope), and everyone with
     * `document.edit` gets a notice with nothing but the reference.
     */
    public function testASubmissionCarriesItsCostCenterAndNotifiesTheInbox(): void
    {
        $kostenstelle = new CostCenterRepository($this->pdo())->create('E-Jugend');
        $rollen = new RoleRepository($this->pdo());
        $finanzen = new UserRepository($this->pdo())->insert(
            $this->serverKey->encrypt('kasse@example.test'),
            random_bytes(32),
            $this->serverKey->encrypt('Kasse'),
            'hash',
            mfaRequired: false,
        );
        $rollen->assignToUser($finanzen, [(int) $rollen->findSystem(SystemRole::Finanzen)?->id]);

        $token = $this->issuedToken();
        $seite = $this->hochladen($token, '%PDF-1.7 kostenstelle');
        $angaben = $this->minimalAngaben([$seite]);
        $angaben['kostenstelle'] = (string) $kostenstelle;
        $angaben['name'] = 'Ganz Geheimer Name';
        $angaben['freitext'] = 'Streng geheimer Verwendungszweck';

        $referenz = $this->json($this->absenden($token, $angaben), 201)['referenz'];

        $dokument = $this->pdo()->query('SELECT id, cost_center_id FROM document')->fetch();
        self::assertNotFalse($dokument);
        self::assertSame($kostenstelle, (int) $dokument['cost_center_id']);

        $mails = new MailQueueRepository($this->pdo(), $this->serverKey)->recent();
        self::assertCount(1, $mails);
        self::assertSame('kasse@example.test', $mails[0]->to);
        self::assertStringContainsString($referenz, $mails[0]->subject);
        foreach (['Ganz Geheimer Name', 'Streng geheimer Verwendungszweck', 'E-Jugend'] as $geheim) {
            self::assertStringNotContainsString($geheim, $mails[0]->subject . ' ' . $mails[0]->body);
        }
    }

    public function testNoEmailMeansNoMail(): void
    {
        $token = $this->issuedToken();
        $seite = $this->hochladen($token, '%PDF-1.7 ohne mail');
        $this->json($this->absenden($token, $this->minimalAngaben([$seite])), 201);

        self::assertSame([], new MailQueueRepository($this->pdo(), $this->serverKey)->recent());
    }

    public function testASubmissionIsAuditedWithoutAnActingUser(): void
    {
        $token = $this->issuedToken();
        $seite = $this->hochladen($token, '%PDF-1.7 audit');
        $this->json($this->absenden($token, $this->minimalAngaben([$seite])), 201);

        $eintrag = $this->pdo()->query('SELECT * FROM audit_log')->fetch();
        self::assertNotFalse($eintrag);
        self::assertSame(AuditAction::EinreichungEingegangen->value, $eintrag['action']);
        self::assertSame('document', $eintrag['entity']);
        self::assertNull($eintrag['user_id']);
    }

    public function testMissingRequiredFieldsAreReportedPerField(): void
    {
        $token = $this->issuedToken();
        $seite = $this->hochladen($token, '%PDF-1.7 leer');

        $daten = $this->json($this->absenden($token, [
            'blobs' => [$seite],
            'name' => '',
            'erstattung' => 'keine',
            'freitext' => '',
            'datenschutz' => false,
        ]), 422);

        self::assertArrayHasKey('name', $daten['fehler']);
        self::assertArrayHasKey('freitext', $daten['fehler']);
        self::assertArrayHasKey('datenschutz', $daten['fehler']);
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM submission')->fetchColumn());
    }

    public function testAnInvalidIbanIsRejectedWhenTransferWasChosen(): void
    {
        $token = $this->issuedToken();
        $seite = $this->hochladen($token, '%PDF-1.7 iban');
        $angaben = $this->minimalAngaben([$seite]);
        $angaben['erstattung'] = 'ueberweisung';
        $angaben['iban'] = 'DE00000000000000000000';
        $angaben['kontoinhaber'] = 'Erika Musterfrau';

        $daten = $this->json($this->absenden($token, $angaben), 422);
        self::assertArrayHasKey('iban', $daten['fehler']);
    }

    public function testABlobUploadedUnderAnotherTokenIsRefused(): void
    {
        $fremderToken = $this->issuedToken();
        $fremdeSeite = $this->hochladen($fremderToken, '%PDF-1.7 fremd');

        $eigenerToken = $this->issuedToken();
        $daten = $this->json($this->absenden($eigenerToken, $this->minimalAngaben([$fremdeSeite])), 422);

        self::assertArrayHasKey('seiten', $daten['fehler']);
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM submission')->fetchColumn());
    }

    public function testMoreThanTheLimitOfPagesIsRefused(): void
    {
        $token = $this->issuedToken();
        $viele = range(1, EinreichungsEinstellungen::MAX_SEITEN_DEFAULT + 1);

        $daten = $this->json($this->absenden($token, $this->minimalAngaben($viele)), 422);
        self::assertArrayHasKey('seiten', $daten['fehler']);
    }

    public function testAManipulatedTokenIsRefusedOnEveryRoute(): void
    {
        $verfaelscht = 'nicht-echt';

        self::assertSame(403, $this->einreichung()->absenden($this->postRequest($verfaelscht, []))->status);
        self::assertSame(403, $this->uploadController()->create($this->uploadRequest($verfaelscht, ['groesse' => 8]))->status);
    }

    public function testWithoutAVaultTheFormWarnsAndSubmittingRefuses(): void
    {
        $this->pdo()->exec('DELETE FROM vault');

        $seite = $this->einreichung()->formular(new Request(HttpMethod::Get, '/einreichen'));
        self::assertStringContainsString('noch nicht eingerichtet', $seite->body);

        $token = $this->issuedToken();
        $antwort = $this->einreichung()->absenden($this->postRequest($token, $this->minimalAngaben([1])));
        self::assertSame(503, $antwort->status);
    }

    // ------------------------------------------------------------------ Hilfe

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
        return EinreichungsEinstellungen::fromSettings(new SettingRepository($this->pdo()));
    }

    private function spamschutz(): Spamschutz
    {
        return new Spamschutz(
            new RateLimiter(new RateLimitRepository($this->pdo()), RateLimiter::SUBMISSION_WINDOW_SECONDS),
            $this->proofOfWork(),
            $this->einstellungen(),
        );
    }

    /**
     * Issued 10 s in the past: real enough for App\Service\Submission\
     * FormToken (24 h TTL), old enough that the minimum-fill-time check
     * (5 s, App\Service\Submission\Spamschutz) never mistakes an ordinary
     * test for a bot.
     */
    private function issuedToken(): string
    {
        return $this->formToken()->ausstellen(new \DateTimeImmutable('-10 seconds'));
    }

    /**
     * The proof-of-work solution belonging to this token - brute-forced the
     * same way public/js/einreichen.js does, cached per token. An invalid
     * token (the "manipulated token" test) has no challenge to solve; the
     * empty string it gets back is refused at the token check anyway,
     * before anything looks at the header.
     */
    private function powLoesung(string $token): string
    {
        if (isset($this->powLoesungen[$token])) {
            return $this->powLoesungen[$token];
        }

        $tokenData = $this->formToken()->pruefen($token);
        if ($tokenData === null) {
            return '';
        }

        $challenge = $this->proofOfWork()->challenge($tokenData);
        for ($zahl = 0; $zahl <= $challenge['max']; $zahl++) {
            if (hash('sha256', $challenge['salt'] . $zahl) === $challenge['challenge']) {
                return $this->powLoesungen[$token] = (string) $zahl;
            }
        }

        self::fail('No proof-of-work solution found for the test token.');
    }

    /**
     * Opens, chunks and finishes one page under the given token; returns the
     * blob id.
     */
    private function hochladen(string $token, string $inhalt): int
    {
        $controller = $this->uploadController();

        $offen = $this->json($controller->create($this->uploadRequest($token, ['groesse' => strlen($inhalt)])), 201);
        foreach (str_split($inhalt, self::CHUNK) as $index => $stueck) {
            self::assertSame(200, $controller->chunk($this->uploadRequest($token, [], $stueck), ['id' => $offen['id'], 'n' => (string) $index])->status);
        }
        $antwort = $this->json($controller->finish($this->uploadRequest($token, ['name' => 'seite.bin']), ['id' => $offen['id']]), 201);

        return (int) $antwort['blob_id'];
    }

    private function absenden(string $token, array $angaben): Response
    {
        return $this->einreichung()->absenden($this->postRequest($token, $angaben));
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

    private function postRequest(string $token, array $post): Request
    {
        return new Request(HttpMethod::Post, '/einreichen', post: $post, headers: [
            'x-csrf-token' => $token,
            'x-pow-loesung' => $this->powLoesung($token),
        ]);
    }

    /**
     * @param array<string, mixed> $post
     */
    private function uploadRequest(string $token, array $post, string $body = ''): Request
    {
        $this->body = $body;

        return new Request(HttpMethod::Post, '/einreichen/upload', post: $post, headers: [
            'x-csrf-token' => $token,
            'x-pow-loesung' => $this->powLoesung($token),
        ]);
    }

    private function uploadController(): EinreichungUploadController
    {
        return new EinreichungUploadController(
            $this->formToken(),
            new UploadService($this->uploadDir, self::CHUNK),
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
                new EinreichungBenachrichtigung($mailer, $this->serverKey, new UserRepository($pdo), new UserAccessRepository($pdo)),
            ),
            $this->spamschutz(),
            $einstellungen,
            new MailSettingsRepository(new SettingRepository($pdo), $this->serverKey),
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
    private function json(Response $response, int $status): array
    {
        $daten = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($daten);
        self::assertSame($status, $response->status, (string) json_encode($daten));

        return $daten;
    }

    /**
     * @return list<string>
     */
    private static function entries(string $dir): array
    {
        return array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
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
