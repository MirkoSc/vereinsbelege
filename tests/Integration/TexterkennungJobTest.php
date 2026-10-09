<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\BlobMeta;
use App\Domain\BlobStorage;
use App\Domain\DocumentSource;
use App\Domain\DocumentStatus;
use App\Domain\JobExecutor;
use App\Domain\OcrStatus;
use App\Domain\SystemRole;
use App\Repository\BlobRepository;
use App\Repository\DocumentArtifactRepository;
use App\Repository\DocumentRepository;
use App\Repository\JobRepository;
use App\Repository\RoleRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Document\ERechnungAuszug;
use App\Service\Document\Texterkennung;
use App\Service\Job\JobLaufStatus;
use App\Service\Job\JobRunner;
use App\Service\Migration\Migrator;
use App\Service\Processing\ERechnung\ERechnungBefund;
use App\Service\Processing\ERechnung\ERechnungSyntax;
use App\Service\Processing\PdfAusBildern;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Service\Upload\MagicBytes;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FakeJpeg;
use App\Tests\Support\PdfBaukasten;

/**
 * The `extract_text` job (issue #45/M7-3, docs/spec/03-erfassung-und-ki.md
 * sections 3 and 5) through the real job runner and schema: a usable text
 * layer replaces the page images for the AI (`ocr_status` `fertig`,
 * Texterkennung::textFuerKi()), anything else leaves them needed
 * (`uebersprungen`); one PDF per step, nothing readable outside the
 * encrypted artifact - and the migration that queues it for open documents
 * received before. Since issue #46/M7-4 the same job reads structured
 * e-invoices: an XRechnung XML original and the invoice XML a ZUGFeRD PDF
 * carries, stored as an `e_rechnung` artifact - without any AI.
 */
final class TexterkennungJobTest extends DatabaseTestCase
{
    private const string FIXTURES = __DIR__ . '/../fixtures/pdf';

    private const string E_RECHNUNGEN = __DIR__ . '/../fixtures/erechnung';

    private Vault $tresor;
    private int $userId;
    private string $blobDir;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->blobDir = sys_get_temp_dir() . '/vb_texterkennung_blobs_' . uniqid('', true);
        mkdir($this->blobDir, 0775, true);

        $crypto = new ServerCrypto((string) base64_decode(self::configData()['server_key'], true));
        $this->tresor = Vault::create();
        new VaultRepository($this->pdo())->insert($this->tresor->publicKey());

        $this->userId = new UserRepository($this->pdo())->insert(
            $crypto->encrypt('finanzen@example.org'),
            random_bytes(32),
            $crypto->encrypt('Fritz Finanzen'),
            'hash',
            mfaRequired: false,
        );
        $rollen = new RoleRepository($this->pdo());
        $finanzen = $rollen->findSystem(SystemRole::Finanzen)?->id;
        assert($finanzen !== null);
        $rollen->assignToUser($this->userId, [$finanzen]);
    }

    protected function tearDown(): void
    {
        // FsBlobBackend: <blobDir>/<two characters>/<name>.
        foreach (glob($this->blobDir . '/*/*') ?: [] as $datei) {
            unlink($datei);
        }
        foreach (glob($this->blobDir . '/*') ?: [] as $eintrag) {
            is_dir($eintrag) ? rmdir($eintrag) : unlink($eintrag);
        }
        rmdir($this->blobDir);
        parent::tearDown();
    }

    public function testADigitalPdfIsEnoughForTheAiOnEitherBackend(): void
    {
        $pdf = self::fixture('rechnung-standardschrift.pdf');
        $imDateisystem = $this->dokument([[MagicBytes::PDF, $pdf]], BlobStorage::Fs);
        $inDerDatenbank = $this->dokument([[MagicBytes::PDF, $pdf]], BlobStorage::Db);

        $this->allesAbarbeiten();

        foreach ([$imDateisystem, $inDerDatenbank] as $id) {
            self::assertSame('fertig', $this->dokumentZeile($id)['ocr_status']);
            self::assertSame('fertig', $this->job($id)['status']);
            $text = $this->textFuerKi($id);
            self::assertNotNull($text);
            self::assertStringContainsString("Gesamtbetrag 119,00 €\n", $text);
            self::assertStringContainsString('IBAN DE02 1203 0000 0000 2020 51', $text);

            $artefakte = $this->artefakte($id);
            self::assertCount(1, $artefakte);
            self::assertSame('text', $artefakte[0]['kind']);
            self::assertSame(0, (int) $artefakte[0]['seq']);
            self::assertSame('session', $artefakte[0]['producer']);
            self::assertSame((int) $this->job($id)['id'], (int) $artefakte[0]['job_id']);
            self::assertNull($artefakte[0]['blob_id']);
        }
    }

    /**
     * The text is club data: only ever in the artifact's `data_enc`, under
     * its own data key - never in the job row, never in plaintext.
     */
    public function testNothingReadableOutsideTheEncryptedArtifact(): void
    {
        $id = $this->dokument([
            [MagicBytes::PDF, self::fixture('rechnung-standardschrift.pdf')],
            [MagicBytes::PDF, self::fixture('rechnung-eingebettete-schrift.pdf')],
        ], BlobStorage::Fs);

        self::assertSame(JobLaufStatus::Gearbeitet, $this->schritt());
        self::assertSame(JobLaufStatus::Gearbeitet, $this->schritt());
        $job = $this->job($id);
        self::assertSame('offen', $job['status']);
        self::assertSame('quelle', $job['step']);
        $state = json_decode((string) $job['state'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['quellen', 'brauchbar'], array_keys($state));
        self::assertSame([true], $state['brauchbar']);
        self::assertNull($job['last_error']);

        $artefakt = $this->artefakte($id)[0];
        foreach (['Musterfirma', 'Gesamtbetrag', '119,00', 'DE02'] as $klartext) {
            self::assertStringNotContainsString($klartext, (string) $job['state']);
            self::assertStringNotContainsString($klartext, (string) $artefakt['data_enc']);
            self::assertStringNotContainsString($klartext, (string) $artefakt['dek_sealed']);
        }
        // Its own data key, not the document's (02, Stand M4-8).
        self::assertNotSame($this->dokumentZeile($id)['dek_sealed'], $artefakt['dek_sealed']);
    }

    public function testOnePdfPerStepInTheOrderOfTheOriginals(): void
    {
        $id = $this->dokument([
            [MagicBytes::PDF, self::fixture('rechnung-standardschrift.pdf')],
            [MagicBytes::PDF, self::fixture('rechnung-eingebettete-schrift.pdf')],
        ], BlobStorage::Fs);

        self::assertSame(JobLaufStatus::Gearbeitet, $this->schritt()); // pruefen
        self::assertSame(JobLaufStatus::Gearbeitet, $this->schritt()); // first PDF
        self::assertCount(1, $this->artefakte($id));
        self::assertSame('ausstehend', $this->dokumentZeile($id)['ocr_status']);
        self::assertNull($this->textFuerKi($id), 'nothing for the AI before the job is done');

        self::assertSame(JobLaufStatus::Gearbeitet, $this->schritt()); // second PDF
        self::assertSame('fertig', $this->job($id)['status']);
        self::assertSame([0, 1], array_map(static fn (array $a): int => (int) $a['seq'], $this->artefakte($id)));
        self::assertSame(JobLaufStatus::Leer, $this->schritt());

        $text = $this->textFuerKi($id);
        self::assertNotNull($text);
        [$erstes, $zweites] = explode("\f", $text);
        self::assertStringContainsString('Rechnung Nr. 2026-0815', $erstes);
        self::assertStringContainsString('Rechnung Nr. RE-2026-0042', $zweites);
    }

    /** A scan - here what PdfAusBildern makes of photographed pages - needs its page images. */
    public function testAScannedPdfNeedsThePageImages(): void
    {
        $scan = implode('', iterator_to_array(PdfAusBildern::erzeuge([FakeJpeg::bauen(breite: 400, hoehe: 600)]), false));
        $id = $this->dokument([[MagicBytes::PDF, $scan]], BlobStorage::Fs);

        $this->allesAbarbeiten();

        self::assertSame('uebersprungen', $this->dokumentZeile($id)['ocr_status']);
        self::assertSame('fertig', $this->job($id)['status']);
        self::assertCount(1, $this->artefakte($id), 'the result is kept either way');
        self::assertNull($this->textFuerKi($id));
    }

    public function testADocumentOfImagesHasNoTextLayer(): void
    {
        $id = $this->dokument([[MagicBytes::JPEG, FakeJpeg::bauen(breite: 40, hoehe: 60)]], BlobStorage::Fs);

        $this->allesAbarbeiten();

        self::assertSame('uebersprungen', $this->job($id)['status']);
        self::assertSame('uebersprungen', $this->dokumentZeile($id)['ocr_status']);
        self::assertSame([], $this->artefakte($id));
        self::assertNull($this->textFuerKi($id));
    }

    /** A photographed page next to a digital PDF: the photo still needs the image route. */
    public function testImagesNextToADigitalPdfStillNeedThePageImages(): void
    {
        $id = $this->dokument([
            [MagicBytes::JPEG, FakeJpeg::bauen(breite: 40, hoehe: 60)],
            [MagicBytes::PDF, self::fixture('rechnung-standardschrift.pdf')],
        ], BlobStorage::Fs);

        $this->allesAbarbeiten();

        self::assertSame('uebersprungen', $this->dokumentZeile($id)['ocr_status']);
        self::assertCount(1, $this->artefakte($id));
        self::assertNull($this->textFuerKi($id));
    }

    /** What is wrong with the file is no error of the job - those go the image route. */
    public function testADamagedOrEncryptedPdfDoesNotFailTheJob(): void
    {
        $verschluesselt = new PdfBaukasten();
        $katalog = $verschluesselt->objekt('<< /Type /Catalog >>');
        $kaputt = $this->dokument([[MagicBytes::PDF, "%PDF-1.7\n\x00\x01 nichts"]], BlobStorage::Fs);
        $gesperrt = $this->dokument([[MagicBytes::PDF, $verschluesselt->pdf($katalog, '/Encrypt << /Filter /Standard >>')]], BlobStorage::Fs);

        $this->allesAbarbeiten();

        foreach ([$kaputt, $gesperrt] as $id) {
            self::assertSame('fertig', $this->job($id)['status']);
            self::assertSame('uebersprungen', $this->dokumentZeile($id)['ocr_status']);
        }
    }

    /**
     * A step cut off after storing its artifact but before the job row
     * moved on runs again with the same state: it finds what it stored and
     * stores nothing twice.
     */
    public function testARepeatedStepStoresNothingTwice(): void
    {
        $id = $this->dokument([[MagicBytes::PDF, self::fixture('rechnung-standardschrift.pdf')]], BlobStorage::Fs);
        self::assertSame(JobLaufStatus::Gearbeitet, $this->schritt()); // pruefen

        $jobs = new JobRepository($this->pdo());
        $job = $jobs->claim(JobExecutor::Session, 'test', 60, Texterkennung::JOB_TYP);
        self::assertNotNull($job);
        $erstes = $this->handler()->schritt($job, $this->tresor, new \DateTimeImmutable());
        $zweites = $this->handler()->schritt($job, $this->tresor, new \DateTimeImmutable());

        self::assertEquals($erstes, $zweites);
        self::assertCount(1, $this->artefakte($id));
        self::assertSame('fertig', $this->dokumentZeile($id)['ocr_status']);
    }

    /** A document whose text recognition is decided is done at once; a new run replaces the old text. */
    public function testADecidedDocumentIsDoneAndANewRunReplacesTheOldText(): void
    {
        $id = $this->dokument([[MagicBytes::PDF, self::fixture('rechnung-standardschrift.pdf')]], BlobStorage::Fs);
        $this->allesAbarbeiten();
        $alterLauf = (int) $this->job($id)['id'];

        new JobRepository($this->pdo())->enqueue(Texterkennung::JOB_TYP, JobExecutor::Session, 'document', $id);
        $this->allesAbarbeiten();
        self::assertSame([$alterLauf], array_map(static fn (array $a): int => (int) $a['job_id'], $this->artefakte($id)));

        new DocumentRepository($this->pdo())->setzeOcrStatus($id, OcrStatus::Ausstehend);
        new JobRepository($this->pdo())->enqueue(Texterkennung::JOB_TYP, JobExecutor::Session, 'document', $id);
        $this->allesAbarbeiten();
        $artefakte = $this->artefakte($id);
        self::assertCount(1, $artefakte);
        self::assertNotSame($alterLauf, (int) $artefakte[0]['job_id']);
        self::assertNotNull($this->textFuerKi($id));
    }

    public function testAnXRechnungIsReadWithoutAiOnEitherBackend(): void
    {
        $cii = $this->dokument([[MagicBytes::XML, self::eRechnungFixture('xrechnung-cii.xml')]], BlobStorage::Fs);
        $ubl = $this->dokument([[MagicBytes::XML, self::eRechnungFixture('xrechnung-ubl.xml')]], BlobStorage::Db);

        $this->allesAbarbeiten();

        foreach ([$cii => ['RE-2026-0042', '172.50', ERechnungSyntax::Cii], $ubl => ['2026/1234', '297.50', ERechnungSyntax::Ubl]] as $id => [$nummer, $brutto, $syntax]) {
            self::assertSame('fertig', $this->job($id)['status']);
            self::assertSame('fertig', $this->dokumentZeile($id)['ocr_status'], 'no page images to evaluate');
            self::assertNull($this->textFuerKi($id), 'no text layer - the fields are already there');

            $artefakte = $this->artefakte($id);
            self::assertCount(1, $artefakte);
            self::assertSame('e_rechnung', $artefakte[0]['kind']);
            self::assertSame('session', $artefakte[0]['producer']);
            self::assertNull($artefakte[0]['blob_id']);

            $auszug = $this->eRechnung($id);
            self::assertNotNull($auszug);
            self::assertTrue($auszug->gelesen());
            self::assertSame($syntax, $auszug->syntax);
            self::assertStringContainsString('xrechnung_3.0', $auszug->profil);
            self::assertNotNull($auszug->extraktion);
            self::assertSame($nummer, $auszug->extraktion['invoice_number']);
            self::assertSame($brutto, $auszug->extraktion['total_gross']);
        }
    }

    public function testAZugferdPdfCarriesItsInvoiceNextToItsText(): void
    {
        $id = $this->dokument([[MagicBytes::PDF, self::eRechnungFixture('zugferd-en16931.pdf')]], BlobStorage::Fs);

        $this->allesAbarbeiten();

        self::assertSame('fertig', $this->dokumentZeile($id)['ocr_status']);
        self::assertSame(['e_rechnung', 'text'], array_map(static fn (array $a): string => (string) $a['kind'], $this->artefakte($id)));
        self::assertStringContainsString('Rechnung RE-2026-0043', (string) $this->textFuerKi($id));
        $auszug = $this->eRechnung($id);
        self::assertNotNull($auszug);
        self::assertSame('urn:cen.eu:en16931:2017', $auszug->profil);
        self::assertSame('RE-2026-0043', $auszug->extraktion['invoice_number'] ?? null);
        self::assertSame('DE123456789', $auszug->extraktion['supplier']['vat_id'] ?? null);
    }

    /** The invoice is club data like the text: only in the artifact, under its own key. */
    public function testTheInvoiceIsNotReadableOutsideItsArtifact(): void
    {
        $id = $this->dokument([
            [MagicBytes::XML, self::eRechnungFixture('xrechnung-cii.xml')],
            [MagicBytes::PDF, self::eRechnungFixture('zugferd-en16931.pdf')],
        ], BlobStorage::Fs);

        self::assertSame(JobLaufStatus::Gearbeitet, $this->schritt()); // pruefen
        self::assertSame(JobLaufStatus::Gearbeitet, $this->schritt()); // the XML
        $job = $this->job($id);
        $state = json_decode((string) $job['state'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['quellen', 'brauchbar'], array_keys($state));
        self::assertSame([true], $state['brauchbar']);
        $this->allesAbarbeiten();

        $job = $this->job($id);
        foreach ($this->artefakte($id) as $artefakt) {
            foreach (['Muster', 'RE-2026', '172.50', '17250', 'DE02', 'DE123456789', 'cii', 'gelesen'] as $klartext) {
                self::assertStringNotContainsString($klartext, (string) $job['state']);
                self::assertStringNotContainsString($klartext, (string) $artefakt['data_enc']);
            }
            self::assertNotSame($this->dokumentZeile($id)['dek_sealed'], $artefakt['dek_sealed']);
        }
        self::assertSame('fertig', $this->dokumentZeile($id)['ocr_status']);
        self::assertSame(['e_rechnung', 'e_rechnung', 'text'], array_map(static fn (array $a): string => (string) $a['kind'], $this->artefakte($id)));
        self::assertSame('RE-2026-0042', $this->eRechnung($id)?->extraktion['invoice_number'] ?? null, 'the first source wins');
    }

    /** A damaged or unsupported e-invoice is no error of the job - the person captures it. */
    public function testADamagedXmlDoesNotFailTheJob(): void
    {
        $kaputt = $this->dokument([[MagicBytes::XML, substr(self::eRechnungFixture('xrechnung-cii.xml'), 0, 3000)]], BlobStorage::Fs);
        $alt = $this->dokument([[MagicBytes::XML, self::eRechnungFixture('zugferd-1.xml')]], BlobStorage::Fs);

        $this->allesAbarbeiten();

        foreach ([$kaputt => ERechnungBefund::Defekt, $alt => ERechnungBefund::NichtUnterstuetzt] as $id => $befund) {
            self::assertSame('fertig', $this->job($id)['status']);
            self::assertSame('uebersprungen', $this->dokumentZeile($id)['ocr_status']);
            $auszug = $this->eRechnung($id);
            self::assertNotNull($auszug);
            self::assertSame($befund, $auszug->befund);
            self::assertFalse($auszug->gelesen());
            self::assertNull($auszug->extraktion);
        }
    }

    public function testAPdfWithoutInvoiceHasNoInvoiceArtifact(): void
    {
        $id = $this->dokument([[MagicBytes::PDF, self::fixture('rechnung-standardschrift.pdf')]], BlobStorage::Fs);

        $this->allesAbarbeiten();

        self::assertSame(['text'], array_map(static fn (array $a): string => (string) $a['kind'], $this->artefakte($id)));
        self::assertNull($this->eRechnung($id));
    }

    /**
     * A ZUGFeRD step cut off anywhere runs again with the same state:
     * after both artifacts, or after the invoice but before the text.
     */
    public function testARepeatedZugferdStepStoresNothingTwice(): void
    {
        $id = $this->dokument([[MagicBytes::PDF, self::eRechnungFixture('zugferd-en16931.pdf')]], BlobStorage::Fs);
        self::assertSame(JobLaufStatus::Gearbeitet, $this->schritt()); // pruefen

        $job = new JobRepository($this->pdo())->claim(JobExecutor::Session, 'test', 60, Texterkennung::JOB_TYP);
        self::assertNotNull($job);
        $erstes = $this->handler()->schritt($job, $this->tresor, new \DateTimeImmutable());
        $zweites = $this->handler()->schritt($job, $this->tresor, new \DateTimeImmutable());
        self::assertEquals($erstes, $zweites);
        self::assertCount(2, $this->artefakte($id));

        $this->pdo()->prepare("DELETE FROM document_artifact WHERE document_id = ? AND kind = 'text'")->execute([$id]);
        $drittes = $this->handler()->schritt($job, $this->tresor, new \DateTimeImmutable());
        self::assertEquals($erstes, $drittes);
        self::assertSame(['e_rechnung', 'text'], array_map(static fn (array $a): string => (string) $a['kind'], $this->artefakte($id)));
        self::assertSame('RE-2026-0043', $this->eRechnung($id)?->extraktion['invoice_number'] ?? null);
    }

    public function testANewRunReplacesTheOldInvoice(): void
    {
        $id = $this->dokument([[MagicBytes::XML, self::eRechnungFixture('xrechnung-ubl.xml')]], BlobStorage::Fs);
        $this->allesAbarbeiten();
        $alterLauf = (int) $this->job($id)['id'];

        new DocumentRepository($this->pdo())->setzeOcrStatus($id, OcrStatus::Ausstehend);
        new JobRepository($this->pdo())->enqueue(Texterkennung::JOB_TYP, JobExecutor::Session, 'document', $id);
        $this->allesAbarbeiten();

        $artefakte = $this->artefakte($id);
        self::assertCount(1, $artefakte);
        self::assertNotSame($alterLauf, (int) $artefakte[0]['job_id']);
        self::assertTrue($this->eRechnung($id)?->gelesen());
    }

    /** "Felder werden ohne KI-Aufruf übernommen": the job has nothing it could call an AI with. */
    public function testTheJobHasNoAiAtHand(): void
    {
        $konstruktor = new \ReflectionMethod(Texterkennung::class, '__construct');
        foreach ($konstruktor->getParameters() as $parameter) {
            self::assertStringNotContainsString('\\Ki\\', (string) $parameter->getType());
            self::assertStringNotContainsString('Llm', (string) $parameter->getType());
        }
    }

    /** `data_enc` takes the text of a long invoice (migrations/026: MEDIUMBLOB). */
    public function testALongTextFitsTheArtifact(): void
    {
        $pdf = new PdfBaukasten();
        $wurzel = $pdf->reservieren();
        $schrift = $pdf->objekt('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');
        $kinder = [];
        for ($seite = 1; $seite <= 30; $seite++) {
            $zeilen = '';
            for ($zeile = 0; $zeile < 40; $zeile++) {
                $zeilen .= sprintf('(Position %d.%d Vereinsbedarf Trainingsmaterial 12,50 EUR) Tj 0 -14 Td ', $seite, $zeile);
            }
            $inhalt = $pdf->stream('BT /F1 10 Tf 72 800 Td ' . $zeilen . 'ET', komprimiert: true);
            $kinder[] = $pdf->objekt(sprintf('<< /Type /Page /Parent %d 0 R /Contents %d 0 R >>', $wurzel, $inhalt)) . ' 0 R';
        }
        $pdf->objekt(sprintf('<< /Type /Pages /Kids [%s] /Count 30 /Resources << /Font << /F1 %d 0 R >> >> >>', implode(' ', $kinder), $schrift), $wurzel);
        $katalog = $pdf->objekt(sprintf('<< /Type /Catalog /Pages %d 0 R >>', $wurzel));
        $id = $this->dokument([[MagicBytes::PDF, $pdf->pdf($katalog)]], BlobStorage::Fs);

        $this->allesAbarbeiten();

        self::assertGreaterThan(60_000, strlen((string) $this->artefakte($id)[0]['data_enc']));
        $text = $this->textFuerKi($id);
        self::assertNotNull($text);
        self::assertCount(30, explode("\f", $text));
        self::assertStringContainsString('Position 30.39 Vereinsbedarf Trainingsmaterial 12,50 EUR', $text);
    }

    /**
     * migrations/026 queues one job for every open document that existed
     * before - applied on top of a database at 025 with documents in it.
     */
    public function testTheMigrationQueuesAJobForEveryOpenDocument(): void
    {
        $this->dropAllTables($this->pdo());
        $bis025 = sys_get_temp_dir() . '/vb_migrations_025_' . uniqid('', true);
        mkdir($bis025);
        foreach (glob($this->migrationsDir() . '/*.sql') ?: [] as $datei) {
            if ((int) substr(basename($datei), 0, 3) <= 25) {
                copy($datei, $bis025 . '/' . basename($datei));
            }
        }

        try {
            new Migrator($this->pdo(), $bis025)->migrate();
            new VaultRepository($this->pdo())->insert($this->tresor->publicKey());
            $documents = new DocumentRepository($this->pdo());
            $alt = [];
            foreach (DocumentStatus::cases() as $status) {
                $alt[$status->value] = $documents->insert(DocumentSource::Einreichung, null, [], $this->tresor->sealDataKey(DataKey::generate()), new \DateTimeImmutable(), $status);
            }
        } finally {
            array_map(unlink(...), glob($bis025 . '/*.sql') ?: []);
            rmdir($bis025);
        }

        $ergebnis = new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        self::assertSame(25, $ergebnis->fromVersion);
        $offen = ['eingegangen', 'bereit_zur_auswertung', 'wiedervorlage', 'ki_fehler'];
        $jobs = $this->pdo()->query("SELECT * FROM job WHERE typ = 'extract_text' ORDER BY ref_id")->fetchAll();
        $erwartet = array_map(static fn (string $s): int => $alt[$s], $offen);
        sort($erwartet);
        self::assertSame($erwartet, array_map(static fn (array $j): int => (int) $j['ref_id'], $jobs));
        foreach ($jobs as $job) {
            self::assertSame('document', $job['ref_type']);
            self::assertSame('session', $job['executor']);
            self::assertSame('offen', $job['status']);
            self::assertSame('', $job['step']);
        }
        foreach ($alt as $status => $id) {
            self::assertSame(in_array($status, $offen, true) ? 'ausstehend' : 'keine', $this->dokumentZeile($id)['ocr_status'], $status);
        }

        $spalte = $this->pdo()->query("SHOW COLUMNS FROM document_artifact LIKE 'data_enc'")->fetch();
        self::assertIsArray($spalte);
        self::assertSame('mediumblob', strtolower((string) $spalte['Type']));
    }

    // ------------------------------------------------------------ helpers

    /**
     * A document with these originals and its `extract_text` job, as
     * App\Service\Submission\SubmissionService queues it.
     *
     * @param list<array{string, string}> $originale [MIME type, bytes]
     */
    private function dokument(array $originale, BlobStorage $storage): int
    {
        $blobIds = [];
        foreach ($originale as [$typ, $inhalt]) {
            $blobIds[] = $this->blobService()->storeString($inhalt, new BlobMeta($typ, 'original'), $this->tresor, $storage)->id;
        }

        $id = new DocumentRepository($this->pdo())->insert(
            DocumentSource::Einreichung,
            null,
            $blobIds,
            $this->tresor->sealDataKey(DataKey::generate()),
            new \DateTimeImmutable(),
            ocrStatus: OcrStatus::Ausstehend,
        );
        new JobRepository($this->pdo())->enqueue(Texterkennung::JOB_TYP, JobExecutor::Session, 'document', $id);

        return $id;
    }

    private function handler(): Texterkennung
    {
        return new Texterkennung(new DocumentRepository($this->pdo()), new DocumentArtifactRepository($this->pdo()), $this->blobService());
    }

    private function schritt(): JobLaufStatus
    {
        $runner = new JobRunner(new JobRepository($this->pdo()), [$this->handler()]);

        return $runner->schritt(new UserAccessRepository($this->pdo())->berechtigungen($this->userId), $this->tresor, new \DateTimeImmutable())->status;
    }

    private function allesAbarbeiten(): void
    {
        for ($i = 0; $i < 50 && $this->schritt() === JobLaufStatus::Gearbeitet; $i++) {
        }
        self::assertSame(0, (int) $this->pdo()->query("SELECT COUNT(*) FROM job WHERE status IN ('offen', 'laeuft')")->fetchColumn());
        self::assertSame(0, (int) $this->pdo()->query("SELECT COUNT(*) FROM job WHERE status = 'fehler'")->fetchColumn());
    }

    private function eRechnung(int $id): ?ERechnungAuszug
    {
        $document = new DocumentRepository($this->pdo())->find($id);
        self::assertNotNull($document);

        return $this->handler()->eRechnung($document, $this->tresor);
    }

    private static function eRechnungFixture(string $name): string
    {
        $daten = file_get_contents(self::E_RECHNUNGEN . '/' . $name);
        self::assertIsString($daten);

        return $daten;
    }

    private function textFuerKi(int $id): ?string
    {
        $document = new DocumentRepository($this->pdo())->find($id);
        self::assertNotNull($document);

        return $this->handler()->textFuerKi($document, $this->tresor);
    }

    /**
     * The newest job of the document.
     *
     * @return array<string, mixed>
     */
    private function job(int $documentId): array
    {
        $stmt = $this->pdo()->prepare("SELECT * FROM job WHERE typ = 'extract_text' AND ref_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$documentId]);
        $zeile = $stmt->fetch();
        self::assertIsArray($zeile);

        return $zeile;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function artefakte(int $documentId): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM document_artifact WHERE document_id = ? ORDER BY seq, id');
        $stmt->execute([$documentId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>
     */
    private function dokumentZeile(int $id): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM document WHERE id = ?');
        $stmt->execute([$id]);
        $zeile = $stmt->fetch();
        self::assertIsArray($zeile);

        return $zeile;
    }

    private static function fixture(string $name): string
    {
        $pdf = file_get_contents(self::FIXTURES . '/' . $name);
        self::assertIsString($pdf);

        return $pdf;
    }

    private function blobService(): BlobService
    {
        $repository = new BlobRepository($this->pdo());

        return new BlobService($repository, new DbBlobBackend($repository), new FsBlobBackend($this->blobDir));
    }
}
