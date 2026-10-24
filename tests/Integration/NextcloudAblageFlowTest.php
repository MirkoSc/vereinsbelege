<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Admin\NextcloudSettingsController;
use App\App\ExportController;
use App\Config\Paths;
use App\Domain\AuditAction;
use App\Domain\BlobMeta;
use App\Domain\BlobStorage;
use App\Domain\DocumentSource;
use App\Domain\DocumentStatus;
use App\Domain\ExportRunStatus;
use App\Domain\InvoiceData;
use App\Domain\InvoiceDirection;
use App\Domain\InvoiceStructure;
use App\Domain\InvoiceType;
use App\Domain\NextcloudAblageStatus;
use App\Domain\SupplierRole;
use App\Domain\SystemRole;
use App\Http\Cookie;
use App\Http\HttpMethod;
use App\Http\Kernel;
use App\Http\LoginGuard;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Router;
use App\Http\Session;
use App\Http\StaticFileHandler;
use App\Repository\AuditLogRepository;
use App\Repository\BlobRepository;
use App\Repository\CategoryRepository;
use App\Repository\CostCenterRepository;
use App\Repository\DocumentRepository;
use App\Repository\ExportRunRepository;
use App\Repository\ExportRunUploadRepository;
use App\Repository\InvoiceRepository;
use App\Repository\NextcloudTargetRepository;
use App\Repository\RoleRepository;
use App\Repository\SettingRepository;
use App\Repository\SupplierRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\Service\Account\SessionVault;
use App\Service\Audit\AuditFilter;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Export\Nextcloud\NextcloudAblage;
use App\Service\Export\Nextcloud\NextcloudZielSpeicher;
use App\Service\Export\ZipExport;
use App\Service\MasterData\SupplierService;
use App\Service\Migration\Migrator;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Service\Upload\MagicBytes;
use App\Support\FileLogger;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FakeWebDav;
use App\View\View;

/**
 * The Nextcloud filing of an export run end to end (issue #95,
 * docs/spec/05-auswertung-und-export.md section 2 "Nextcloud-Ablage"): the
 * real route table, guard, controllers, services, repositories, blob store
 * and schema, against a WebDAV folder rebuilt in memory
 * (App\Tests\Support\FakeWebDav). All names are made up; the real club
 * Nextcloud is a manual check (see the PR).
 */
final class NextcloudAblageFlowTest extends DatabaseTestCase
{
    private const string IP = '198.51.100.95';

    private const string MARKER = 'KLARTEXT-95c1d0';

    private const array ZEITRAUM = ['von' => '2025-01-01', 'bis' => '2025-12-31'];

    private const string URL = 'https://cloud.example.org/remote.php/dav/files/kasse/Belege';

    private const string PASSWORT = 'app-pass-1234';

    /** Files per step in this test - small, so the chain takes several. */
    private const int JE_SCHRITT = 2;

    private ServerCrypto $crypto;
    private Vault $tresor;
    private AuditLog $audit;
    private FakeWebDav $dav;
    private int $userId;
    private string $wurzel;
    private int $laufnummer = 0;

    /** @var array<string, int> */
    private array $stamm = [];

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->wurzel = sys_get_temp_dir() . '/vb_nextcloud_' . uniqid('', true);
        mkdir($this->blobDir(), 0775, true);
        mkdir($this->tmpDir(), 0775, true);

        $this->crypto = new ServerCrypto((string) base64_decode(self::configData()['server_key'], true));
        $this->tresor = Vault::create();
        new VaultRepository($this->pdo())->insert($this->tresor->publicKey());
        $this->audit = new AuditLog(new AuditLogRepository($this->pdo()), new VaultRepository($this->pdo()), $this->crypto);
        $this->dav = new FakeWebDav();

        $this->userId = new UserRepository($this->pdo())->insert(
            $this->crypto->encrypt('admin@example.org'),
            random_bytes(32),
            $this->crypto->encrypt('Ada Admin'),
            'hash',
            mfaRequired: false,
        );
        $this->rolle(SystemRole::Admin);

        $lieferanten = new SupplierService($this->pdo(), new SupplierRepository($this->pdo()), new CategoryRepository($this->pdo()));
        $this->stamm = [
            'bauhaus' => $lieferanten->anlegen($this->tresor, ['name' => 'Bauhaus', 'rolle' => SupplierRole::Lieferant->value], new \DateTimeImmutable())->id,
        ];

        new Session()->start();
        $jetzt = time();
        $_SESSION = ['user_id' => $this->userId, 'login_at' => $jetzt, 'last_seen_at' => $jetzt, 'session_epoch' => 0];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        foreach (array_reverse(self::dateien($this->wurzel)) as $eintrag) {
            $pfad = $this->wurzel . '/' . $eintrag;
            is_dir($pfad) ? rmdir($pfad) : unlink($pfad);
        }
        rmdir($this->wurzel);
        parent::tearDown();
    }

    // ------------------------------------------------------ the target

    public function testTheTargetLivesInTheVaultAndThePasswordNeverReturns(): void
    {
        $this->einrichten();

        $zeile = $this->pdo()->query('SELECT * FROM nextcloud_target')->fetch();
        self::assertIsArray($zeile);
        foreach (['cloud.example.org', 'kasse', self::PASSWORT] as $klartext) {
            self::assertStringNotContainsString($klartext, (string) $zeile['data_enc']);
        }
        $ziel = new NextcloudZielSpeicher(new NextcloudTargetRepository($this->pdo()))->lesen($this->tresor);
        self::assertSame(self::URL, $ziel?->url);
        self::assertSame(self::PASSWORT, $ziel->passwort);

        $seite = $this->get('/admin/export/nextcloud');
        self::assertSame(200, $seite->status);
        self::assertStringContainsString('value="' . self::URL . '"', $seite->body);
        self::assertStringNotContainsString(self::PASSWORT, $seite->body);

        $gesperrt = $this->get('/admin/export/nextcloud', entsperrt: false);
        self::assertStringNotContainsString('cloud.example.org', $gesperrt->body, 'without the vault the address stays hidden');
        self::assertStringContainsString('eingerichtet', $gesperrt->body);

        // An empty password field keeps the stored one.
        $this->post('/admin/export/nextcloud', ['url' => self::URL . '/', 'benutzer' => 'kasse', 'passwort' => '']);
        self::assertSame(self::PASSWORT, new NextcloudZielSpeicher(new NextcloudTargetRepository($this->pdo()))->lesen($this->tresor)?->passwort);

        $audits = $this->audits(AuditAction::EinstellungNextcloud);
        self::assertCount(2, $audits);
        $details = json_encode(array_map(fn(\App\Domain\AuditEntry $e): mixed => $this->auditDetails($e), $audits), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('cloud.example.org', $details);
        self::assertStringNotContainsString('kasse', $details);
    }

    public function testTheFormRefusesPlainHttpAndCredentialsInTheAddress(): void
    {
        foreach (['http://cloud.example.org/dav', 'https://kasse:geheim@cloud.example.org/dav', 'https://cloud.example.org/dav?x=1', 'ftp://cloud.example.org'] as $url) {
            $antwort = $this->post('/admin/export/nextcloud', ['url' => $url, 'benutzer' => 'kasse', 'passwort' => self::PASSWORT]);
            self::assertInstanceOf(Response::class, $antwort);
            self::assertSame(422, $antwort->status, $url);
        }
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM nextcloud_target')->fetchColumn());
    }

    public function testTheConnectionTestReportsLoginAndFolder(): void
    {
        $this->einrichten();

        $this->post('/admin/export/nextcloud/testen', []);
        self::assertStringContainsString('Verbindung in Ordnung', $this->get('/admin/export/nextcloud')->body);

        $this->dav->passwort = 'anderes';
        $this->post('/admin/export/nextcloud/testen', []);
        self::assertStringContainsString('Anmeldung abgelehnt', $this->get('/admin/export/nextcloud')->body);
    }

    public function testRemovingTheTargetIsAudited(): void
    {
        $this->einrichten();
        $this->post('/admin/export/nextcloud/entfernen', []);

        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM nextcloud_target')->fetchColumn());
        self::assertCount(2, $this->audits(AuditAction::EinstellungNextcloud));
    }

    // ------------------------------------------------------ the filing

    /**
     * The same paths and contents as the ZIP, index.csv last, in several
     * short steps; audited once, with counts only. No plaintext is left in
     * var/tmp.
     */
    public function testTheRunIsFiledWithTheZipsPathsAndTheIndexLast(): void
    {
        $this->einrichten();
        $belege = $this->belege();
        $lauf = $this->laufAnlegen();

        $this->starten($lauf);
        $schritte = $this->bisFertig($lauf);
        self::assertGreaterThan(1, $schritte, 'several short requests');

        $erwartet = [
            'Belege_2025/Bauhaus/2025/02. Februar/Bauhaus 03.02.2025.pdf',
            'Belege_2025/Bauhaus/2025/05. Mai/Bauhaus 17.05.2025.pdf',
            'Belege_2025/Bauhaus/2025/05. Mai/Bauhaus 17.05.2025 (2).pdf',
            'Belege_2025/index.csv',
        ];
        self::assertSame($erwartet, $this->dav->hochgeladen());
        self::assertSame(self::pdfText($belege[0]), $this->dav->dateien[$erwartet[0]]['inhalt']);
        self::assertSame(self::pdfText($belege[2]), $this->dav->dateien[$erwartet[2]]['inhalt']);
        self::assertStringStartsWith("\u{FEFF}Datum;Richtung;Lieferant;", $this->dav->dateien['Belege_2025/index.csv']['inhalt']);
        self::assertStringContainsString(';Bauhaus/2025/05. Mai/Bauhaus 17.05.2025 (2).pdf', $this->dav->dateien['Belege_2025/index.csv']['inhalt']);
        self::assertSame(sha1(self::pdfText($belege[0])), $this->dav->dateien[$erwartet[0]]['sha1'], 'uploaded with its checksum');

        $stand = new ExportRunUploadRepository($this->pdo())->find($lauf);
        self::assertSame(NextcloudAblageStatus::Fertig, $stand?->status);
        self::assertSame([4, 4, 4, 0, 0], [$stand->nextIndex, $stand->total, $stand->hochgeladen, $stand->vorhanden, $stand->umbenannt]);

        $audits = $this->audits(AuditAction::ExportNextcloud);
        self::assertCount(1, $audits);
        self::assertSame($lauf, $audits[0]->entityId);
        self::assertSame(['belege' => 3, 'dateien' => 4, 'hochgeladen' => 4, 'vorhanden' => 0, 'umbenannt' => 0], $this->auditDetails($audits[0]));

        // A further step after the end changes nothing and audits nothing.
        self::assertSame('fertig', $this->schritt($lauf)['status']);
        self::assertCount(1, $this->audits(AuditAction::ExportNextcloud));

        self::assertSame(ExportRunStatus::Offen, new ExportRunRepository($this->pdo())->find($lauf)?->status, 'filing marks nothing - confirming stays the user\'s');
        self::assertSame([], self::dateien($this->tmpDir()), 'no temp file');

        $seite = $this->get('/app/export/lauf/' . $lauf)->body;
        self::assertStringContainsString('4 Dateien hochgeladen', $seite);
        self::assertStringContainsString('Erneut ablegen', $seite);
        self::assertStringNotContainsString('Bauhaus', $seite, 'structure only on this page');
    }

    /** Filing again uploads nothing: every file is recognised by its checksum. */
    public function testASecondFilingSkipsWhatIsThere(): void
    {
        $this->einrichten();
        $this->belege();
        $lauf = $this->laufAnlegen();
        $this->starten($lauf);
        $this->bisFertig($lauf);
        $this->dav->aufrufe = [];

        $this->starten($lauf);
        $this->bisFertig($lauf);

        self::assertSame([], $this->dav->hochgeladen());
        $stand = new ExportRunUploadRepository($this->pdo())->find($lauf);
        self::assertSame([0, 4, 0], [$stand?->hochgeladen, $stand?->vorhanden, $stand?->umbenannt]);
        self::assertCount(2, $this->audits(AuditAction::ExportNextcloud));
    }

    /**
     * A different file with the planned name is never overwritten - ours
     * gets the next " (n)". A file put there by hand with the same content
     * (no checksum, same size) counts as there.
     */
    public function testAForeignFileWithTheSameNameIsNeverOverwritten(): void
    {
        $this->einrichten();
        $belege = $this->belege();
        $fremd = 'Belege_2025/Bauhaus/2025/02. Februar/Bauhaus 03.02.2025.pdf';
        $this->dav->legen($fremd, 'ein ganz anderer Beleg');
        $vonHand = 'Belege_2025/Bauhaus/2025/05. Mai/Bauhaus 17.05.2025.pdf';
        $this->dav->legen($vonHand, self::pdfText($belege[1]));
        $lauf = $this->laufAnlegen();

        $this->starten($lauf);
        $this->bisFertig($lauf);

        self::assertSame('ein ganz anderer Beleg', $this->dav->dateien[$fremd]['inhalt']);
        self::assertSame(self::pdfText($belege[0]), $this->dav->dateien['Belege_2025/Bauhaus/2025/02. Februar/Bauhaus 03.02.2025 (2).pdf']['inhalt']);
        self::assertNotContains($vonHand, $this->dav->hochgeladen());
        $stand = new ExportRunUploadRepository($this->pdo())->find($lauf);
        self::assertSame([2, 1, 1], [$stand?->hochgeladen, $stand?->vorhanden, $stand?->umbenannt]);
        self::assertStringContainsString('1 mit Zusatz', $this->get('/app/export/lauf/' . $lauf)->body);
    }

    /** A failed step moves nothing; the next one carries on with the same file. */
    public function testAFailedStepMovesNothingAndTheNextOneGoesOn(): void
    {
        $this->einrichten();
        $this->belege();
        $lauf = $this->laufAnlegen();
        $this->starten($lauf);

        $this->dav->naechsterPutStatus = 507;
        $antwort = $this->schritt($lauf, 409);
        self::assertStringContainsString('Speicherplatz', $antwort['fehler']);
        self::assertSame(0, new ExportRunUploadRepository($this->pdo())->find($lauf)?->nextIndex);

        $this->bisFertig($lauf);
        self::assertSame(4, new ExportRunUploadRepository($this->pdo())->find($lauf)?->hochgeladen);
    }

    /** Two tabs never count a file twice: the cursor update names the cursor it read. */
    public function testTheCursorMovesOnlyFromWhereItWasRead(): void
    {
        $this->einrichten();
        $this->belege();
        $lauf = $this->laufAnlegen();
        $this->starten($lauf);
        $uploads = new ExportRunUploadRepository($this->pdo());

        self::assertTrue($uploads->weiter($lauf, 0, 'hochgeladen', new \DateTimeImmutable()));
        self::assertFalse($uploads->weiter($lauf, 0, 'hochgeladen', new \DateTimeImmutable()), 'the second tab finds the cursor moved');
        self::assertSame([1, 1], [$uploads->find($lauf)?->nextIndex, $uploads->find($lauf)?->hochgeladen]);
    }

    public function testWrongCredentialsStopTheStartWithAMessage(): void
    {
        $this->einrichten();
        $this->belege();
        $lauf = $this->laufAnlegen();
        $this->dav->passwort = 'anderes';

        $this->post('/app/export/lauf/' . $lauf . '/nextcloud', []);

        self::assertNull(new ExportRunUploadRepository($this->pdo())->find($lauf));
        self::assertStringContainsString('Anmeldung abgelehnt', $this->get('/app/export/lauf/' . $lauf)->body);
    }

    public function testWithoutATargetThePageOffersToSetOneUp(): void
    {
        $this->belege();
        $lauf = $this->laufAnlegen();

        $seite = $this->get('/app/export/lauf/' . $lauf)->body;
        self::assertStringContainsString('href="/admin/export/nextcloud"', $seite);
        self::assertStringNotContainsString('/nextcloud"', str_replace('href="/admin/export/nextcloud"', '', $seite), 'no filing form');

        $this->post('/app/export/lauf/' . $lauf . '/nextcloud', []);
        self::assertNull(new ExportRunUploadRepository($this->pdo())->find($lauf));
        self::assertStringContainsString('keine Nextcloud eingerichtet', $this->get('/app/export/lauf/' . $lauf)->body);
    }

    public function testFilingNeedsTheVaultAndTheCsrfToken(): void
    {
        $this->einrichten();
        $this->belege();
        $lauf = $this->laufAnlegen();

        $this->post('/app/export/lauf/' . $lauf . '/nextcloud', [], entsperrt: false);
        self::assertNull(new ExportRunUploadRepository($this->pdo())->find($lauf));

        new Session()->start();
        $antwort = $this->kernel()->handle(new Request(HttpMethod::Post, '/app/export/lauf/' . $lauf . '/nextcloud', cookies: $this->tresorCookie(), ip: self::IP));
        self::assertInstanceOf(Response::class, $antwort);
        self::assertSame(302, $antwort->status);
        self::assertNull(new ExportRunUploadRepository($this->pdo())->find($lauf));

        $this->starten($lauf);
        new Session()->start();
        $antwort = $this->kernel()->handle(new Request(HttpMethod::Post, '/app/export/lauf/' . $lauf . '/nextcloud/schritt', cookies: $this->tresorCookie(), ip: self::IP));
        self::assertInstanceOf(Response::class, $antwort);
        self::assertSame(403, $antwort->status);
        self::assertSame(403, $this->schrittAntwort($lauf, entsperrt: false)->status);

        foreach (['', '/testen', '/entfernen'] as $aktion) {
            new Session()->start();
            $antwort = $this->kernel()->handle(new Request(HttpMethod::Post, '/admin/export/nextcloud' . $aktion, post: ['url' => self::URL, 'benutzer' => 'x', 'passwort' => 'y'], cookies: $this->tresorCookie(), ip: self::IP));
            self::assertInstanceOf(Response::class, $antwort);
            self::assertSame(302, $antwort->status, $aktion);
        }
        self::assertSame('kasse', new NextcloudZielSpeicher(new NextcloudTargetRepository($this->pdo()))->lesen($this->tresor)?->benutzer);
        self::assertSame([], $this->dav->hochgeladen());
    }

    /** The cloud folder is the club's archive: a scoped role does not file into it. */
    public function testAScopedRoleMayNotFile(): void
    {
        $this->einrichten();
        $this->belege();
        $lauf = $this->laufAnlegen();
        $this->rolle(SystemRole::Steuerberater);
        new UserAccessRepository($this->pdo())->setScope($this->userId, new \DateTimeImmutable('2025-01-01'), null);

        $seite = $this->get('/app/export/lauf/' . $lauf)->body;
        self::assertStringNotContainsString('nextcloud-ablage', $seite);
        self::assertStringNotContainsString('/nextcloud"', $seite);
        $antwort = $this->post('/app/export/lauf/' . $lauf . '/nextcloud', []);
        self::assertInstanceOf(Response::class, $antwort);
        self::assertSame(404, $antwort->status);
        self::assertSame(404, $this->schrittAntwort($lauf)->status);
        self::assertNull(new ExportRunUploadRepository($this->pdo())->find($lauf));
    }

    // ------------------------------------------------------ helpers

    /**
     * Three checked receipts in 2025, one of them a name collision of the
     * ZIP; one in review that stays out.
     *
     * @return list<int> the running numbers of the three, in ZIP order
     */
    private function belege(): array
    {
        $bauhaus = $this->stamm['bauhaus'];
        $a = $this->beleg('2025-02-03', $bauhaus);
        $b = $this->beleg('2025-05-17', $bauhaus);
        $c = $this->beleg('2025-05-17', $bauhaus);
        $this->beleg('2025-06-01', $bauhaus, DocumentStatus::InPruefung);

        return [$a, $b, $c];
    }

    /** @return int the running number, which the PDF's content carries */
    private function beleg(string $datum, ?int $lieferant, DocumentStatus $status = DocumentStatus::Geprueft): int
    {
        $nr = ++$this->laufnummer;
        $original = $this->blobs()->storeString('JPEG Original ' . $nr . ' ' . self::MARKER, new BlobMeta(MagicBytes::JPEG, 'foto.jpg'), $this->tresor, BlobStorage::Fs)->id;

        $documents = new DocumentRepository($this->pdo());
        $id = $documents->insert(DocumentSource::Intern, null, [$original], $this->tresor->sealDataKey(DataKey::generate()), new \DateTimeImmutable(), DocumentStatus::InPruefung, createdBy: $this->userId);
        $pdf = $this->blobs()->storeString(self::pdfText($nr), new BlobMeta(MagicBytes::PDF, 'beleg.pdf'), $this->tresor, BlobStorage::Fs);
        self::assertTrue($documents->setzePdfBlob($id, $pdf->id));
        $this->pdo()->prepare('UPDATE document SET status = ? WHERE id = ?')->execute([$status->value, $id]);

        $schluessel = DataKey::generate();
        $rechnungen = new InvoiceRepository($this->pdo());
        $rechnung = $rechnungen->insert(
            $id,
            new InvoiceStructure(InvoiceType::Rechnung, InvoiceDirection::Ausgabe, $lieferant, new \DateTimeImmutable($datum), null, null, null, null, null),
            $this->tresor->sealDataKey($schluessel),
            null,
            $this->userId,
            new \DateTimeImmutable(),
        );
        $daten = new InvoiceData('RE-' . $nr, 1000 * $nr, null, [], 'EUR', 'Zweck ' . self::MARKER, '');
        $rechnungen->setData($rechnung, FieldCipher::encrypt(
            $schluessel,
            json_encode($daten->toPayload(), JSON_THROW_ON_ERROR),
            new FieldContext('invoice', $rechnung, 'data_enc'),
        ));

        return $nr;
    }

    private static function pdfText(int $nr): string
    {
        return '%PDF-1.4 Arbeitskopie ' . $nr . ' ' . self::MARKER;
    }

    private function einrichten(): void
    {
        $antwort = $this->post('/admin/export/nextcloud', ['url' => self::URL, 'benutzer' => 'kasse', 'passwort' => self::PASSWORT]);
        self::assertInstanceOf(Response::class, $antwort);
        self::assertSame(302, $antwort->status);
    }

    private function laufAnlegen(): int
    {
        $antwort = $this->post('/app/export/lauf', self::ZEITRAUM);
        self::assertInstanceOf(Response::class, $antwort);
        $ziel = $antwort->headers['Location'] ?? '';
        self::assertMatchesRegularExpression('#^/app/export/lauf/\d+$#', $ziel);

        return (int) substr($ziel, strlen('/app/export/lauf/'));
    }

    private function starten(int $lauf): void
    {
        $antwort = $this->post('/app/export/lauf/' . $lauf . '/nextcloud', []);
        self::assertInstanceOf(Response::class, $antwort);
        self::assertSame('/app/export/lauf/' . $lauf, $antwort->headers['Location'] ?? null);
        self::assertSame(NextcloudAblageStatus::Laeuft, new ExportRunUploadRepository($this->pdo())->find($lauf)?->status);
        self::assertStringContainsString('data-status="laeuft"', $this->get('/app/export/lauf/' . $lauf)->body);
    }

    /** @return int the steps it took */
    private function bisFertig(int $lauf): int
    {
        for ($schritte = 1; $schritte <= 20; $schritte++) {
            if ($this->schritt($lauf)['status'] === 'fertig') {
                return $schritte;
            }
        }
        self::fail('The chain did not finish.');
    }

    /** @return array<string, mixed> */
    private function schritt(int $lauf, int $status = 200): array
    {
        $antwort = $this->schrittAntwort($lauf);
        self::assertSame($status, $antwort->status, $antwort->body);
        $daten = json_decode($antwort->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($daten);

        return $daten;
    }

    private function schrittAntwort(int $lauf, bool $entsperrt = true): Response
    {
        $antwort = $this->post('/app/export/lauf/' . $lauf . '/nextcloud/schritt', [], $entsperrt);
        self::assertInstanceOf(Response::class, $antwort);

        return $antwort;
    }

    /**
     * @return list<\App\Domain\AuditEntry>
     */
    private function audits(AuditAction $aktion): array
    {
        return array_values(array_filter(
            new AuditLogRepository($this->pdo())->page(new AuditFilter(), null, 100),
            static fn(\App\Domain\AuditEntry $e): bool => $e->action === $aktion->value,
        ));
    }

    /** @return array<string, mixed> */
    private function auditDetails(\App\Domain\AuditEntry $eintrag): array
    {
        return $this->audit->details($eintrag, $this->tresor) ?? [];
    }

    private function rolle(SystemRole $rolle): void
    {
        $rollen = new RoleRepository($this->pdo());
        $id = $rollen->findSystem($rolle)?->id;
        assert($id !== null);
        $rollen->assignToUser($this->userId, [$id]);
    }

    private function blobDir(): string
    {
        return $this->wurzel . '/shared/var/blobs';
    }

    private function tmpDir(): string
    {
        return $this->wurzel . '/shared/var/tmp';
    }

    private function blobs(): BlobService
    {
        $repository = new BlobRepository($this->pdo());

        return new BlobService($repository, new DbBlobBackend($repository), new FsBlobBackend($this->blobDir()));
    }

    /**
     * @param array<string, string> $felder
     */
    private function post(string $pfad, array $felder, bool $entsperrt = true): ResponseInterface
    {
        new Session()->start();

        return $this->kernel()->handle(new Request(
            HttpMethod::Post,
            $pfad,
            post: [...$felder, '_csrf' => new Session()->csrfToken()],
            cookies: $entsperrt ? $this->tresorCookie() : [],
            ip: self::IP,
        ));
    }

    private function get(string $pfad, bool $entsperrt = true): Response
    {
        new Session()->start();
        $antwort = $this->kernel()->handle(new Request(HttpMethod::Get, $pfad, cookies: $entsperrt ? $this->tresorCookie() : []));
        self::assertInstanceOf(Response::class, $antwort);

        return $antwort;
    }

    /**
     * @return array<string, string>
     */
    private function tresorCookie(): array
    {
        return [Cookie::vaultKey('x', Request::httpsFromGlobals())->name => new SessionVault()->store($this->tresor)];
    }

    private function kernel(): Kernel
    {
        $paths = new Paths(dirname(__DIR__, 2));
        $view = new View($paths->viewsDir(), '0.0.0-test');
        $pdo = $this->pdo();
        $ziele = new NextcloudZielSpeicher(new NextcloudTargetRepository($pdo));

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
        $export = function () use ($view, $pdo, $ziele): ExportController {
            $kategorien = new CategoryRepository($pdo);
            $kostenstellen = new CostCenterRepository($pdo);
            $lieferanten = new SupplierService($pdo, new SupplierRepository($pdo), $kategorien);
            $zip = new ZipExport(new InvoiceRepository($pdo), $this->blobs(), $lieferanten, $kategorien, $kostenstellen, new SettingRepository($pdo), new FileLogger($this->wurzel . '/shared/var/log/app.log'));

            return new ExportController(
                $view,
                new Session(),
                new SessionVault(),
                $zip,
                new ExportRunRepository($pdo),
                $kategorien,
                $kostenstellen,
                $this->audit,
                new NextcloudAblage($zip, $this->blobs(), new ExportRunUploadRepository($pdo), $ziele, $this->dav, $this->audit, budgetS: 60.0, maxJeSchritt: self::JE_SCHRITT),
            );
        };
        $nextcloudAdmin = fn(): NextcloudSettingsController => new NextcloudSettingsController($view, new Session(), new SessionVault(), $ziele, $this->dav, $this->audit);
        $unerreichbar = static fn(): never => throw new \LogicException('Diese Route gehört nicht zu diesem Test.');

        // Every controller closure of app/src/routes.php in order: the
        // guard second, the export 36th, the Nextcloud settings 38th.
        $controller = array_fill(0, 38, $unerreichbar);
        $controller[1] = $guard;
        $controller[35] = $export;
        $controller[37] = $nextcloudAdmin;

        $router = new Router();
        (require dirname(__DIR__, 2) . '/app/src/routes.php')($router, $view, ...$controller);

        return new Kernel(
            router: $router,
            staticFiles: new StaticFileHandler($paths->publicDir(), longCache: false),
            view: $view,
            debug: true,
        );
    }

    /**
     * Everything below $dir, recursively, parents before their children.
     *
     * @return list<string>
     */
    private static function dateien(string $dir, string $prefix = ''): array
    {
        $alle = [];
        foreach (array_diff(scandir($dir . '/' . $prefix) ?: [], ['.', '..']) as $name) {
            $alle[] = $prefix . $name;
            if (is_dir($dir . '/' . $prefix . $name)) {
                array_push($alle, ...self::dateien($dir, $prefix . $name . '/'));
            }
        }

        return $alle;
    }
}
