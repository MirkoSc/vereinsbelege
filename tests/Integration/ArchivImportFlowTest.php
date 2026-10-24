<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Api\UploadController;
use App\App\ArchivImportController;
use App\Config\Paths;
use App\Domain\ArchiveImportStatus;
use App\Domain\ArtifactKind;
use App\Domain\AuditAction;
use App\Domain\DocumentSource;
use App\Domain\DocumentStatus;
use App\Domain\SupplierOrigin;
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
use App\Repository\ArchiveImportRepository;
use App\Repository\AuditLogRepository;
use App\Repository\BlobRepository;
use App\Repository\CategoryRepository;
use App\Repository\DocumentArtifactRepository;
use App\Repository\DocumentRepository;
use App\Repository\JobRepository;
use App\Repository\RoleRepository;
use App\Repository\SettingRepository;
use App\Repository\SupplierRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\Service\Account\SessionVault;
use App\Service\Archiv\ArchivImport;
use App\Service\Archiv\ArchivVorgabe;
use App\Service\Audit\AuditFilter;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\MasterData\SupplierService;
use App\Service\Migration\Migrator;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Service\Upload\UploadService;
use App\Tests\Support\DatabaseTestCase;
use App\View\View;

/**
 * The archive import end to end (issue #77/M12-3, docs/spec/
 * 05-auswertung-und-export.md section 3 and "Pflicht-Tests": folder
 * semantics Kasse/Wiedervorlage, idempotency of a repeated step). The real
 * route table, guard, controller, chunk upload, service, repositories, blob
 * store and schema; the ZIP is built in the test. All names are made up.
 */
final class ArchivImportFlowTest extends DatabaseTestCase
{
    private const string IP = '198.51.100.77';

    /** In every file's plaintext - it must turn up nowhere but in the blobs, encrypted. */
    private const string MARKER = 'KLARTEXT-a77c3e';

    /** Small chunks, so the ZIP is read across chunk boundaries. */
    private const int CHUNK = 1024;

    private ServerCrypto $crypto;
    private Vault $tresor;
    private AuditLog $audit;
    private int $userId;
    private string $wurzel;
    private int $bauhaus;
    private int $platzpflege;

    /** The raw body of the next chunk request. */
    private string $chunkInhalt = '';

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->wurzel = sys_get_temp_dir() . '/vb_archiv_' . uniqid('', true);
        mkdir($this->blobDir(), 0775, true);
        mkdir($this->tmpDir(), 0775, true);

        $this->crypto = new ServerCrypto((string) base64_decode(self::configData()['server_key'], true));
        $this->tresor = Vault::create();
        new VaultRepository($this->pdo())->insert($this->tresor->publicKey());
        $this->audit = new AuditLog(new AuditLogRepository($this->pdo()), new VaultRepository($this->pdo()), $this->crypto);

        $this->userId = new UserRepository($this->pdo())->insert(
            $this->crypto->encrypt('finanzen@example.org'),
            random_bytes(32),
            $this->crypto->encrypt('Fritz Finanzen'),
            'hash',
            mfaRequired: false,
        );
        $this->rolle(SystemRole::Finanzen);

        $this->bauhaus = $this->lieferanten()->anlegen($this->tresor, ['name' => 'BAUHAUS GmbH & Co. KG', 'rolle' => SupplierRole::Lieferant->value], new \DateTimeImmutable())->id;
        $stmt = $this->pdo()->prepare('SELECT id FROM category WHERE name = ?');
        $stmt->execute(['Platzpflege & Grünanlagen']);
        $this->platzpflege = (int) $stmt->fetchColumn();

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

    // ------------------------------------------------ the whole way

    public function testTheArchiveBecomesReceiptsWithWhatItsPathsSay(): void
    {
        $id = $this->hochladen($this->archiv());

        $vorschau = $this->get('/app/archiv-import/' . $id);
        self::assertSame(200, $vorschau->status);
        self::assertStringContainsString('Vorschau – noch ist nichts gespeichert', $vorschau->body);
        self::assertStringContainsString('Kiosk am Platz', $vorschau->body);
        self::assertStringContainsString('wird angelegt', $vorschau->body);
        self::assertStringContainsString('vorhanden', $vorschau->body, 'BAUHAUS GmbH & Co. KG is found as "Bauhaus"');
        self::assertSame(0, $this->anzahl('document'), 'the preview writes nothing');

        $this->starten($id);
        $this->alleSchritte($id);

        $import = $this->importe()->find($id);
        self::assertSame(ArchiveImportStatus::Fertig, $import?->status);
        self::assertSame(5, $import->stats->belege);
        self::assertSame(1, $import->stats->kasse);
        self::assertSame(1, $import->stats->wiedervorlage);
        self::assertSame(1, $import->stats->ohneVorbelegung);
        self::assertSame(2, $import->stats->lieferantenNeu, 'Kiosk am Platz and Stadtwerke Musterstadt, each once');
        self::assertSame(2, $import->stats->uebersprungen, 'the text file and the export copy');
        self::assertSame(1, $import->stats->systemdateien);

        $dokumente = $this->dokumente();
        self::assertCount(5, $dokumente);
        foreach ($dokumente as $d) {
            self::assertSame(DocumentSource::Archiv->value, $d['source']);
            self::assertSame('ausstehend', $d['ocr_status']);
            self::assertSame($this->userId, (int) $d['created_by']);
            // PDF, duplicate index, text - as for any captured receipt; and
            // the AI (issue #47/M7-5) for what is ready for evaluation right
            // away, not for a Wiedervorlage.
            $ki = $d['status'] === DocumentStatus::BereitZurAuswertung->value ? 1 : 0;
            self::assertSame(3 + $ki, $this->jobsFuer((int) $d['id']));
            self::assertSame($ki, $this->jobsFuer((int) $d['id'], 'ai_extract'));
        }

        $nachPfad = [];
        foreach ($dokumente as $d) {
            $vorgabe = $this->vorgabe((int) $d['id']);
            $nachPfad[$vorgabe->pfad] = [$d, $vorgabe];
        }
        self::assertSame([
            '2026/02. Februar/Bauhaus 03.02.2026.pdf',
            '2026/02. Februar/Kasse/Kiosk am Platz 05.02.2026.pdf',
            '2026/03. März/Kiosk am Platz 07.03.2026 (2).pdf',
            '2026/Wiedervorlage/Stadtwerke Musterstadt 15.01.2026.pdf',
            '2026/Platzpflege & Grünanlagen/Scan0001.pdf',
        ], array_keys($nachPfad));

        [$bauhaus, $v] = $nachPfad['2026/02. Februar/Bauhaus 03.02.2026.pdf'];
        self::assertSame(DocumentStatus::BereitZurAuswertung->value, $bauhaus['status']);
        self::assertSame($this->bauhaus, $v->lieferantId);
        self::assertSame('2026-02-03', $v->datum?->format('Y-m-d'));
        self::assertFalse($v->kasse);
        self::assertSame($this->original((int) $bauhaus['id']), self::pdf('Bauhaus'));

        [, $kiosk] = $nachPfad['2026/02. Februar/Kasse/Kiosk am Platz 05.02.2026.pdf'];
        [, $kiosk2] = $nachPfad['2026/03. März/Kiosk am Platz 07.03.2026 (2).pdf'];
        self::assertTrue($kiosk->kasse);
        self::assertFalse($kiosk2->kasse);
        self::assertNotNull($kiosk->lieferantId);
        self::assertSame($kiosk->lieferantId, $kiosk2->lieferantId, 'the supplier created for the first file is found for the second');
        $neu = new SupplierRepository($this->pdo())->find((int) $kiosk->lieferantId);
        self::assertSame(SupplierOrigin::Archiv, $neu?->createdVia);
        self::assertSame(SupplierRole::Lieferant, $neu->role);
        self::assertSame('Kiosk am Platz', $this->lieferanten()->finde($this->tresor, (int) $kiosk->lieferantId)?->data->name);

        [$wv, $wvVorgabe] = $nachPfad['2026/Wiedervorlage/Stadtwerke Musterstadt 15.01.2026.pdf'];
        self::assertSame(DocumentStatus::Wiedervorlage->value, $wv['status']);
        self::assertTrue($wvVorgabe->wiedervorlage);
        self::assertSame(new \DateTimeImmutable()->format('Y-m-d'), $wv['resubmit_on'], 'due on the day of the import');
        self::assertSame(ArchivImport::WIEDERVORLAGE_NOTIZ, FieldCipher::decrypt(
            $this->tresor->openDataKey((string) $wv['dek_sealed']),
            (string) $wv['status_note_enc'],
            new FieldContext('document', (int) $wv['id'], 'status_note_enc'),
        ));

        [, $scan] = $nachPfad['2026/Platzpflege & Grünanlagen/Scan0001.pdf'];
        self::assertNull($scan->lieferantId);
        self::assertNull($scan->datum);
        self::assertSame($this->platzpflege, $scan->kategorieId, 'a folder named like a category');

        $bericht = $this->importService()->bericht($this->tresor, $import);
        self::assertSame(['2026/notiz.txt', 'Belege_2026/_Originale/Bauhaus/2026/02. Februar/Bauhaus 03.02.2026.jpg'], array_column($bericht, 'pfad'));
        self::assertStringContainsString('Kein PDF', $bericht[0]['grund']);

        $ergebnis = $this->get('/app/archiv-import/' . $id);
        self::assertStringContainsString('Übernommen:', $ergebnis->body);
        self::assertStringContainsString('2026/notiz.txt', $ergebnis->body);

        self::assertSame([], self::dateien($this->tmpDir() . '/upload'), 'the chunks are gone once the import is done');
        self::assertCount(1, $this->audits(AuditAction::ArchivImportiert));
        self::assertCount(2, $this->audits(AuditAction::LieferantAngelegt));
    }

    public function testARepeatedOrParallelStepWritesNothingTwice(): void
    {
        $id = $this->hochladen($this->archiv());
        $this->starten($id);

        $erster = $this->schritt($id);
        self::assertSame('laeuft', $erster['status']);
        $belege = $this->anzahl('document');
        self::assertGreaterThan(0, $belege);

        // A second tab still holding the old cursor loses: nothing moves twice.
        self::assertFalse($this->importe()->advance($id, 0, new \DateTimeImmutable()));
        self::assertSame($belege, $this->anzahl('document'));

        $this->alleSchritte($id);
        $nachher = $this->anzahl('document');
        self::assertSame(5, $nachher);
        $ende = $this->schritt($id);
        self::assertSame('fertig', $ende['status'], 'a step after the end only reports');
        self::assertSame(5, $this->anzahl('document'));
        self::assertSame(2, $this->anzahl('supplier') - 1, 'no supplier twice');
        self::assertCount(1, $this->audits(AuditAction::ArchivImportiert), 'finished once, logged once');
    }

    public function testAHeicPhotoInTheArchiveIsSkippedWithAReason(): void
    {
        $heic = "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic" . str_repeat("\x00", 64);
        $id = $this->hochladen(self::zip([
            '2026/02. Februar/Bauhaus 03.02.2026.pdf' => self::pdf('Bauhaus'),
            '2026/IMG_0001.HEIC' => $heic,
        ]));
        $this->starten($id);
        $this->alleSchritte($id);

        self::assertSame(1, $this->anzahl('document'), 'only the PDF becomes a receipt');
        $import = $this->importe()->find($id);
        self::assertNotNull($import);
        $bericht = $this->importService()->bericht($this->tresor, $import);
        self::assertSame(['2026/IMG_0001.HEIC'], array_column($bericht, 'pfad'));
        self::assertStringContainsString('HEIC wird im Archiv-Import nicht unterstützt', $bericht[0]['grund']);
    }

    public function testNoPlaintextIsLeftOnceTheImportIsDone(): void
    {
        $vorher = scandir(sys_get_temp_dir()) ?: [];
        $id = $this->hochladen($this->archiv());
        $this->starten($id);
        $this->alleSchritte($id);

        self::assertSame([], self::dateien($this->tmpDir() . '/upload'), 'no chunk is left in shared/var/tmp');
        foreach (array_diff(scandir(sys_get_temp_dir()) ?: [], $vorher) as $neu) {
            $pfad = sys_get_temp_dir() . '/' . $neu;
            if (is_file($pfad) && is_readable($pfad)) {
                self::assertStringNotContainsString(self::MARKER, (string) file_get_contents($pfad), $neu);
            }
        }
        $blobs = 0;
        foreach (self::dateien($this->blobDir()) as $datei) {
            if (is_file($this->blobDir() . '/' . $datei)) {
                $blobs++;
                self::assertStringNotContainsString(self::MARKER, (string) file_get_contents($this->blobDir() . '/' . $datei));
            }
        }
        self::assertSame(5, $blobs, 'one encrypted original per receipt');
        $stmt = $this->pdo()->query('SELECT stats, report_enc FROM archive_import');
        $zeile = (string) json_encode($stmt === false ? [] : $stmt->fetch());
        self::assertStringNotContainsString('notiz', $zeile, 'file names only encrypted');
        self::assertStringNotContainsString('Kiosk', $zeile);
    }

    // ------------------------------------------------ preview and pattern

    public function testThePreviewUsesThePatternAskedFor(): void
    {
        $id = $this->hochladen(self::zip([
            '2026-02-03 Bauhaus.pdf' => self::pdf('a'),
            '2026-03-01 Kiosk am Platz.pdf' => self::pdf('b'),
        ]));

        $standard = $this->get('/app/archiv-import/' . $id);
        self::assertMatchesRegularExpression('/ohne passenden Dateinamen.*?<td class="zahl">2</s', $standard->body);

        $iso = $this->get('/app/archiv-import/' . $id, ['muster' => '{datum_iso} {lieferant}']);
        self::assertMatchesRegularExpression('/ohne passenden Dateinamen.*?<td class="zahl">0</s', $iso->body);
        self::assertStringContainsString('03.02.2026', $iso->body);
        self::assertSame('{datum_iso} {lieferant}', $this->importe()->find($id)?->namePattern, 'a usable pattern is remembered');

        $falsch = $this->get('/app/archiv-import/' . $id, ['muster' => '{lieferant}{datum}']);
        self::assertStringContainsString('Trennzeichen', $falsch->body);
        self::assertStringNotContainsString('Import starten', $falsch->body);

        $this->post('/app/archiv-import/' . $id . '/starten', ['muster' => '{datum_iso} {lieferant}']);
        $this->alleSchritte($id);
        $daten = array_map(fn(array $d): ?string => $this->vorgabe((int) $d['id'])->datum?->format('Y-m-d'), $this->dokumente());
        self::assertSame(['2026-02-03', '2026-03-01'], $daten);
    }

    public function testAnUnusablePatternDoesNotStartTheImport(): void
    {
        $id = $this->hochladen(self::zip(['Bauhaus 03.02.2026.pdf' => self::pdf('a')]));

        $antwort = $this->post('/app/archiv-import/' . $id . '/starten', ['muster' => 'nur Text']);

        self::assertSame(422, $antwort->status);
        self::assertSame(ArchiveImportStatus::Vorschau, $this->importe()->find($id)?->status);
    }

    // ------------------------------------------------ upload checks

    public function testAFileThatIsNoZipIsRefusedAndThrownAway(): void
    {
        $antwort = $this->hochladenRoh(self::pdf('kein Archiv'));

        self::assertSame(422, $antwort->status);
        self::assertStringContainsString('kein ZIP', (string) (json_decode($antwort->body, true)['fehler'] ?? ''));
        self::assertSame(0, $this->anzahl('archive_import'));
        self::assertSame([], self::dateien($this->tmpDir() . '/upload'), 'the upload goes at once');
    }

    public function testAnArchiveAboveTheLimitIsRefusedBeforeAByteIsSent(): void
    {
        $antwort = $this->post('/app/archiv-import/upload', ['groesse' => (string) (ArchivImport::MAX_ZIP_BYTES + 1)]);

        self::assertSame(413, $antwort->status);
    }

    // ------------------------------------------------ cut off

    public function testDiscardingKeepsTheReceiptsImportedSoFar(): void
    {
        $id = $this->hochladen($this->archiv());
        $this->starten($id);
        $this->schritt($id);
        $belege = $this->anzahl('document');

        $antwort = $this->post('/app/archiv-import/' . $id . '/verwerfen');

        self::assertSame(302, $antwort->status);
        self::assertSame(ArchiveImportStatus::Abgebrochen, $this->importe()->find($id)?->status);
        self::assertSame($belege, $this->anzahl('document'));
        self::assertSame([], self::dateien($this->tmpDir() . '/upload'));
        $audit = $this->audits(AuditAction::ArchivImportiert);
        self::assertCount(1, $audit);
        self::assertSame(['abgebrochen'], [$this->auditDetails($audit[0])['status'] ?? null]);
        self::assertSame('abgebrochen', $this->schritt($id)['status'], 'a step afterwards only reports');
    }

    public function testAnExpiredUploadEndsTheImportWithAReason(): void
    {
        $id = $this->hochladen($this->archiv());
        $this->starten($id);
        $this->schritt($id);
        foreach (array_reverse(self::dateien($this->tmpDir() . '/upload')) as $eintrag) {
            $pfad = $this->tmpDir() . '/upload/' . $eintrag;
            is_dir($pfad) ? rmdir($pfad) : unlink($pfad);
        }

        $stand = $this->schritt($id);

        self::assertSame('abgebrochen', $stand['status']);
        self::assertStringContainsString('nicht mehr da', (string) $stand['meldung']);
        self::assertSame(ArchiveImportStatus::Abgebrochen, $this->importe()->find($id)?->status);
    }

    public function testAChunkLostInTheMiddleOfAnEntryEndsTheImportCleanly(): void
    {
        // Incompressible first file: its bytes fill the first chunks, the
        // table of contents lies far behind them.
        $id = $this->hochladen(self::zip([
            'Bauhaus 03.02.2026.pdf' => "%PDF-1.4\n" . random_bytes(6000),
            'Kiosk am Platz 05.02.2026.pdf' => self::pdf('b'),
        ]));
        $this->starten($id);
        unlink($this->tmpDir() . '/upload/' . $this->importe()->find($id)?->uploadId . '/1.part');

        $stand = $this->schritt($id);

        self::assertSame('abgebrochen', $stand['status']);
        self::assertSame(0, $this->anzahl('document'), 'the entry was rolled back');
        self::assertSame(0, $this->anzahl('file_blob'), 'its half-written blob too');
        self::assertSame([], self::dateien($this->tmpDir() . '/upload'));
    }

    public function testARepeatedClosingRequestFindsTheSameImport(): void
    {
        $offen = $this->post('/app/archiv-import/upload', ['groesse' => (string) strlen($zip = $this->archiv())]);
        $ticket = json_decode($offen->body, true);
        foreach (str_split($zip, (int) $ticket['chunk_bytes']) as $n => $stueck) {
            $this->chunkInhalt = $stueck;
            $this->post('/app/archiv-import/upload/' . $ticket['id'] . '/chunk/' . $n);
        }

        $erste = $this->post('/app/archiv-import/upload/' . $ticket['id'] . '/finish');
        $zweite = $this->post('/app/archiv-import/upload/' . $ticket['id'] . '/finish');

        self::assertSame(201, $erste->status);
        self::assertSame($erste->body, $zweite->body);
        self::assertSame(1, $this->anzahl('archive_import'));
    }

    public function testEveryStepKeepsTheUploadAliveForTheCron(): void
    {
        $id = $this->hochladen($this->archiv());
        $this->starten($id);
        $upload = (string) $this->importe()->find($id)?->uploadId;
        foreach (self::dateien($this->tmpDir() . '/upload/' . $upload) as $datei) {
            touch($this->tmpDir() . '/upload/' . $upload . '/' . $datei, time() - 3 * 86400);
        }

        $this->schritt($id);

        self::assertSame(0, new UploadService($this->tmpDir() . '/upload')->cleanup(new \DateTimeImmutable('-24 hours')));
        self::assertDirectoryExists($this->tmpDir() . '/upload/' . $upload);
    }

    // ------------------------------------------------ vault, CSRF

    public function testWithoutTheVaultNothingIsReadOrWritten(): void
    {
        $start = $this->get('/app/archiv-import', entsperrt: false);
        self::assertStringContainsString('nur mit entsperrtem Tresor lesbar', $start->body);
        self::assertStringNotContainsString('archivimport-upload', $start->body);

        $id = $this->hochladen($this->archiv());
        $this->starten($id);
        $antwort = $this->post('/app/archiv-import/' . $id . '/schritt', entsperrt: false);
        self::assertSame(403, $antwort->status);
        self::assertSame(0, $this->anzahl('document'));
        self::assertSame(302, $this->get('/app/archiv-import/' . $id, entsperrt: false)->status);
    }

    public function testEveryWriteNeedsTheCsrfToken(): void
    {
        $id = $this->hochladen($this->archiv());

        $ohne = fn(string $pfad): ResponseInterface => $this->kernel()->handle(new Request(HttpMethod::Post, $pfad, cookies: $this->tresorCookie(), ip: self::IP));

        self::assertSame(302, $ohne('/app/archiv-import/' . $id . '/starten')->status);
        self::assertSame(ArchiveImportStatus::Vorschau, $this->importe()->find($id)?->status);
        $this->starten($id);
        self::assertSame(403, $ohne('/app/archiv-import/' . $id . '/schritt')->status);
        self::assertSame(403, $ohne('/app/archiv-import/upload')->status);
        self::assertSame(0, $this->anzahl('document'));
    }

    // ------------------------------------------------------ helpers

    /** The ZIP most tests import: both layouts, the folders, the odd files. */
    private function archiv(): string
    {
        return self::zip([
            '2026/' => null,
            '2026/02. Februar/Bauhaus 03.02.2026.pdf' => self::pdf('Bauhaus'),
            '2026/02. Februar/Kasse/Kiosk am Platz 05.02.2026.pdf' => self::pdf('Kiosk 1'),
            '2026/03. März/Kiosk am Platz 07.03.2026 (2).pdf' => self::pdf('Kiosk 2'),
            '2026/Wiedervorlage/Stadtwerke Musterstadt 15.01.2026.pdf' => self::pdf('Stadtwerke'),
            '2026/Platzpflege & Grünanlagen/Scan0001.pdf' => self::pdf('Scan'),
            '2026/notiz.txt' => 'Nur eine Notiz ' . self::MARKER,
            '__MACOSX/2026/._Bauhaus 03.02.2026.pdf' => 'resource fork',
            'Belege_2026/_Originale/Bauhaus/2026/02. Februar/Bauhaus 03.02.2026.jpg' => "\xFF\xD8\xFF\xE0 jpeg",
        ]);
    }

    private static function pdf(string $was): string
    {
        return "%PDF-1.4\n% " . $was . ' ' . self::MARKER . "\n" . str_repeat('Inhalt ' . $was . ' ', 300) . "\n%%EOF\n";
    }

    /**
     * @param array<string, string|null> $dateien path => content, null for a folder entry
     */
    private static function zip(array $dateien): string
    {
        $pfad = tempnam(sys_get_temp_dir(), 'archivtest');
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($pfad, \ZipArchive::OVERWRITE));
        foreach ($dateien as $name => $inhalt) {
            $inhalt === null ? $zip->addEmptyDir(rtrim($name, '/')) : $zip->addFromString($name, $inhalt);
        }
        $zip->close();
        $bytes = (string) file_get_contents($pfad);
        unlink($pfad);

        return $bytes;
    }

    /** Uploads through the real chunk routes and returns the import id. */
    private function hochladen(string $zip): int
    {
        $antwort = $this->hochladenRoh($zip);
        self::assertSame(201, $antwort->status, $antwort->body);
        $weiter = (string) (json_decode($antwort->body, true)['weiter'] ?? '');
        self::assertMatchesRegularExpression('#^/app/archiv-import/\d+$#', $weiter);

        return (int) substr($weiter, strlen('/app/archiv-import/'));
    }

    private function hochladenRoh(string $bytes): Response
    {
        $offen = $this->post('/app/archiv-import/upload', ['groesse' => (string) strlen($bytes)]);
        self::assertSame(201, $offen->status, $offen->body);
        $ticket = json_decode($offen->body, true);
        foreach (str_split($bytes, (int) $ticket['chunk_bytes']) as $n => $stueck) {
            $this->chunkInhalt = $stueck;
            self::assertSame(200, $this->post('/app/archiv-import/upload/' . $ticket['id'] . '/chunk/' . $n)->status);
        }

        return $this->post('/app/archiv-import/upload/' . $ticket['id'] . '/finish', ['name' => 'Belege 2026.zip']);
    }

    private function starten(int $id): void
    {
        $muster = $this->importe()->find($id)?->namePattern ?? '';
        $antwort = $this->post('/app/archiv-import/' . $id . '/starten', ['muster' => $muster]);
        self::assertSame(302, $antwort->status, $antwort->body);
        self::assertSame(ArchiveImportStatus::Laeuft, $this->importe()->find($id)?->status);
    }

    /**
     * @return array<string, mixed>
     */
    private function schritt(int $id): array
    {
        $antwort = $this->post('/app/archiv-import/' . $id . '/schritt');
        self::assertSame(200, $antwort->status, $antwort->body);

        return json_decode($antwort->body, true);
    }

    private function alleSchritte(int $id): void
    {
        for ($i = 0; $i < 50; $i++) {
            if ($this->schritt($id)['status'] !== 'laeuft') {
                return;
            }
        }
        self::fail('the chain did not end');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dokumente(): array
    {
        $stmt = $this->pdo()->query('SELECT * FROM document ORDER BY id');

        return $stmt === false ? [] : $stmt->fetchAll();
    }

    private function vorgabe(int $documentId): ArchivVorgabe
    {
        $artefakt = new DocumentArtifactRepository($this->pdo())->fuerDokument($documentId, ArtifactKind::Vorgabe)[0] ?? null;
        self::assertNotNull($artefakt);
        $json = FieldCipher::decrypt($this->tresor->openDataKey($artefakt->dekSealed), (string) $artefakt->dataEnc, new FieldContext('document_artifact', $artefakt->id, 'data_enc'));
        $vorgabe = ArchivVorgabe::fromArray(json_decode($json, true));
        self::assertNotNull($vorgabe);

        return $vorgabe;
    }

    private function original(int $documentId): string
    {
        $document = new DocumentRepository($this->pdo())->find($documentId);
        $blob = $this->blobs()->find((int) ($document?->originalBlobIds[0] ?? 0));
        self::assertNotNull($blob);

        return implode('', iterator_to_array($this->blobs()->openRead($blob, $this->tresor), false));
    }

    private function jobsFuer(int $documentId, ?string $typ = null): int
    {
        $stmt = $this->pdo()->prepare("SELECT COUNT(*) FROM job WHERE ref_type = 'document' AND ref_id = ? AND (? IS NULL OR typ = ?)");
        $stmt->execute([$documentId, $typ, $typ]);

        return (int) $stmt->fetchColumn();
    }

    private function anzahl(string $tabelle): int
    {
        $stmt = $this->pdo()->query('SELECT COUNT(*) FROM ' . $tabelle);

        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
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

    /**
     * @return array<string, mixed>
     */
    private function auditDetails(\App\Domain\AuditEntry $eintrag): array
    {
        return $this->audit->details($eintrag, $this->tresor) ?? [];
    }

    private function importe(): ArchiveImportRepository
    {
        return new ArchiveImportRepository($this->pdo());
    }

    private function lieferanten(): SupplierService
    {
        return new SupplierService($this->pdo(), new SupplierRepository($this->pdo()), new CategoryRepository($this->pdo()));
    }

    private function uploads(): UploadService
    {
        return new UploadService($this->tmpDir() . '/upload', self::CHUNK, ArchivImport::MAX_ZIP_BYTES);
    }

    private function importService(): ArchivImport
    {
        $pdo = $this->pdo();

        return new ArchivImport(
            $pdo,
            new ArchiveImportRepository($pdo),
            $this->uploads(),
            $this->blobs(),
            new SettingRepository($pdo),
            new DocumentRepository($pdo),
            new DocumentArtifactRepository($pdo),
            new JobRepository($pdo),
            $this->lieferanten(),
            new CategoryRepository($pdo),
            $this->audit,
            schrittDateien: 2,
        );
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
    private function post(string $pfad, array $felder = [], bool $entsperrt = true): Response
    {
        new Session()->start();
        $antwort = $this->kernel()->handle(new Request(
            HttpMethod::Post,
            $pfad,
            post: [...$felder, '_csrf' => new Session()->csrfToken()],
            cookies: $entsperrt ? $this->tresorCookie() : [],
            ip: self::IP,
        ));
        self::assertInstanceOf(Response::class, $antwort);

        return $antwort;
    }

    /**
     * @param array<string, string> $abfrage
     */
    private function get(string $pfad, array $abfrage = [], bool $entsperrt = true): Response
    {
        new Session()->start();
        $antwort = $this->kernel()->handle(new Request(HttpMethod::Get, $pfad, query: $abfrage, cookies: $entsperrt ? $this->tresorCookie() : []));
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
        $archiv = fn(): ArchivImportController => new ArchivImportController(
            $view,
            new Session(),
            new SessionVault(),
            new UploadController(
                new Session(),
                $this->uploads(),
                static fn(): never => throw new \LogicException('no blob of its own'),
                function () {
                    $strom = fopen('php://memory', 'w+b');
                    fwrite($strom, $this->chunkInhalt);
                    rewind($strom);

                    return $strom;
                },
            ),
            fn(): ArchivImport => $this->importService(),
        );
        $unerreichbar = static fn(): never => throw new \LogicException('Diese Route gehört nicht zu diesem Test.');

        // Every controller closure of app/src/routes.php in order: the
        // guard second, the archive import last (37th).
        $controller = array_fill(0, 37, $unerreichbar);
        $controller[1] = $guard;
        $controller[36] = $archiv;

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
        if (!is_dir($dir . '/' . $prefix)) {
            return [];
        }
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
