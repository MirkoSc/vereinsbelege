<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Api\UploadController;
use App\App\ErfassungController;
use App\App\InboxController;
use App\Config\Paths;
use App\Domain\BlobMeta;
use App\Domain\BlobStorage;
use App\Domain\SystemRole;
use App\Http\Cookie;
use App\Http\HttpMethod;
use App\Http\Kernel;
use App\Http\LoginGuard;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Http\Session;
use App\Http\StaticFileHandler;
use App\Repository\AuditLogRepository;
use App\Repository\BlobRepository;
use App\Repository\CostCenterRepository;
use App\Repository\DocumentRepository;
use App\Repository\JobRepository;
use App\Repository\MailQueueRepository;
use App\Repository\RoleRepository;
use App\Repository\SettingRepository;
use App\Repository\SubmissionRepository;
use App\Repository\SubmissionUploadRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\Service\Account\SessionVault;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Inbox\Posteingang;
use App\Service\Mail\EinreichungBenachrichtigung;
use App\Service\Mail\Mailer;
use App\Service\Mail\MailSettingsRepository;
use App\Service\Mail\MailTemplates;
use App\Service\Migration\Migrator;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Service\Submission\InterneErfassung;
use App\Service\Upload\UploadService;
use App\Service\Upload\UploadStore;
use App\Tests\Support\DatabaseTestCase;
use App\View\View;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The internal capture `/app/belege/neu` end to end (issue #28/M4-6,
 * docs/spec/03-erfassung-und-ki.md sections 1 and 4): the real route table,
 * guard, upload controller, capture controller, service, repositories and
 * schema. Pages go through `/api/upload` with the page's capture id, the
 * pass is one JSON POST, and every receipt lands in the inbox as a sealed
 * submission with source `intern`.
 */
final class InterneErfassungTest extends DatabaseTestCase
{
    private const int CHUNK = 16;
    private const string ANZEIGENAME = 'Fritz Finanzen';

    private ServerCrypto $crypto;
    private RoleRepository $rollen;
    private Vault $tresor;
    private AuditLog $audit;
    private int $userId;
    private string $blobDir;
    private string $uploadDir;
    private string $erfassung;

    /** The body of the next chunk request (ChunkUploadTest's pattern). */
    private string $body = '';

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->blobDir = sys_get_temp_dir() . '/vb_erfassen_blobs_' . uniqid('', true);
        $this->uploadDir = sys_get_temp_dir() . '/vb_erfassen_tmp_' . uniqid('', true);
        mkdir($this->blobDir, 0775, true);
        mkdir($this->uploadDir, 0775, true);

        $this->crypto = new ServerCrypto((string) base64_decode(self::configData()['server_key'], true));
        $this->tresor = Vault::create();
        new VaultRepository($this->pdo())->insert($this->tresor->publicKey());
        $this->audit = new AuditLog(new AuditLogRepository($this->pdo()), new VaultRepository($this->pdo()), $this->crypto);

        $this->rollen = new RoleRepository($this->pdo());
        $this->userId = $this->konto('finanzen@example.org', self::ANZEIGENAME, SystemRole::Finanzen);
        $this->anmelden($this->userId);
        $this->erfassung = InterneErfassung::neueErfassungsId();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        self::removeDir($this->blobDir);
        self::removeDir($this->uploadDir);
        parent::tearDown();
    }

    // ------------------------------------------------------------- rights

    /**
     * `document.submit_internal`: Admin, Vorstand, Finanzen and the
     * Vereinsverantwortlicher - not the read-only external roles
     * (docs/spec/01-sicherheit.md section 4).
     *
     * @return iterable<string, array{SystemRole, bool}>
     */
    public static function rollen(): iterable
    {
        yield 'Admin' => [SystemRole::Admin, true];
        yield 'Vorstand' => [SystemRole::Vorstand, true];
        yield 'Finanzen' => [SystemRole::Finanzen, true];
        yield 'Kassenprüfer' => [SystemRole::Kassenpruefer, false];
        yield 'Steuerberater' => [SystemRole::Steuerberater, false];
        yield 'Vereinsverantwortlicher' => [SystemRole::Vereinsverantwortlicher, true];
    }

    #[DataProvider('rollen')]
    public function testOnlyDocumentSubmitInternalOpensThePage(SystemRole $rolle, bool $darf): void
    {
        $this->alsRolle($this->userId, $rolle);

        $seite = $this->get('/app/belege/neu');
        $absenden = $this->absenden([['blobs' => [1]]]);

        if ($darf) {
            self::assertSame(200, $seite->status);
            self::assertStringContainsString('Belege erfassen', $seite->body);
            self::assertStringContainsString('href="/app/belege/neu"', $seite->body, 'the navigation entry is live');
            self::assertNotSame(403, $absenden->status);
        } else {
            self::assertSame(403, $seite->status);
            self::assertSame(403, $absenden->status);
        }
    }

    public function testThePageHandsOutAFreshCaptureId(): void
    {
        preg_match('/data-erfassung="([0-9a-f]+)"/', $this->get('/app/belege/neu')->body, $eins);
        preg_match('/data-erfassung="([0-9a-f]+)"/', $this->get('/app/belege/neu')->body, $zwei);

        self::assertTrue(InterneErfassung::istErfassungsId($eins[1] ?? ''));
        self::assertNotSame($eins[1], $zwei[1] ?? null);
    }

    public function testTheSubmitNeedsTheCsrfToken(): void
    {
        $seite = $this->hochladen('%PDF-1.7 Rechnung');

        $antwort = $this->dispatch(new Request(
            HttpMethod::Post,
            '/app/belege/neu',
            post: ['erfassung' => $this->erfassung, 'belege' => [['blobs' => [$seite]]]],
        ));

        self::assertSame(403, $antwort->status);
        self::assertSame(0, $this->zaehle('document'));
    }

    // ------------------------------------------------------ the pass itself

    public function testSeveralReceiptsInOnePassBecomeSealedInboxEntries(): void
    {
        $kostenstelle = new CostCenterRepository($this->pdo())->create('E-Jugend');
        $seite1 = $this->hochladen('%PDF-1.7 Rechnung eins');
        $seite2 = $this->hochladen("\xFF\xD8\xFF\xE0 Kassenbon Seite 1");
        $seite3 = $this->hochladen("\xFF\xD8\xFF\xE0 Kassenbon Seite 2");
        $seite4 = $this->hochladen("\x89PNG\r\n\x1A\n Quittung");

        $daten = $this->json($this->absenden([
            ['blobs' => [$seite1]],
            ['blobs' => [$seite3, $seite2], 'freitext' => 'Getränke Sommerfest', 'kostenstelle' => (string) $kostenstelle],
            [
                'blobs' => [$seite4],
                'erstattung' => 'ueberweisung',
                'iban' => 'DE89 3704 0044 0532 0130 00',
                'kontoinhaber' => 'Erika Musterfrau',
            ],
        ]), 201);

        self::assertCount(3, $daten['referenzen']);
        foreach ($daten['referenzen'] as $referenz) {
            self::assertMatchesRegularExpression('/^R-\d{4}-\d{4}$/', $referenz);
        }
        self::assertCount(3, array_unique($daten['referenzen']));

        $dokumente = $this->pdo()->query(
            'SELECT d.*, s.reference_code FROM document d JOIN submission s ON s.id = d.submission_id ORDER BY d.id',
        )->fetchAll();
        self::assertCount(3, $dokumente);
        self::assertSame($daten['referenzen'], array_column($dokumente, 'reference_code'));
        foreach ($dokumente as $dokument) {
            self::assertSame('intern', $dokument['source']);
            self::assertSame('eingegangen', $dokument['status']);
            self::assertSame($this->userId, (int) $dokument['created_by']);
        }
        self::assertSame([$seite1], json_decode((string) $dokumente[0]['original_blob_ids'], true));
        self::assertSame([$seite3, $seite2], json_decode((string) $dokumente[1]['original_blob_ids'], true), 'page order as sent');
        self::assertNull($dokumente[0]['cost_center_id']);
        self::assertSame($kostenstelle, (int) $dokumente[1]['cost_center_id']);

        self::assertSame(['name' => self::ANZEIGENAME, 'freitext' => ''], $this->payload((int) $dokumente[0]['submission_id']));
        self::assertSame(
            ['name' => self::ANZEIGENAME, 'freitext' => 'Getränke Sommerfest', 'kostenstelle_hinweis' => $kostenstelle],
            $this->payload((int) $dokumente[1]['submission_id']),
        );
        self::assertSame(
            ['art' => 'ueberweisung', 'iban' => 'DE89370400440532013000', 'kontoinhaber' => 'Erika Musterfrau'],
            $this->payload((int) $dokumente[2]['submission_id'])['erstattung'],
        );

        // One PDF job per receipt, the pages claimed, every receipt audited.
        $jobs = $this->pdo()->query("SELECT ref_id FROM job WHERE typ = 'pdf_erzeugen' ORDER BY ref_id")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(array_map(intval(...), array_column($dokumente, 'id')), array_map(intval(...), $jobs));
        self::assertSame(0, $this->zaehle('submission_upload'));
        self::assertSame(
            ['beleg.erfasst', 'beleg.erfasst', 'beleg.erfasst'],
            $this->pdo()->query('SELECT action FROM audit_log ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN),
        );
        self::assertSame(
            [$this->userId],
            array_values(array_unique(array_map(intval(...), $this->pdo()->query('SELECT user_id FROM audit_log')->fetchAll(\PDO::FETCH_COLUMN)))),
        );
    }

    public function testARetriedPassGetsTheSameReferencesAndNoSecondRows(): void
    {
        $seite1 = $this->hochladen('%PDF-1.7 eins');
        $seite2 = $this->hochladen('%PDF-1.7 zwei');
        $belege = [['blobs' => [$seite1]], ['blobs' => [$seite2]]];

        $erstes = $this->json($this->absenden($belege), 201);
        $zweites = $this->json($this->absenden($belege), 201);

        self::assertSame($erstes['referenzen'], $zweites['referenzen']);
        self::assertSame(2, $this->zaehle('document'));
        self::assertSame(2, $this->zaehle('submission'));
    }

    public function testPagesRemovedBeforeSubmittingStayForTheCron(): void
    {
        $behalten = $this->hochladen('%PDF-1.7 behalten');
        $entfernt = $this->hochladen('%PDF-1.7 wieder entfernt');

        $this->json($this->absenden([['blobs' => [$behalten]]]), 201);

        self::assertSame(
            [$entfernt],
            new SubmissionUploadRepository($this->pdo())->blobIdsForFormHash(InterneErfassung::uploadHash($this->userId, $this->erfassung)),
        );
    }

    public function testReimbursementDetailsAreOptionalButCompleteWhenGiven(): void
    {
        $seite1 = $this->hochladen('%PDF-1.7 eins');
        $seite2 = $this->hochladen('%PDF-1.7 zwei');

        $fehler = $this->json($this->absenden([
            ['blobs' => [$seite1], 'erstattung' => 'bar'],
            ['blobs' => [$seite2], 'erstattung' => 'ueberweisung', 'iban' => 'DE00 1234', 'kontoinhaber' => ''],
        ]), 422)['fehler'];

        self::assertSame(['1.iban', '1.kontoinhaber'], array_keys($fehler));
        self::assertSame('Das ist keine gültige IBAN.', $fehler['1.iban']);
        self::assertSame(0, $this->zaehle('document'), 'all or nothing');
        self::assertSame(0, $this->zaehle('submission'));
    }

    public function testAnUnknownCostCenterAndATooLongTextAreFieldErrors(): void
    {
        $seite = $this->hochladen('%PDF-1.7 eins');

        $fehler = $this->json($this->absenden([
            ['blobs' => [$seite], 'kostenstelle' => '999', 'freitext' => str_repeat('x', 1001)],
        ]), 422)['fehler'];

        self::assertSame(['0.freitext', '0.kostenstelle'], array_keys($fehler));
    }

    public function testAReceiptWithoutPagesIsRefused(): void
    {
        $fehler = $this->json($this->absenden([['blobs' => []]]), 422)['fehler'];

        self::assertSame(['0.seiten' => 'Bitte mindestens eine Seite hinzufügen.'], $fehler);
    }

    public function testAnEmptyOrOversizedPassIsRefused(): void
    {
        self::assertArrayHasKey('belege', $this->json($this->absenden([]), 422)['fehler']);

        $zuViele = array_fill(0, InterneErfassung::MAX_BELEGE + 1, ['blobs' => [1]]);
        self::assertArrayHasKey('belege', $this->json($this->absenden($zuViele), 422)['fehler']);
    }

    public function testAPageInTwoReceiptsIsRefused(): void
    {
        $seite = $this->hochladen('%PDF-1.7 doppelt');

        $fehler = $this->json($this->absenden([['blobs' => [$seite]], ['blobs' => [$seite]]]), 422)['fehler'];

        self::assertSame(['1.seiten' => 'Eine Seite steckt in mehreren Belegen.'], $fehler);
    }

    // ----------------------------------------------- whose pages may be used

    public function testPagesOfAnotherPageLoadAreRefused(): void
    {
        $seite = $this->hochladen('%PDF-1.7 von gestern');
        $this->erfassung = InterneErfassung::neueErfassungsId();

        $fehler = $this->json($this->absenden([['blobs' => [$seite]]]), 422)['fehler'];

        self::assertSame(['0.seiten' => 'Eine Seite ist nicht mehr vorhanden. Bitte erneut hochladen.'], $fehler);
    }

    public function testPagesOfAnotherAccountAreRefused(): void
    {
        $seite = $this->hochladen('%PDF-1.7 fremd');

        $andere = $this->konto('vorstand@example.org', 'Vera Vorstand', SystemRole::Vorstand);
        $this->anmelden($andere);

        self::assertSame(422, $this->absenden([['blobs' => [$seite]]])->status);
        self::assertSame(0, $this->zaehle('document'));
    }

    public function testAPageOfThePublicSubmissionCannotBeClaimed(): void
    {
        $blob = $this->blobService()->storeString('%PDF-1.7 öffentlich', new BlobMeta('application/pdf', 'x.pdf'), $this->tresor, BlobStorage::Fs);
        new SubmissionUploadRepository($this->pdo())->record($blob->id, random_bytes(32), new \DateTimeImmutable());

        self::assertSame(422, $this->absenden([['blobs' => [$blob->id]]])->status);
    }

    public function testAClaimedPageCannotBeClaimedAgain(): void
    {
        $seite = $this->hochladen('%PDF-1.7 einmal');
        $this->json($this->absenden([['blobs' => [$seite]]]), 201);

        // A second receipt that also names the already claimed page.
        $neu = $this->hochladen('%PDF-1.7 neu');
        self::assertSame(422, $this->absenden([['blobs' => [$neu, $seite]]])->status);
        self::assertSame(1, $this->zaehle('document'));
    }

    // --------------------------------------------- confidentiality, inbox

    public function testNoPlaintextOfTheReceiptLeaksIntoAnyColumn(): void
    {
        $this->konto('admin@example.org', 'Anna Admin', SystemRole::Admin);
        $seite = $this->hochladen('%PDF-1.7 geheim');

        $this->json($this->absenden([[
            'blobs' => [$seite],
            'freitext' => 'Hoechst vertraulicher Verwendungszweck',
            'erstattung' => 'ueberweisung',
            'iban' => 'DE89370400440532013000',
            'kontoinhaber' => 'Geheime Kontoinhaberin',
        ]]), 201);

        foreach (['submission', 'document', 'job', 'audit_log', 'mail_queue', 'submission_upload'] as $tabelle) {
            $roh = $this->rohTabelle($tabelle);
            foreach (['Hoechst vertraulicher', 'DE89370400440532013000', 'Geheime Kontoinhaberin', self::ANZEIGENAME] as $geheim) {
                self::assertStringNotContainsString($geheim, $roh, $tabelle . ' carries plaintext');
            }
        }
    }

    public function testTheInboxIsNotifiedExceptTheCapturingAccount(): void
    {
        $this->konto('admin@example.org', 'Anna Admin', SystemRole::Admin);
        $seite1 = $this->hochladen('%PDF-1.7 eins');
        $seite2 = $this->hochladen('%PDF-1.7 zwei');

        $this->json($this->absenden([['blobs' => [$seite1]], ['blobs' => [$seite2]]]), 201);

        $empfaenger = array_map(static fn($mail): string => $mail->to, new MailQueueRepository($this->pdo(), $this->crypto)->recent());
        self::assertSame(['admin@example.org', 'admin@example.org'], $empfaenger, 'one notice per receipt, none to the capturer');
    }

    public function testTheInboxShowsTheCapturedReceipt(): void
    {
        $seite = $this->hochladen('%PDF-1.7 fürs Postfach');
        $referenz = $this->json($this->absenden([['blobs' => [$seite], 'freitext' => 'Trikotwäsche']]), 201)['referenzen'][0];
        $id = (int) $this->pdo()->query('SELECT id FROM document')->fetchColumn();

        $detail = $this->get('/app/posteingang/' . $id, entsperrt: true);

        self::assertSame(200, $detail->status);
        self::assertStringContainsString($referenz, $detail->body);
        self::assertStringContainsString(self::ANZEIGENAME, $detail->body);
        self::assertStringContainsString('Trikotwäsche', $detail->body);
    }

    // ------------------------------------------------------------- helpers

    private function konto(string $email, string $name, SystemRole $rolle): int
    {
        $id = new UserRepository($this->pdo())->insert(
            $this->crypto->encrypt($email),
            random_bytes(32),
            $this->crypto->encrypt($name),
            'hash',
            mfaRequired: false,
        );
        $this->alsRolle($id, $rolle);

        return $id;
    }

    private function alsRolle(int $userId, SystemRole $rolle): void
    {
        $id = $this->rollen->findSystem($rolle)?->id;
        assert($id !== null);
        $this->rollen->assignToUser($userId, [$id]);
    }

    private function anmelden(int $userId): void
    {
        new Session()->start();
        $jetzt = time();
        $_SESSION = ['user_id' => $userId, 'login_at' => $jetzt, 'last_seen_at' => $jetzt, 'session_epoch' => 0];
    }

    /**
     * One page through the real /api/upload routes, with the capture id.
     */
    private function hochladen(string $inhalt): int
    {
        $eroeffnet = $this->json($this->upload('/api/upload', ['groesse' => strlen($inhalt)]), 201);
        foreach (str_split($inhalt, self::CHUNK) as $index => $stueck) {
            $this->body = $stueck;
            $this->json($this->upload('/api/upload/' . $eroeffnet['id'] . '/chunk/' . $index, []), 200);
        }

        return (int) $this->json($this->upload('/api/upload/' . $eroeffnet['id'] . '/finish', ['name' => 'beleg']), 201)['blob_id'];
    }

    /**
     * @param array<string, mixed> $post
     */
    private function upload(string $pfad, array $post): Response
    {
        return $this->dispatch(new Request(
            HttpMethod::Post,
            $pfad,
            post: $post,
            headers: ['x-csrf-token' => new Session()->csrfToken(), 'x-erfassung' => $this->erfassung],
        ));
    }

    /**
     * @param list<array<string, mixed>> $belege
     */
    private function absenden(array $belege): Response
    {
        return $this->dispatch(new Request(
            HttpMethod::Post,
            '/app/belege/neu',
            post: ['erfassung' => $this->erfassung, 'belege' => $belege],
            headers: ['x-csrf-token' => new Session()->csrfToken()],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $submissionId): array
    {
        $stmt = $this->pdo()->prepare('SELECT dek_sealed, payload_enc FROM submission WHERE id = ?');
        $stmt->execute([$submissionId]);
        $zeile = $stmt->fetch();
        self::assertIsArray($zeile);

        $klartext = FieldCipher::decrypt(
            $this->tresor->openDataKey((string) $zeile['dek_sealed']),
            (string) $zeile['payload_enc'],
            new FieldContext('submission', $submissionId, 'payload_enc'),
        );
        $payload = json_decode($klartext, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }

    private function zaehle(string $tabelle): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM ' . $tabelle)->fetchColumn();
    }

    private function rohTabelle(string $tabelle): string
    {
        $roh = '';
        foreach ($this->pdo()->query('SELECT * FROM ' . $tabelle)->fetchAll() as $zeile) {
            foreach ($zeile as $wert) {
                if (is_string($wert)) {
                    $roh .= $wert . "\x00";
                }
            }
        }

        return $roh;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response, int $status): array
    {
        $daten = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($daten);
        self::assertSame($status, $response->status, $response->body);

        return $daten;
    }

    private function get(string $pfad, bool $entsperrt = false): Response
    {
        return $this->dispatch(new Request(
            HttpMethod::Get,
            $pfad,
            cookies: $entsperrt
                ? [Cookie::vaultKey('x', Request::httpsFromGlobals())->name => new SessionVault()->store($this->tresor)]
                : [],
        ));
    }

    private function dispatch(Request $request): Response
    {
        $antwort = $this->kernel()->handle($request);
        self::assertInstanceOf(Response::class, $antwort);

        return $antwort;
    }

    private function blobService(): BlobService
    {
        $repository = new BlobRepository($this->pdo());

        return new BlobService($repository, new DbBlobBackend($repository), new FsBlobBackend($this->blobDir));
    }

    private function kernel(): Kernel
    {
        $paths = new Paths(dirname(__DIR__, 2));
        $view = new View($paths->viewsDir(), '0.0.0-test');
        $pdo = $this->pdo();
        $mailer = new Mailer(
            new MailQueueRepository($pdo, $this->crypto),
            new MailSettingsRepository(new SettingRepository($pdo), $this->crypto),
            new MailTemplates(dirname(__DIR__, 2) . '/app/views/mail'),
        );

        $guard = fn(): LoginGuard => new LoginGuard(
            new Session(),
            $view,
            static fn(): SessionTimeouts => new SessionTimeouts(),
            function (int $id) use ($pdo): ?SessionUser {
                $user = new UserRepository($pdo)->findById($id);

                return $user === null
                    ? null
                    : new SessionUser($user, $this->crypto->decrypt($user->displayNameEnc), new UserAccessRepository($pdo)->berechtigungen($id));
            },
        );
        $uploads = fn(): UploadController => new UploadController(
            new Session(),
            new UploadService($this->uploadDir, self::CHUNK),
            fn(): UploadStore => new UploadStore($this->blobService(), new VaultRepository($pdo), new SettingRepository($pdo)),
            function (): mixed {
                $stream = fopen('php://temp', 'r+b');
                fwrite($stream, $this->body);
                rewind($stream);

                return $stream;
            },
            vermerke: static fn(): SubmissionUploadRepository => new SubmissionUploadRepository($pdo),
        );
        $posteingang = fn(): InboxController => new InboxController(
            $view,
            new Session(),
            new SessionVault(),
            new Posteingang(new DocumentRepository($pdo), new CostCenterRepository($pdo), $this->blobService(), $this->audit),
            new CostCenterRepository($pdo),
        );
        $erfassung = fn(): ErfassungController => new ErfassungController(
            $view,
            new Session(),
            new VaultRepository($pdo),
            new CostCenterRepository($pdo),
            new UserRepository($pdo),
            $this->crypto,
            new InterneErfassung(
                $pdo,
                new SubmissionRepository($pdo),
                new DocumentRepository($pdo),
                new SubmissionUploadRepository($pdo),
                new CostCenterRepository($pdo),
                $this->audit,
                new JobRepository($pdo),
                new EinreichungBenachrichtigung($mailer, $this->crypto, new UserRepository($pdo), new UserAccessRepository($pdo)),
            ),
        );
        $unerreichbar = static fn(): never => throw new \LogicException('Diese Route gehört nicht zu diesem Test.');

        $router = new Router();
        (require dirname(__DIR__, 2) . '/app/src/routes.php')(
            $router,
            $view,
            $unerreichbar,
            $guard,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $uploads,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $posteingang,
            $erfassung,
            $unerreichbar,
        );

        return new Kernel(
            router: $router,
            staticFiles: new StaticFileHandler($paths->publicDir(), longCache: false),
            view: $view,
            debug: true,
        );
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
