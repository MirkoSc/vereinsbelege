<?php

declare(strict_types=1);

namespace App\Tests\Worker;

use App\Domain\ArtifactKind;
use App\Domain\BlobMeta;
use App\Domain\DocumentArtifact;
use App\Domain\DocumentSource;
use App\Domain\DocumentStatus;
use App\Domain\JobExecutor;
use App\Domain\JobStatus;
use App\Domain\OcrStatus;
use App\Domain\WorkerFaehigkeit;
use App\Domain\WorkerStatus;
use App\Repository\BlobRepository;
use App\Service\Crypto\DataKey;
use App\Service\Document\KiAuslesung;
use App\Service\Document\Texterkennung;
use App\Service\Ki\KiAbfrage;
use App\Service\Ki\KiFaehigkeiten;
use App\Service\Ki\KiPrompts;
use App\Service\Processing\KiAuslese\Auslesung;
use App\Service\Upload\MagicBytes;
use App\Service\Worker\WorkerProtokoll;
use App\Service\Worker\WorkerRouting;
use App\Service\Worker\WorkerUebernahme;
use App\Tests\Service\Worker\WorkerTestCase;
use App\Tests\Support\FakeJpeg;
use App\Tests\Support\FakeLlmClient;
use App\Tests\Support\KiAuslesungFabrik;
use App\Tests\Worker\Support\FakeWerkzeuge;
use App\Tests\Worker\Support\InProzessHttp;
use App\Worker\Abbruch;
use App\Worker\ApiClient;
use App\Worker\Arbeitsverzeichnis;
use App\Worker\Identitaet;
use App\Worker\KiKonfiguration;
use App\Worker\Kopplung;
use App\Worker\Log;
use App\Worker\Schleife;
use App\Worker\Schritt\KiAuslesen;
use App\Worker\Schritt\Ocr;
use App\Worker\Schritt\SeitenRendern;

/**
 * bin/worker.php end to end against the web host's own code (issue #54,
 * docs/spec/07-worker.md): the worker program's requests go through the
 * real signature check into the real WorkerApiController, its results are
 * taken over by the real WorkerUebernahme. Only the command-line tools and
 * the AI are stand-ins.
 */
final class WorkerProgrammTest extends WorkerTestCase
{
    private const string BASIS = 'https://belege.test';

    private int $workerId = 0;

    private string $arbeitBasis = '';

    private Abbruch $abbruch;

    private FakeWerkzeuge $werkzeuge;

    private FakeLlmClient $llm;

    private KiKonfiguration $ki;

    private InProzessHttp $http;

    private Schleife $schleife;

    private WorkerUebernahme $uebernahme;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->workerId, $schluessel] = $this->koppeln();
        $identitaet = new Identitaet($this->workerId, $schluessel, $this->workerSchluesselpaar, self::BASIS, 'Pi');

        $this->arbeitBasis = $this->tmp . '/arbeit';
        mkdir($this->arbeitBasis, 0700);
        $this->abbruch = new Abbruch();
        $this->werkzeuge = new FakeWerkzeuge();
        $this->llm = new FakeLlmClient([FakeLlmClient::fixture('extract-v1/gueltig.json')]);
        $this->ki = new KiKonfiguration();
        $this->http = new InProzessHttp($this->auth, $this->api);
        $uhr = static fn(): int => time();
        $schlafen = static fn(float $s): bool => true;
        $prompts = new KiPrompts(dirname(__DIR__, 2) . '/app/prompts');

        $this->schleife = new Schleife(
            new ApiClient($this->http, $identitaet, $this->abbruch, $uhr, $schlafen),
            $identitaet,
            $this->ki,
            new Arbeitsverzeichnis($this->arbeitBasis, 'tmpfs ' . realpath($this->arbeitBasis) . " tmpfs rw 0 0\n"),
            [
                new SeitenRendern($this->werkzeuge),
                new Ocr($this->werkzeuge),
                new KiAuslesen($this->ki, new Auslesung($prompts), new KiAbfrage($this->llm, $prompts), $this->werkzeuge, $identitaet),
            ],
            ['ocr', 'render', 'ai'],
            '2.0.0',
            $this->abbruch,
            new Log(static function (string $zeile): void {
            }),
            $uhr,
            $schlafen,
        );

        $this->uebernahme = new WorkerUebernahme(
            $this->documents,
            $this->artifacts,
            new BlobRepository($this->pdo()),
            $this->jobs,
            new Texterkennung($this->documents, $this->artifacts, $this->blobService),
            KiAuslesungFabrik::bauen($this->pdo(), $this->blobService, $this->crypto),
        );
        // Online with every capability, as after its first heartbeat.
        $this->workers->heartbeat($this->workerId, 1, '2.0.0', WorkerFaehigkeit::cases(), new \DateTimeImmutable());
    }

    public function testRenderPagesDeliversPageImagesTheWebHostTakesOver(): void
    {
        $doc = $this->dokument([['%PDF-1.4 Rechnung', MagicBytes::PDF], [FakeJpeg::bauen(800, 600), MagicBytes::JPEG]]);
        $jobId = $this->anWorker('render_pages', $doc);

        self::assertSame(0.0, $this->schleife->runde(), 'straight on after a job');

        self::assertSame(JobStatus::Fertig, $this->jobs->find($jobId)?->status);
        self::assertSame(['seitenzahl', 'seite:1:150:-:85', 'seite:2:150:-:85'], $this->werkzeuge->aufrufe, 'only the PDF is rendered, at 150 DPI');
        $this->uebernehmen();

        $seiten = $this->workerArtefakte($doc, ArtifactKind::PageImage, $jobId);
        self::assertSame([0, 1], array_map(static fn(DocumentArtifact $a): int => $a->seq, $seiten));
        foreach ($seiten as $seite) {
            $blob = $this->blobService->find((int) $seite->blobId);
            self::assertNotNull($blob);
            $jpeg = implode('', iterator_to_array($this->blobService->openRead($blob, $this->vault), false));
            self::assertSame(MagicBytes::JPEG, MagicBytes::detect(substr($jpeg, 0, MagicBytes::HEAD_BYTES)), 'readable with the vault');
            // Sealed to VK_pub: the worker's own key does not open it.
            self::assertFalse(sodium_crypto_box_seal_open(substr($blob->dekSealed, 1), $this->workerSchluesselpaar));
        }
        self::assertFalse($this->jobs->offenFuer(['render_pages'], 'document', $doc), 'no browser rendering needed');
        $this->assertArbeitsverzeichnisLeer();
    }

    public function testAPageTooLargeIsScaledToTheLimit(): void
    {
        $this->werkzeuge->seiten = 1;
        $this->werkzeuge->breite = 5000;
        $this->werkzeuge->hoehe = 7000;
        $doc = $this->dokument([['%PDF-1.4 Plan', MagicBytes::PDF]]);
        $this->anWorker('render_pages', $doc);

        $this->schleife->runde();

        self::assertSame(['seitenzahl', 'seite:1:150:-:85', 'seite:1:150:4000:85'], $this->werkzeuge->aufrufe);
    }

    public function testOcrDeliversOneTextPerOriginalAndThePdfa(): void
    {
        $doc = $this->dokument([[FakeJpeg::bauen(800, 600), MagicBytes::JPEG], ['%PDF-1.4 Scan', MagicBytes::PDF]]);
        $jobId = $this->anWorker('ocr', $doc);

        $this->schleife->runde();

        self::assertSame(JobStatus::Fertig, $this->jobs->find($jobId)?->status);
        self::assertSame(['ocr:bild', 'ocr:pdf'], $this->werkzeuge->aufrufe);
        $this->uebernehmen();

        self::assertSame(OcrStatus::Fertig, $this->documents->find($doc)?->ocrStatus, 'the usable text reaches for the AI');
        self::assertCount(2, $this->workerArtefakte($doc, ArtifactKind::Text, $jobId));
        self::assertCount(2, $this->workerArtefakte($doc, ArtifactKind::Pdfa, $jobId));
        $text = new Texterkennung($this->documents, $this->artifacts, $this->blobService)->textFuerKi($this->documents->find($doc) ?? self::fail(), $this->vault);
        self::assertStringContainsString('Sportartikel Müller GmbH', (string) $text);
        $this->assertArbeitsverzeichnisLeer();
    }

    public function testAnOriginalOcrCannotReadEndsTheOcrForGood(): void
    {
        $doc = $this->dokument([['<?xml version="1.0"?><rechnung/>', MagicBytes::XML]]);
        $jobId = $this->anWorker('ocr', $doc);

        $this->schleife->runde();

        $job = $this->jobs->find($jobId);
        self::assertSame(JobStatus::Fehler, $job?->status);
        self::assertSame('OcrNichtMoeglich', $job->lastError);
    }

    public function testAiExtractUsesTheSharedLogicAndTheWebHostTakesTheResultOver(): void
    {
        $profil = $this->anbieter->insert('Standard', 'https://ki.test/v1', 'gpt-6-luna', new KiFaehigkeiten(true, true, 4, 4096), 60, true, 'sk-test');
        $this->anbieter->setDefault($profil);
        $doc = $this->dokument([[FakeJpeg::bauen(800, 600), MagicBytes::JPEG]], DocumentStatus::BereitZurAuswertung);
        $jobId = $this->anWorker('ai_extract', $doc, JobExecutor::Session);
        // Without OCR tools the model reads the page image.
        $this->werkzeuge->ocrDa = false;

        $this->schleife->runde();

        self::assertSame($profil, $this->ki->standard()[0]->id ?? null, 'the AI profile came with the heartbeat');
        self::assertSame(JobStatus::Fertig, $this->jobs->find($jobId)?->status);
        self::assertCount(1, $this->llm->anfragen);
        self::assertSame('sk-test', $this->llm->anfragen[0]['apiKey']);
        self::assertCount(1, $this->llm->anfragen[0]['anfrage']->bilder, 'the page image went along');
        $this->uebernehmen();

        self::assertSame(DocumentStatus::Ausgewertet, $this->documents->find($doc)?->status);
        $auszug = KiAuslesungFabrik::bauen($this->pdo(), $this->blobService, $this->crypto)->auszug($this->documents->find($doc) ?? self::fail(), $this->vault);
        self::assertNotNull($auszug);
        self::assertNotNull($auszug->extraktion);
    }

    public function testWithOcrToolsTheAiReadsTheWorkersOwnTextInsteadOfTheImage(): void
    {
        $profil = $this->anbieter->insert('Standard', 'https://ki.test/v1', 'gpt-6-luna', new KiFaehigkeiten(true, true, 4, 4096), 60, true, 'sk-test');
        $this->anbieter->setDefault($profil);
        $doc = $this->dokument([[FakeJpeg::bauen(800, 600), MagicBytes::JPEG]], DocumentStatus::BereitZurAuswertung);
        $this->anWorker('ai_extract', $doc, JobExecutor::Session);

        $this->schleife->runde();

        self::assertSame(['bildtext'], $this->werkzeuge->aufrufe);
        self::assertSame([], $this->llm->anfragen[0]['anfrage']->bilder, 'a usable text replaces the images, as in the session');
        self::assertStringContainsString('Sportartikel Müller GmbH', $this->llm->anfragen[0]['anfrage']->text);
        $this->assertArbeitsverzeichnisLeer();
    }

    public function testWithoutAiProfileTheSessionReadsTheReceipt(): void
    {
        $doc = $this->dokument([[FakeJpeg::bauen(800, 600), MagicBytes::JPEG]], DocumentStatus::BereitZurAuswertung);
        $jobId = $this->anWorker('ai_extract', $doc, JobExecutor::Session);

        $this->schleife->runde();

        self::assertSame('KiNichtEingerichtet', $this->jobs->find($jobId)?->lastError);
        self::assertSame([], $this->llm->anfragen);
        self::assertTrue($this->jobs->offenFuer([KiAuslesung::JOB_TYP], 'document', $doc), 'the session takes over');
    }

    public function testSwitchingTheModuleOffDropsTheKeysOnTheWorker(): void
    {
        $profil = $this->anbieter->insert('Standard', 'https://ki.test/v1', 'gpt-6-luna', new KiFaehigkeiten(true, true, 4, 4096), 60, true, 'sk-test');
        $this->anbieter->setDefault($profil);
        $this->schleife->runde();
        self::assertNotNull($this->ki->standard());

        $this->settings->set(WorkerRouting::SETTING_AKTIV, '0');
        $this->schleife->runde();

        self::assertNull($this->ki->standard());
        self::assertSame(0, $this->ki->anzahl());
    }

    public function testAStopRequestGivesTheRunningJobBack(): void
    {
        $doc = $this->dokument([['%PDF-1.4 Rechnung', MagicBytes::PDF]]);
        $jobId = $this->anWorker('render_pages', $doc);
        $this->werkzeuge->danach = $this->abbruch->anfordern(...);

        $this->schleife->runde();

        $job = $this->jobs->find($jobId);
        self::assertSame(JobStatus::Offen, $job?->status, 'back in the queue, not failed');
        self::assertSame('WorkerBeendet', $job->lastError);
        self::assertNotSame([], $this->grants->blobIdsFuer($this->workerId, $doc, new \DateTimeImmutable()), 'the grants stay for the next attempt');
        $this->assertArbeitsverzeichnisLeer();
    }

    public function testEveryRequestCarriesItsOwnNonce(): void
    {
        $doc = $this->dokument([['%PDF-1.4 Rechnung', MagicBytes::PDF]]);
        $this->anWorker('render_pages', $doc);

        $this->schleife->runde();

        self::assertGreaterThan(5, count($this->http->nonces));
        self::assertSame(count($this->http->nonces), count(array_unique($this->http->nonces)));
    }

    public function testPairingStoresTheIdentityAndShowsTheSameFingerprintAsTheAdmin(): void
    {
        $this->kopplung->entkoppeln($this->workerId, null, '', new \DateTimeImmutable());
        $code = $this->kopplung->codeErzeugen(null, '', new \DateTimeImmutable());
        self::assertNotNull($code);
        $datei = $this->tmp . '/daten/worker.json';

        $identitaet = Kopplung::koppeln($this->http, self::BASIS . '/', $code, 'Pi im Vereinsheim', '2.0.0', $datei);

        self::assertSame(0600, fileperms($datei) & 0777);
        self::assertSame(self::BASIS, $identitaet->basisUrl);
        $worker = $this->workers->find($identitaet->workerId);
        self::assertNotNull($worker);
        self::assertSame(WorkerStatus::Gekoppelt, $worker->status, 'inactive until the admin confirms');
        self::assertSame(sodium_crypto_box_publickey($identitaet->schluesselpaar), $worker->publicKey);
        self::assertSame(WorkerProtokoll::fingerabdruckAnzeige(WorkerProtokoll::fingerabdruck($worker->publicKey)), $identitaet->fingerabdruck());
        self::assertEquals($identitaet, Identitaet::laden($datei));
    }

    public function testPairingRefusesPlainHttpBeforeSendingAnything(): void
    {
        try {
            Kopplung::koppeln($this->http, 'http://belege.test', 'ABCD-EFGH', 'Pi', '2.0.0', $this->tmp . '/daten/worker.json');
            self::fail('expected the refusal');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame([], $this->http->anfragen);
        self::assertFileDoesNotExist($this->tmp . '/daten/worker.json');
    }

    // ------------------------------------------------------------- helpers

    /**
     * @param list<array{string, string}> $originale content and MIME type
     */
    private function dokument(array $originale, DocumentStatus $status = DocumentStatus::Eingegangen): int
    {
        $blobs = [];
        foreach ($originale as [$inhalt, $mime]) {
            $blobs[] = $this->blobService->storeString($inhalt, new BlobMeta($mime), $this->vault)->id;
        }

        return $this->documents->insert(
            DocumentSource::Intern,
            null,
            $blobs,
            $this->vault->sealDataKey(DataKey::generate()),
            new \DateTimeImmutable(),
            $status,
            OcrStatus::Ausstehend,
        );
    }

    /** Routes a job of $typ to the worker, as the web host does. */
    private function anWorker(string $typ, int $doc, ?JobExecutor $ersatz = null): int
    {
        self::assertSame(JobExecutor::Worker, $this->uebergabe->einreihen($typ, $ersatz, $doc, $this->vault, new \DateTimeImmutable()));
        $stmt = $this->pdo()->prepare('SELECT MAX(id) FROM job WHERE typ = ? AND ref_id = ?');
        $stmt->execute([$typ, $doc]);

        return (int) $stmt->fetchColumn();
    }

    /** Runs the takeover job `complete` queued, like a session would. */
    private function uebernehmen(): void
    {
        $job = $this->jobs->claim(JobExecutor::Session, 'sitzung', 60, WorkerUebernahme::JOB_TYP);
        self::assertNotNull($job, 'complete queues the takeover');
        $ergebnis = $this->uebernahme->schritt($job, $this->vault, new \DateTimeImmutable());
        $this->jobs->schrittErledigt($job->id, 'sitzung', $ergebnis);
    }

    /**
     * The worker's visible (not defective) artifacts of $kind from job $jobId.
     *
     * @return list<DocumentArtifact>
     */
    private function workerArtefakte(int $doc, ArtifactKind $kind, int $jobId): array
    {
        $liste = array_values(array_filter(
            $this->artifacts->fuerDokument($doc, $kind),
            static fn(DocumentArtifact $a): bool => $a->jobId === $jobId && $a->producer === JobExecutor::Worker,
        ));
        usort($liste, static fn(DocumentArtifact $a, DocumentArtifact $b): int => $a->seq <=> $b->seq);

        return $liste;
    }

    private function assertArbeitsverzeichnisLeer(): void
    {
        self::assertSame(['.', '..'], scandir($this->arbeitBasis), 'no plaintext file is left behind');
    }
}
