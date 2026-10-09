<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\App\ExportController;
use App\Config\Paths;
use App\Domain\AuditAction;
use App\Domain\BlobMeta;
use App\Domain\BlobStorage;
use App\Domain\DocumentSource;
use App\Domain\DocumentStatus;
use App\Domain\InvoiceData;
use App\Domain\InvoiceDirection;
use App\Domain\InvoiceStructure;
use App\Domain\InvoiceType;
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
use App\Http\StreamResponse;
use App\Repository\AuditLogRepository;
use App\Repository\BlobRepository;
use App\Repository\CategoryRepository;
use App\Repository\CostCenterRepository;
use App\Repository\DocumentRepository;
use App\Repository\InvoiceRepository;
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
use App\Service\Export\ZipExport;
use App\Service\MasterData\SupplierService;
use App\Service\Migration\Migrator;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Service\Upload\MagicBytes;
use App\Support\FileLogger;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\ZipLeser;
use App\View\View;

/**
 * The ZIP export end to end (issue #76/M12-2, docs/spec/
 * 05-auswertung-und-export.md section 2 and "Pflicht-Tests": the ZIP stream
 * is a valid ZIP holding exactly the filtered receipts plus index.csv, and
 * no temp file with plaintext is left). The real route table, guard,
 * controller, service, repositories, blob store and schema; receipts are
 * written straight into the encrypted store. All names are made up.
 */
final class ExportZipFlowTest extends DatabaseTestCase
{
    private const string IP = '198.51.100.76';

    /** In every file's plaintext - it must turn up nowhere but in the ZIP. */
    private const string MARKER = 'KLARTEXT-7f3a9c';

    private const array ZEITRAUM = ['von' => '2025-01-01', 'bis' => '2025-12-31'];

    private ServerCrypto $crypto;
    private Vault $tresor;
    private AuditLog $audit;
    private int $userId;
    private string $wurzel;
    private int $laufnummer = 0;

    /** @var array<string, int> */
    private array $stamm = [];

    /** @var array<int, string> document id => letter, see belege() */
    private array $buchstaben = [];

    /** @var array<int, int> document id => running number of beleg() */
    private array $nummern = [];

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        // A shared/ of its own: var/blobs for the store, var/tmp to look into.
        $this->wurzel = sys_get_temp_dir() . '/vb_export_' . uniqid('', true);
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

        $kostenstellen = new CostCenterRepository($this->pdo());
        $lieferanten = new SupplierService($this->pdo(), new SupplierRepository($this->pdo()), new CategoryRepository($this->pdo()));
        $this->stamm = [
            'platzpflege' => $this->kategorie('Platzpflege & Grünanlagen'),
            'spenden' => $this->kategorie('Spenden'),
            'jugend' => $kostenstellen->create('Jugend'),
            'senioren' => $kostenstellen->create('Senioren'),
            'bauhaus' => $lieferanten->anlegen($this->tresor, ['name' => 'Bauhaus', 'rolle' => SupplierRole::Lieferant->value], new \DateTimeImmutable())->id,
            'stadtwerke' => $lieferanten->anlegen($this->tresor, ['name' => 'Stadtwerke Musterstadt', 'rolle' => SupplierRole::Lieferant->value], new \DateTimeImmutable())->id,
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

    // ------------------------------------------------------ contents

    public function testTheZipHoldsExactlyTheFilteredReceiptsAndTheIndex(): void
    {
        $b = $this->belege();

        $antwort = $this->download(self::ZEITRAUM);
        self::assertSame('application/zip', $antwort->headers['Content-Type']);
        self::assertSame('attachment; filename="Belege_2025.zip"', $antwort->headers['Content-Disposition'], 'the period only, no business data');
        self::assertSame('no-store, private', $antwort->headers['Cache-Control']);

        $inhalte = ZipLeser::inhalte(self::bytes($antwort));
        self::assertSame([
            'Belege_2025/index.csv',
            'Belege_2025/Bauhaus/2025/02. Februar/Bauhaus 03.02.2025.pdf',
            'Belege_2025/_Ohne Lieferant/2025/03. März/_Ohne Lieferant 20.03.2025.pdf',
            'Belege_2025/Bauhaus/2025/05. Mai/Bauhaus 17.05.2025.pdf',
            'Belege_2025/Bauhaus/2025/05. Mai/Bauhaus 17.05.2025 (2).pdf',
        ], array_keys($inhalte), 'checked and locked receipts of the period, by date and id; not the one in review, the rejected one, or last year\'s');

        self::assertSame($this->pdfInhalt($b['A']), $inhalte['Belege_2025/Bauhaus/2025/02. Februar/Bauhaus 03.02.2025.pdf']);
        self::assertSame($this->pdfInhalt($b['B']), $inhalte['Belege_2025/Bauhaus/2025/05. Mai/Bauhaus 17.05.2025.pdf']);
        self::assertSame($this->pdfInhalt($b['C']), $inhalte['Belege_2025/Bauhaus/2025/05. Mai/Bauhaus 17.05.2025 (2).pdf']);

        self::assertSame(
            "\u{FEFF}Datum;Richtung;Lieferant;Rechnungsnr.;Brutto;Kategorie;Kostenstelle;Status;bezahlt am;Konto;Referenz;Datei\r\n"
            . "03.02.2025;Ausgabe;Bauhaus;RE-1;1.234,56;Platzpflege & Grünanlagen;Jugend;Geprüft;;;;Bauhaus/2025/02. Februar/Bauhaus 03.02.2025.pdf\r\n"
            . "20.03.2025;Einnahme;;;7,00;Spenden;;Geprüft;;;;_Ohne Lieferant/2025/03. März/_Ohne Lieferant 20.03.2025.pdf\r\n"
            . "17.05.2025;Ausgabe;Bauhaus;RE-2;-50,00;Platzpflege & Grünanlagen;;Festgeschrieben;;;;Bauhaus/2025/05. Mai/Bauhaus 17.05.2025.pdf\r\n"
            . "17.05.2025;Ausgabe;Bauhaus;'=1+1;19,99 USD;;Senioren;Geprüft;;;;Bauhaus/2025/05. Mai/Bauhaus 17.05.2025 (2).pdf\r\n",
            $inhalte['Belege_2025/index.csv'],
            'BOM, semicolons, CRLF, German amounts, no formula',
        );
    }

    public function testEveryFilterNarrowsTheZip(): void
    {
        $this->belege();
        $s = $this->stamm;

        self::assertSame(['A', 'B'], $this->exportiert(['kategorie' => (string) $s['platzpflege']]));
        self::assertSame(['C'], $this->exportiert(['kategorie' => 'ohne']));
        self::assertSame(['A'], $this->exportiert(['kostenstelle' => (string) $s['jugend']]));
        self::assertSame(['D', 'B'], $this->exportiert(['kostenstelle' => 'ohne']));
        self::assertSame(['A', 'B', 'C'], $this->exportiert(['lieferant' => (string) $s['bauhaus']]));
        self::assertSame(['D'], $this->exportiert(['lieferant' => 'ohne']));
        self::assertSame(['B'], $this->exportiert(['status' => 'festgeschrieben']));
        self::assertSame(['B', 'C'], $this->exportiert(['von' => '2025-05-01', 'bis' => '2025-05-31']));
        self::assertSame(['G'], $this->exportiert(['von' => '2024-12-31', 'bis' => '2024-12-31']));
    }

    public function testAllStatusesPutTheUncheckedIntoWiedervorlage(): void
    {
        $this->belege();

        $inhalte = ZipLeser::inhalte(self::bytes($this->download([...self::ZEITRAUM, 'status' => 'alle'])));

        self::assertSame([
            'Belege_2025/index.csv',
            'Belege_2025/Bauhaus/2025/02. Februar/Bauhaus 03.02.2025.pdf',
            'Belege_2025/_Ohne Lieferant/2025/03. März/_Ohne Lieferant 20.03.2025.pdf',
            'Belege_2025/_Wiedervorlage/Stadtwerke Musterstadt/2025/04. April/Stadtwerke Musterstadt 01.04.2025.pdf',
            'Belege_2025/Bauhaus/2025/05. Mai/Bauhaus 17.05.2025.pdf',
            'Belege_2025/Bauhaus/2025/05. Mai/Bauhaus 17.05.2025 (2).pdf',
        ], array_keys($inhalte), 'the rejected receipt never');
        self::assertStringContainsString(
            ";In Prüfung;;;;_Wiedervorlage/Stadtwerke Musterstadt/2025/04. April/Stadtwerke Musterstadt 01.04.2025.pdf\r\n",
            $inhalte['Belege_2025/index.csv'],
        );
    }

    public function testOriginalsComeAlongOnlyWithTheOption(): void
    {
        $a = $this->beleg('2025-02-03', lieferant: $this->stamm['bauhaus']);
        // Two photos and no working PDF (yet): the originals stand in.
        $h = $this->beleg('2025-06-10', lieferant: $this->stamm['stadtwerke'], mitPdf: false, seiten: 2);

        $ohne = ZipLeser::inhalte(self::bytes($this->download(self::ZEITRAUM)));
        self::assertSame([
            'Belege_2025/index.csv',
            'Belege_2025/Bauhaus/2025/02. Februar/Bauhaus 03.02.2025.pdf',
            'Belege_2025/Stadtwerke Musterstadt/2025/06. Juni/Stadtwerke Musterstadt 10.06.2025.jpg',
            'Belege_2025/Stadtwerke Musterstadt/2025/06. Juni/Stadtwerke Musterstadt 10.06.2025 (2).jpg',
        ], array_keys($ohne));
        self::assertSame($this->originalInhalt($h, 2), $ohne['Belege_2025/Stadtwerke Musterstadt/2025/06. Juni/Stadtwerke Musterstadt 10.06.2025 (2).jpg']);
        self::assertStringContainsString(
            ';Stadtwerke Musterstadt/2025/06. Juni/Stadtwerke Musterstadt 10.06.2025.jpg | Stadtwerke Musterstadt/2025/06. Juni/Stadtwerke Musterstadt 10.06.2025 (2).jpg' . "\r\n",
            $ohne['Belege_2025/index.csv'],
        );

        $mit = ZipLeser::inhalte(self::bytes($this->download([...self::ZEITRAUM, 'originale' => '1'])));
        self::assertSame([
            'Belege_2025/index.csv',
            'Belege_2025/Bauhaus/2025/02. Februar/Bauhaus 03.02.2025.pdf',
            'Belege_2025/_Originale/Bauhaus/2025/02. Februar/Bauhaus 03.02.2025.jpg',
            'Belege_2025/Stadtwerke Musterstadt/2025/06. Juni/Stadtwerke Musterstadt 10.06.2025.jpg',
            'Belege_2025/Stadtwerke Musterstadt/2025/06. Juni/Stadtwerke Musterstadt 10.06.2025 (2).jpg',
            'Belege_2025/_Originale/Stadtwerke Musterstadt/2025/06. Juni/Stadtwerke Musterstadt 10.06.2025.jpg',
            'Belege_2025/_Originale/Stadtwerke Musterstadt/2025/06. Juni/Stadtwerke Musterstadt 10.06.2025 (2).jpg',
        ], array_keys($mit));
        self::assertSame($this->originalInhalt($a, 1), $mit['Belege_2025/_Originale/Bauhaus/2025/02. Februar/Bauhaus 03.02.2025.jpg']);
    }

    public function testTheConfiguredPatternShapesThePaths(): void
    {
        $this->beleg('2025-02-03', lieferant: $this->stamm['bauhaus']);
        new SettingRepository($this->pdo())->set('export_pfad_muster', '{jahr}/{monatsname}/{kasse}/{lieferant} {datum}.pdf');

        self::assertSame(
            ['Belege_2025/index.csv', 'Belege_2025/2025/02. Februar/Bauhaus 03.02.2025.pdf'],
            array_keys(ZipLeser::inhalte(self::bytes($this->download(self::ZEITRAUM)))),
            '{kasse} stays empty until payments are matched (M10) - the level is dropped',
        );
    }

    // ------------------------------------------------- no plaintext left

    /**
     * Pflicht-Test: no temp file with plaintext. Nothing appears in
     * shared/var/tmp, no new file in the system temp directory holds a
     * receipt's content, and the blob store holds ciphertext only.
     */
    public function testNoPlaintextIsLeftInATempFile(): void
    {
        $this->belege();
        $vorher = scandir(sys_get_temp_dir()) ?: [];

        $zip = self::bytes($this->download([...self::ZEITRAUM, 'status' => 'alle', 'originale' => '1']));
        self::assertStringContainsString(self::MARKER, implode('', ZipLeser::inhalte($zip)), 'the plaintext is in the ZIP');

        self::assertSame([], self::dateien($this->tmpDir()), 'shared/var/tmp stays empty');
        foreach (array_diff(scandir(sys_get_temp_dir()) ?: [], $vorher) as $neu) {
            $pfad = sys_get_temp_dir() . '/' . $neu;
            if (is_file($pfad) && is_readable($pfad)) {
                self::assertStringNotContainsString(self::MARKER, (string) file_get_contents($pfad), $neu);
            }
        }
        foreach (self::dateien($this->blobDir()) as $datei) {
            if (is_file($this->blobDir() . '/' . $datei)) {
                self::assertStringNotContainsString(self::MARKER, (string) file_get_contents($this->blobDir() . '/' . $datei));
            }
        }
    }

    // ---------------------------------------------- broken files

    /**
     * A working PDF that was never finished is no reason to leave a
     * receipt out: its originals stand in, nothing is reported missing.
     */
    public function testALostWorkingPdfIsReplacedByTheOriginals(): void
    {
        $a = $this->beleg('2025-02-03', lieferant: $this->stamm['bauhaus']);
        $this->pdo()->prepare('UPDATE file_blob SET cipher_sha256 = NULL WHERE id = ?')->execute([$this->pdfBlob($a)]);

        self::assertStringNotContainsString('fehlen im Speicher', $this->get('/app/export', self::ZEITRAUM)->body);

        $inhalte = ZipLeser::inhalte(self::bytes($this->download(self::ZEITRAUM)));
        self::assertSame(['Belege_2025/index.csv', 'Belege_2025/Bauhaus/2025/02. Februar/Bauhaus 03.02.2025.jpg'], array_keys($inhalte));
        self::assertSame($this->originalInhalt($a, 1), $inhalte['Belege_2025/Bauhaus/2025/02. Februar/Bauhaus 03.02.2025.jpg']);
        self::assertStringEndsWith(";Geprüft;;;;Bauhaus/2025/02. Februar/Bauhaus 03.02.2025.jpg\r\n", $inhalte['Belege_2025/index.csv']);
    }

    /**
     * A file missing from the store - its row unfinished, or its ciphertext
     * gone from shared/var/blobs/ - is found before the first byte: the
     * rest of the ZIP is intact, and index.csv names each gap.
     */
    public function testAFileMissingFromTheStoreIsNamedInTheIndex(): void
    {
        $a = $this->beleg('2025-02-03', lieferant: $this->stamm['bauhaus'], mitPdf: false, seiten: 3);
        $this->beleg('2025-03-01', lieferant: $this->stamm['stadtwerke']);
        [, $zwei, $drei] = $this->originalBlobs($a);
        unlink($this->fsPfad($zwei));
        $this->pdo()->prepare('UPDATE file_blob SET cipher_sha256 = NULL WHERE id = ?')->execute([$drei]);

        self::assertStringContainsString('2 Datei(en) fehlen im Speicher', $this->get('/app/export', self::ZEITRAUM)->body);

        $inhalte = ZipLeser::inhalte(self::bytes($this->download(self::ZEITRAUM)));
        self::assertSame([
            'Belege_2025/index.csv',
            'Belege_2025/Bauhaus/2025/02. Februar/Bauhaus 03.02.2025.jpg',
            'Belege_2025/Stadtwerke Musterstadt/2025/03. März/Stadtwerke Musterstadt 01.03.2025.pdf',
        ], array_keys($inhalte), 'one lost file does not break the ZIP');
        self::assertStringContainsString(
            ";Geprüft;;;;Bauhaus/2025/02. Februar/Bauhaus 03.02.2025.jpg | (Datei fehlt) | (Datei fehlt)\r\n",
            $inhalte['Belege_2025/index.csv'],
        );
    }

    /**
     * A file that cannot be decrypted halfway through ends the stream
     * without the central directory: recognisably broken rather than
     * quietly incomplete. The log names the error class, never a path or a
     * name.
     */
    public function testAFileUnreadableDuringTheStreamBreaksTheZip(): void
    {
        $this->beleg('2025-02-03', lieferant: $this->stamm['bauhaus']);
        $b = $this->beleg('2025-03-01', lieferant: $this->stamm['stadtwerke']);
        $datei = $this->fsPfad($this->pdfBlob($b));
        file_put_contents($datei, random_bytes((int) filesize($datei)));

        $zip = self::bytes($this->download(self::ZEITRAUM));

        self::assertStringContainsString('Bauhaus 03.02.2025.pdf', $zip, 'what came before went out');
        self::assertNotSame("PK\x05\x06", substr($zip, -22, 4), 'no end record');
        $log = (string) file_get_contents($this->logDatei());
        self::assertStringContainsString('ZIP export aborted: App\Service\Crypto\CryptoException', $log);
        self::assertStringNotContainsString('Stadtwerke', $log);
        self::assertStringNotContainsString(self::MARKER, $log);
    }

    // ------------------------------------------------- the page

    public function testThePagePreviewsTheExport(): void
    {
        $this->belege();

        $seite = $this->get('/app/export', self::ZEITRAUM);

        self::assertSame(200, $seite->status);
        self::assertStringContainsString('<code>Belege_2025.zip</code>', $seite->body);
        self::assertStringContainsString('<strong>4 Belege</strong>', $seite->body);
        self::assertStringContainsString('5 Dateien inkl. <code>index.csv</code>', $seite->body);
        self::assertStringContainsString('<form method="post" action="/app/export/zip"', $seite->body);
        self::assertStringContainsString('<input type="hidden" name="von" value="2025-01-01">', $seite->body);
        self::assertStringContainsString('<option value="' . $this->stamm['bauhaus'] . '">Bauhaus</option>', $seite->body, 'suppliers decrypted for the filter');
    }

    public function testFromAfterToIsRefused(): void
    {
        $this->belege();
        $falsch = ['von' => '2025-06-01', 'bis' => '2025-05-01'];

        $seite = $this->get('/app/export', $falsch);
        self::assertStringContainsString('Das Von-Datum liegt nach dem Bis-Datum.', $seite->body);
        self::assertStringNotContainsString('action="/app/export/zip"', $seite->body);

        $antwort = $this->post('/app/export/zip', $falsch);
        self::assertInstanceOf(Response::class, $antwort);
        self::assertSame(302, $antwort->status);
    }

    public function testAnEmptySelectionGivesANoticeInsteadOfAZip(): void
    {
        $this->belege();
        $leer = ['von' => '2023-01-01', 'bis' => '2023-12-31'];

        self::assertStringContainsString('Für diese Auswahl gibt es keine Belege.', $this->get('/app/export', $leer)->body);

        $antwort = $this->post('/app/export/zip', $leer);
        self::assertInstanceOf(Response::class, $antwort);
        self::assertSame(302, $antwort->status);
        self::assertSame('/app/export?von=2023-01-01&bis=2023-12-31&status=geprueft', $antwort->headers['Location'] ?? null, 'back to the same filter');
        self::assertStringContainsString('es wurde kein ZIP erstellt', $this->get('/app/export', $leer)->body);
        self::assertSame([], $this->exportAudits());
    }

    // --------------------------------------------- vault, CSRF, rights

    public function testWithoutTheVaultNothingIsReadOrExported(): void
    {
        $this->belege();

        $seite = $this->get('/app/export', self::ZEITRAUM, entsperrt: false);
        self::assertSame(200, $seite->status);
        self::assertStringContainsString('nur mit entsperrtem Tresor lesbar', $seite->body);
        self::assertStringNotContainsString('Bauhaus', $seite->body);
        self::assertStringNotContainsString('action="/app/export/zip"', $seite->body);

        $antwort = $this->post('/app/export/zip', self::ZEITRAUM, entsperrt: false);
        self::assertInstanceOf(Response::class, $antwort);
        self::assertSame(302, $antwort->status);
        self::assertSame([], $this->exportAudits());
    }

    public function testTheDownloadNeedsTheCsrfToken(): void
    {
        $this->belege();

        $antwort = $this->kernel()->handle(new Request(HttpMethod::Post, '/app/export/zip', post: self::ZEITRAUM, cookies: $this->tresorCookie(), ip: self::IP));

        self::assertInstanceOf(Response::class, $antwort);
        self::assertSame(302, $antwort->status);
        self::assertSame([], $this->exportAudits());
    }

    public function testTheExportIsAuditedWithFilterAndCounts(): void
    {
        $this->belege();

        self::bytes($this->download([...self::ZEITRAUM, 'lieferant' => (string) $this->stamm['bauhaus'], 'originale' => '1']));

        $eintraege = $this->exportAudits();
        self::assertCount(1, $eintraege);
        self::assertSame($this->userId, $eintraege[0]->userId);
        self::assertNull($eintraege[0]->entityId);
        self::assertSame([
            'von' => '2025-01-01',
            'bis' => '2025-12-31',
            'status' => 'geprueft',
            'kategorie' => null,
            'kostenstelle' => null,
            'lieferant' => $this->stamm['bauhaus'],
            'originale' => true,
            'belege' => 3,
            'dateien' => 7,
        ], $this->audit->details($eintraege[0], $this->tresor), 'filter, counts, user - no names');
    }

    /**
     * Steuerberater: export.zip without the admin area, narrowed to the
     * released period on the receipt date - whatever the form asks for.
     */
    public function testThePeriodScopeOfTheTaxAdvisorApplies(): void
    {
        $this->belege();
        $this->rolle(SystemRole::Steuerberater);
        new UserAccessRepository($this->pdo())->setScope($this->userId, new \DateTimeImmutable('2025-05-01'), null);

        self::assertSame(['B', 'C'], $this->exportiert([]));
    }

    /**
     * The supplier filter offers only suppliers of receipts within the
     * scope - a tax advisor released for 2025 does not learn the names of
     * suppliers the club only had in other years.
     */
    public function testTheSupplierChoiceStaysWithinTheScope(): void
    {
        $alt = new SupplierService($this->pdo(), new SupplierRepository($this->pdo()), new CategoryRepository($this->pdo()))
            ->anlegen($this->tresor, ['name' => 'Altlieferant Beispiel', 'rolle' => SupplierRole::Lieferant->value], new \DateTimeImmutable())->id;
        $this->beleg('2025-02-03', lieferant: $this->stamm['bauhaus']);
        $this->beleg('2023-06-01', lieferant: $alt);

        self::assertStringContainsString('>Altlieferant Beispiel</option>', $this->get('/app/export', self::ZEITRAUM)->body);
        self::assertStringNotContainsString('>Stadtwerke Musterstadt</option>', $this->get('/app/export', self::ZEITRAUM)->body, 'no receipt, no entry');

        $this->rolle(SystemRole::Steuerberater);
        new UserAccessRepository($this->pdo())->setScope($this->userId, new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2025-12-31'));
        $seite = $this->get('/app/export', self::ZEITRAUM)->body;

        self::assertStringContainsString('>Bauhaus</option>', $seite);
        self::assertStringNotContainsString('Altlieferant', $seite);
    }

    public function testTheBoardMayExportAClubOfficerMayNot(): void
    {
        $this->belege();

        $this->rolle(SystemRole::Vorstand);
        self::assertSame(200, $this->get('/app/export', self::ZEITRAUM)->status);
        self::assertSame(['A', 'D', 'B', 'C'], $this->exportiert([]));

        $this->rolle(SystemRole::Vereinsverantwortlicher);
        self::assertSame(403, $this->get('/app/export', self::ZEITRAUM)->status);
        $antwort = $this->post('/app/export/zip', self::ZEITRAUM);
        self::assertInstanceOf(Response::class, $antwort);
        self::assertSame(403, $antwort->status);
    }

    // ------------------------------------------------------ helpers

    /**
     * The receipts most tests share - A to G, by letter:
     * A, B, C, D checked or locked in 2025; E in review and F rejected in
     * 2025; G checked in 2024.
     *
     * @return array<string, int> letter => document id
     */
    private function belege(): array
    {
        $s = $this->stamm;
        $ids = [
            'A' => $this->beleg('2025-02-03', lieferant: $s['bauhaus'], kategorie: $s['platzpflege'], kostenstelle: $s['jugend'], brutto: 123456, nummer: 'RE-1'),
            'B' => $this->beleg('2025-05-17', DocumentStatus::Festgeschrieben, $s['bauhaus'], $s['platzpflege'], brutto: -5000, nummer: 'RE-2'),
            'C' => $this->beleg('2025-05-17', lieferant: $s['bauhaus'], kostenstelle: $s['senioren'], brutto: 1999, nummer: '=1+1', waehrung: 'USD'),
            'D' => $this->beleg('2025-03-20', kategorie: $s['spenden'], brutto: 700, richtung: InvoiceDirection::Einnahme),
            'E' => $this->beleg('2025-04-01', DocumentStatus::InPruefung, $s['stadtwerke']),
            'F' => $this->beleg('2025-04-02', DocumentStatus::Abgelehnt, $s['stadtwerke']),
            'G' => $this->beleg('2024-12-31', lieferant: $s['stadtwerke']),
        ];
        $this->buchstaben = array_flip($ids);

        return $ids;
    }

    /**
     * One receipt straight into the encrypted store: its originals (JPEG),
     * the working PDF unless $mitPdf is false, the invoice row.
     */
    private function beleg(
        string $datum,
        DocumentStatus $status = DocumentStatus::Geprueft,
        ?int $lieferant = null,
        ?int $kategorie = null,
        ?int $kostenstelle = null,
        int $brutto = 1000,
        string $nummer = '',
        string $waehrung = 'EUR',
        InvoiceDirection $richtung = InvoiceDirection::Ausgabe,
        bool $mitPdf = true,
        int $seiten = 1,
    ): int {
        $nr = ++$this->laufnummer;
        $originale = [];
        for ($seite = 1; $seite <= $seiten; $seite++) {
            $originale[] = $this->blobs()->storeString(self::originalText($nr, $seite), new BlobMeta(MagicBytes::JPEG, 'foto.jpg'), $this->tresor, BlobStorage::Fs)->id;
        }

        $documents = new DocumentRepository($this->pdo());
        $id = $documents->insert(DocumentSource::Intern, null, $originale, $this->tresor->sealDataKey(DataKey::generate()), new \DateTimeImmutable(), DocumentStatus::InPruefung, costCenterId: $kostenstelle, createdBy: $this->userId);
        if ($mitPdf) {
            $pdf = $this->blobs()->storeString(self::pdfText($nr), new BlobMeta(MagicBytes::PDF, 'beleg.pdf'), $this->tresor, BlobStorage::Fs);
            self::assertTrue($documents->setzePdfBlob($id, $pdf->id));
        }
        $this->pdo()->prepare('UPDATE document SET status = ? WHERE id = ?')->execute([$status->value, $id]);
        $this->nummern[$id] = $nr;

        $schluessel = DataKey::generate();
        $rechnungen = new InvoiceRepository($this->pdo());
        $rechnung = $rechnungen->insert(
            $id,
            new InvoiceStructure(InvoiceType::Rechnung, $richtung, $lieferant, new \DateTimeImmutable($datum), null, null, null, $kategorie, $kostenstelle),
            $this->tresor->sealDataKey($schluessel),
            null,
            $this->userId,
            new \DateTimeImmutable(),
        );
        $daten = new InvoiceData($nummer, $brutto, null, [], $waehrung, 'Zweck ' . self::MARKER, '');
        $rechnungen->setData($rechnung, FieldCipher::encrypt(
            $schluessel,
            json_encode($daten->toPayload(), JSON_THROW_ON_ERROR),
            new FieldContext('invoice', $rechnung, 'data_enc'),
        ));

        return $id;
    }

    private static function pdfText(int $nr): string
    {
        return '%PDF-1.4 Arbeitskopie ' . $nr . ' ' . self::MARKER;
    }

    private static function originalText(int $nr, int $seite): string
    {
        return 'JPEG Original ' . $nr . '/' . $seite . ' ' . self::MARKER;
    }

    private function pdfInhalt(int $documentId): string
    {
        return self::pdfText($this->nummern[$documentId]);
    }

    private function originalInhalt(int $documentId, int $seite): string
    {
        return self::originalText($this->nummern[$documentId], $seite);
    }

    private function pdfBlob(int $documentId): int
    {
        $stmt = $this->pdo()->prepare('SELECT pdf_blob_id FROM document WHERE id = ?');
        $stmt->execute([$documentId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<int>
     */
    private function originalBlobs(int $documentId): array
    {
        $stmt = $this->pdo()->prepare('SELECT original_blob_ids FROM document WHERE id = ?');
        $stmt->execute([$documentId]);

        return array_map(intval(...), json_decode((string) $stmt->fetchColumn(), true, flags: JSON_THROW_ON_ERROR));
    }

    private function fsPfad(int $blobId): string
    {
        $name = (string) new BlobRepository($this->pdo())->find($blobId)?->fsName;

        return $this->blobDir() . '/' . substr($name, 0, 2) . '/' . $name;
    }

    /**
     * The letters (see belege()) of the receipts a download with these
     * fields exports, in ZIP order. Every receipt of belege() has exactly
     * one working PDF, so the PDF's content tells which receipt it is.
     *
     * @param array<string, string> $felder on top of ZEITRAUM
     *
     * @return list<string>
     */
    private function exportiert(array $felder): array
    {
        $nachInhalt = [];
        foreach ($this->buchstaben as $id => $buchstabe) {
            $nachInhalt[$this->pdfInhalt($id)] = $buchstabe;
        }

        $buchstaben = [];
        foreach (ZipLeser::inhalte(self::bytes($this->download([...self::ZEITRAUM, ...$felder]))) as $name => $inhalt) {
            if (!str_ends_with($name, '/index.csv')) {
                $buchstaben[] = $nachInhalt[$inhalt] ?? 'unbekannt: ' . $name;
            }
        }

        return $buchstaben;
    }

    /**
     * @return list<\App\Domain\AuditEntry>
     */
    private function exportAudits(): array
    {
        return array_values(array_filter(
            new AuditLogRepository($this->pdo())->page(new AuditFilter(), null, 50),
            static fn(\App\Domain\AuditEntry $e): bool => $e->action === AuditAction::ExportErstellt->value,
        ));
    }

    private function kategorie(string $name): int
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM category WHERE name = ?');
        $stmt->execute([$name]);

        return (int) $stmt->fetchColumn();
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

    private function logDatei(): string
    {
        return $this->wurzel . '/shared/var/log/app.log';
    }

    private function blobs(): BlobService
    {
        $repository = new BlobRepository($this->pdo());

        return new BlobService($repository, new DbBlobBackend($repository), new FsBlobBackend($this->blobDir()));
    }

    private static function bytes(StreamResponse $antwort): string
    {
        $bytes = '';
        foreach ($antwort->chunks as $stueck) {
            $bytes .= $stueck;
        }

        return $bytes;
    }

    /**
     * @param array<string, string> $felder
     */
    private function download(array $felder): StreamResponse
    {
        $antwort = $this->post('/app/export/zip', $felder);
        self::assertInstanceOf(StreamResponse::class, $antwort, 'the download streams');

        return $antwort;
    }

    /**
     * @param array<string, string> $felder
     */
    private function post(string $pfad, array $felder, bool $entsperrt = true): ResponseInterface
    {
        // A download closed the session (Session::schliessen()); the next
        // request opens it again, as every request does - before the vault
        // cookie below is written into it.
        new Session()->start();

        return $this->kernel()->handle(new Request(
            HttpMethod::Post,
            $pfad,
            post: [...$felder, '_csrf' => new Session()->csrfToken()],
            cookies: $entsperrt ? $this->tresorCookie() : [],
            ip: self::IP,
        ));
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
        $export = function () use ($view, $pdo): ExportController {
            $kategorien = new CategoryRepository($pdo);
            $kostenstellen = new CostCenterRepository($pdo);
            $lieferanten = new SupplierService($pdo, new SupplierRepository($pdo), $kategorien);

            return new ExportController(
                $view,
                new Session(),
                new SessionVault(),
                new ZipExport(new InvoiceRepository($pdo), $this->blobs(), $lieferanten, $kategorien, $kostenstellen, new SettingRepository($pdo), new FileLogger($this->logDatei())),
                $kategorien,
                $kostenstellen,
                $this->audit,
            );
        };
        $unerreichbar = static fn(): never => throw new \LogicException('Diese Route gehört nicht zu diesem Test.');

        // Every controller closure of app/src/routes.php in order: the
        // guard second, the export last (36th).
        $controller = array_fill(0, 36, $unerreichbar);
        $controller[1] = $guard;
        $controller[35] = $export;

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
