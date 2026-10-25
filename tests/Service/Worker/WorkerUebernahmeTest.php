<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Domain\ArtifactKind;
use App\Domain\BlobMeta;
use App\Domain\DocumentSource;
use App\Domain\DocumentStatus;
use App\Domain\JobExecutor;
use App\Domain\JobStatus;
use App\Domain\OcrStatus;
use App\Domain\Worker;
use App\Domain\WorkerFaehigkeit;
use App\Http\Request;
use App\Http\Response;
use App\Repository\BlobRepository;
use App\Repository\DocumentArtifactRepository;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Document\KiAuslesung;
use App\Service\Document\Texterkennung;
use App\Service\Upload\MagicBytes;
use App\Service\Worker\WorkerKrypto;
use App\Service\Worker\WorkerUebergabe;
use App\Service\Worker\WorkerUebernahme;
use App\Tests\Support\KiAuslesungFabrik;

/**
 * Taking over the worker's results (issue #53, review of #52): a finished
 * worker job queues a session job that checks every artifact; good ones
 * enter the flow, a broken one (does not decrypt, no JSON, wrong shape) is
 * marked defective and the browser/session route takes over - and no
 * reader ever throws on it, before or after.
 */
final class WorkerUebernahmeTest extends WorkerTestCase
{
    private Texterkennung $texterkennung;
    private KiAuslesung $kiAuslesung;
    private WorkerUebernahme $uebernahme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->texterkennung = new Texterkennung($this->documents, $this->artifacts, $this->blobService);
        $this->kiAuslesung = KiAuslesungFabrik::bauen($this->pdo(), $this->blobService, $this->crypto);
        $this->uebernahme = new WorkerUebernahme(
            $this->documents,
            $this->artifacts,
            new BlobRepository($this->pdo()),
            $this->jobs,
            $this->texterkennung,
            $this->kiAuslesung,
        );
    }

    public function testUsableOcrTextIsTakenOverAndTheAiReadsIt(): void
    {
        $doc = $this->dokument();
        [$id, $schluessel, $job] = $this->workerJob(WorkerUebergabe::OCR, $doc);
        $this->datenArtefakt($doc, ArtifactKind::Text, $job['id'], 0, json_encode(['brauchbar' => true, 'seiten' => ['Rechnung 42 Trikots']], JSON_THROW_ON_ERROR));
        $this->abschliessen($id, $schluessel, $job);

        $this->uebernehmen();
        $document = $this->documents->find($doc);
        self::assertSame(OcrStatus::Fertig, $document?->ocrStatus);
        self::assertSame('Rechnung 42 Trikots', $this->texterkennung->textFuerKi($document, $this->vault));
    }

    public function testABrokenOcrArtifactIsMarkedAndNeverBreaksAReader(): void
    {
        $doc = $this->dokument();
        [$id, $schluessel, $job] = $this->workerJob(WorkerUebergabe::OCR, $doc);
        // Encrypted for another row (wrong AAD): does not open.
        $kaputt = $this->datenArtefakt($doc, ArtifactKind::Text, $job['id'], 0, '{"brauchbar":true,"seiten":["x"]}', falscheZeile: true);
        // Opens, but is no JSON object of the expected shape.
        $form = $this->datenArtefakt($doc, ArtifactKind::Text, $job['id'], 1, '{"brauchbar":"ja"}');
        $this->abschliessen($id, $schluessel, $job);

        // Before the takeover the readers already treat them as missing.
        $document = $this->documents->find($doc);
        self::assertNotNull($document);
        self::assertSame([], $this->texterkennung->seitentexte($document, $this->vault));

        $this->uebernehmen();
        self::assertSame(DocumentArtifactRepository::DEFEKT . 'text', $this->artifacts->rohzeile($kaputt)['kind'] ?? null);
        self::assertSame(DocumentArtifactRepository::DEFEKT . 'text', $this->artifacts->rohzeile($form)['kind'] ?? null);
        self::assertSame(OcrStatus::Uebersprungen, $this->documents->find($doc)?->ocrStatus);
        self::assertSame(2, $this->artifacts->zaehleDefekte());
        self::assertSame(0, $this->uebergabe->nachholbar(), 'A run that delivered something broken is not repeated as is.');
    }

    public function testOneBrokenTextKeepsTheWholeRunFromBeingTakenOver(): void
    {
        $doc = $this->dokument();
        [$id, $schluessel, $job] = $this->workerJob(WorkerUebergabe::OCR, $doc);
        $this->datenArtefakt($doc, ArtifactKind::Text, $job['id'], 0, json_encode(['brauchbar' => true, 'seiten' => ['Seite eins']], JSON_THROW_ON_ERROR));
        $this->datenArtefakt($doc, ArtifactKind::Text, $job['id'], 1, 'kaputt', falscheZeile: true);
        $this->abschliessen($id, $schluessel, $job);

        $this->uebernehmen();
        self::assertSame(OcrStatus::Uebersprungen, $this->documents->find($doc)?->ocrStatus, 'The good text alone does not cover every original.');
    }

    public function testAValidWorkerExtractionEndsTheRun(): void
    {
        $doc = $this->dokument();
        [$id, $schluessel, $job] = $this->workerJob(KiAuslesung::JOB_TYP, $doc);
        $this->datenArtefakt($doc, ArtifactKind::Extraction, $job['id'], 0, $this->extraktion(true));
        $this->abschliessen($id, $schluessel, $job);

        $this->uebernehmen();
        self::assertSame(DocumentStatus::Ausgewertet, $this->documents->find($doc)?->status);
        self::assertFalse($this->jobs->offenFuer([KiAuslesung::JOB_TYP], 'document', $doc), 'No session run on top.');
        $document = $this->documents->find($doc);
        self::assertNotNull($document);
        $auszug = $this->kiAuslesung->auszug($document, $this->vault);
        self::assertNotNull($auszug);
        self::assertNotSame('Vom Worker behauptet.', $auszug->meldung, 'The message is the server\'s own.');
        self::assertSame('RE-2026-0815', $auszug->extraktion['invoice_number'] ?? null);
    }

    public function testTheWorkersAiCallsAppearInTheCallLogOnceWithoutContent(): void
    {
        $doc = $this->dokument();
        [$id, $schluessel, $job] = $this->workerJob(KiAuslesung::JOB_TYP, $doc);
        // The first attempt as an odd worker might report it: text where an
        // identifier belongs, numbers outside their columns.
        $this->datenArtefakt($doc, ArtifactKind::Extraction, $job['id'], 0, $this->extraktion(false, aufruf: [
            'anbieter_id' => 999,
            'anbieter_name' => 'Rechnung RE-2026-0815 Müller',
            'modell' => 'Sportartikel Müller GmbH',
            'dauer_ms' => -5,
            'http_status' => 70000,
            'prompt_tokens' => 12,
        ]));
        $this->datenArtefakt($doc, ArtifactKind::Extraction, $job['id'], 1, $this->extraktion(true));
        $this->abschliessen($id, $schluessel, $job);

        $uebernahme = $this->jobs->claim(JobExecutor::Session, 'sitzung', 60, WorkerUebernahme::JOB_TYP);
        self::assertNotNull($uebernahme);
        $this->uebernahme->schritt($uebernahme, $this->vault, new \DateTimeImmutable());
        // The same step again (its request died before it was marked done).
        $ergebnis = $this->uebernahme->schritt($uebernahme, $this->vault, new \DateTimeImmutable());
        $this->jobs->schrittErledigt($uebernahme->id, 'sitzung', $ergebnis);

        $zeilen = $this->pdo()->query('SELECT * FROM ai_call_log ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(2, $zeilen, 'each worker call once, also after a repeated takeover');
        self::assertSame(['kein_json', 'gueltig'], array_column($zeilen, 'status'));
        // Profile 1 is the web host's own (KiAuslesungFabrik): its name and
        // model count, not the worker's "Test"/"test". Profile 999 is unknown.
        $profil = $this->pdo()->query('SELECT name, model FROM ai_provider WHERE id = 1')->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($profil);
        self::assertSame(['unbekannt', $profil['model']], array_column($zeilen, 'model'), 'no free text from the worker');
        self::assertSame(['Worker', $profil['name']], array_column($zeilen, 'provider_name'), 'an unknown profile is not named by the worker');
        self::assertSame([null, 1], array_map(static fn(mixed $id): ?int => $id === null ? null : (int) $id, array_column($zeilen, 'provider_id')));
        self::assertSame(['extract-v1', 'extract-v1'], array_column($zeilen, 'prompt_version'));
        self::assertNull($zeilen[0]['duration_ms']);
        self::assertNull($zeilen[0]['http_status']);
        self::assertSame(12, (int) $zeilen[0]['prompt_tokens']);
        // Operating data only: no column carries what the AI read.
        $alles = json_encode($zeilen, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('RE-2026-0815', $alles);
        self::assertStringNotContainsString('Müller', $alles);
    }

    public function testTheExtractionIsRebuiltWithTheServersChecksNotTakenFromTheWorker(): void
    {
        $doc = $this->dokument();
        [$id, $schluessel, $job] = $this->workerJob(KiAuslesung::JOB_TYP, $doc);
        // The worker claims an extraction with a field the attempt never had
        // and a category the club does not offer.
        $this->datenArtefakt($doc, ArtifactKind::Extraction, $job['id'], 0, $this->extraktion(true, extraktion: ['invoice_number' => 'GEFAELSCHT', 'category_id' => 7]));
        $this->abschliessen($id, $schluessel, $job);

        $this->uebernehmen();
        $document = $this->documents->find($doc);
        self::assertNotNull($document);
        $auszug = $this->kiAuslesung->auszug($document, $this->vault);
        self::assertNotNull($auszug);
        self::assertSame('RE-2026-0815', $auszug->extraktion['invoice_number'] ?? null);
        self::assertIsArray($auszug->extraktion);
        self::assertArrayHasKey('category_id', $auszug->extraktion);
        self::assertNull($auszug->extraktion['category_id'], 'auswerten() drops a category the club does not offer');
    }

    public function testAValidClaimThatBreaksTheSchemaFallsBackToTheSession(): void
    {
        $doc = $this->dokument();
        [$id, $schluessel, $job] = $this->workerJob(KiAuslesung::JOB_TYP, $doc);
        $kaputt = $this->datenArtefakt($doc, ArtifactKind::Extraction, $job['id'], 0, $this->extraktion(true, daten: ['total_gross' => 12.5]));
        $this->abschliessen($id, $schluessel, $job);

        $this->uebernehmen();
        self::assertSame(DocumentArtifactRepository::DEFEKT . 'extraction', $this->artifacts->rohzeile($kaputt)['kind'] ?? null);
        self::assertSame(DocumentStatus::BereitZurAuswertung, $this->documents->find($doc)?->status);
        self::assertTrue($this->jobs->offenFuer([KiAuslesung::JOB_TYP], 'document', $doc));
    }

    public function testAnIncompleteOcrRunIsMarkedAndChangesNothing(): void
    {
        foreach ([
            'leere Seiten' => fn(int $doc, int $jobId) => $this->datenArtefakt($doc, ArtifactKind::Text, $jobId, 0, '{"brauchbar":true,"seiten":[]}'),
            'falsche seq' => fn(int $doc, int $jobId) => $this->datenArtefakt($doc, ArtifactKind::Text, $jobId, 3, '{"brauchbar":true,"seiten":["Text"]}'),
        ] as $fall => $liefern) {
            $doc = $this->dokument();
            [$id, $schluessel, $job] = $this->workerJob(WorkerUebergabe::OCR, $doc);
            $zeile = $liefern($doc, $job['id']);
            $this->abschliessen($id, $schluessel, $job);
            $this->uebernehmen();
            self::assertSame(OcrStatus::Uebersprungen, $this->documents->find($doc)?->ocrStatus, $fall);
            self::assertSame(DocumentArtifactRepository::DEFEKT . 'text', $this->artifacts->rohzeile($zeile)['kind'] ?? null, $fall);
            $this->kopplung->entkoppeln($id, null, '', new \DateTimeImmutable());
        }
    }

    public function testOneTextForThreeOriginalsIsIncomplete(): void
    {
        $doc = $this->dokument(originale: 3);
        [$id, $schluessel, $job] = $this->workerJob(WorkerUebergabe::OCR, $doc);
        $zeile = $this->datenArtefakt($doc, ArtifactKind::Text, $job['id'], 0, '{"brauchbar":true,"seiten":["Nur Seite eins"]}');
        $this->abschliessen($id, $schluessel, $job);

        $this->uebernehmen();
        self::assertSame(OcrStatus::Uebersprungen, $this->documents->find($doc)?->ocrStatus);
        self::assertSame(DocumentArtifactRepository::DEFEKT . 'text', $this->artifacts->rohzeile($zeile)['kind'] ?? null);
    }

    public function testACompleteButUnusableTextStaysForTheSearchOnly(): void
    {
        $doc = $this->dokument();
        [$id, $schluessel, $job] = $this->workerJob(WorkerUebergabe::OCR, $doc);
        $zeile = $this->datenArtefakt($doc, ArtifactKind::Text, $job['id'], 0, '{"brauchbar":false,"seiten":["kaum lesbar"]}');
        $this->abschliessen($id, $schluessel, $job);

        $this->uebernehmen();
        self::assertSame(OcrStatus::Uebersprungen, $this->documents->find($doc)?->ocrStatus);
        self::assertSame('text', $this->artifacts->rohzeile($zeile)['kind'] ?? null);
    }

    public function testABrokenWorkerExtractionFallsBackToTheSession(): void
    {
        $doc = $this->dokument();
        [$id, $schluessel, $job] = $this->workerJob(KiAuslesung::JOB_TYP, $doc);
        $kaputt = $this->datenArtefakt($doc, ArtifactKind::Extraction, $job['id'], 0, 'kein json');
        $this->abschliessen($id, $schluessel, $job);

        $document = $this->documents->find($doc);
        self::assertNotNull($document);
        self::assertNull($this->kiAuslesung->auszug($document, $this->vault), 'The review page does not break on it.');

        $this->uebernehmen();
        self::assertSame(DocumentArtifactRepository::DEFEKT . 'extraction', $this->artifacts->rohzeile($kaputt)['kind'] ?? null);
        self::assertSame(DocumentStatus::BereitZurAuswertung, $this->documents->find($doc)?->status);
        $stmt = $this->pdo()->prepare('SELECT executor FROM job WHERE typ = ? AND status = ?');
        $stmt->execute([KiAuslesung::JOB_TYP, JobStatus::Offen->value]);
        self::assertSame(JobExecutor::Session->value, $stmt->fetchColumn());
    }

    public function testBrokenPageImagesSendTheRenderingBackToTheBrowser(): void
    {
        $doc = $this->dokument();
        [$id, $schluessel, $job] = $this->workerJob('render_pages', $doc);
        // A page row whose blob is missing.
        $reserviert = $this->artifacts->reserviere($doc, ArtifactKind::PageImage, 0, $this->vault->sealDataKey(DataKey::generate()), $job['id'], new \DateTimeImmutable());
        self::assertNotNull($reserviert);
        self::assertTrue($this->artifacts->festschreiben($reserviert, ArtifactKind::PageImage, null, null));
        $this->abschliessen($id, $schluessel, $job);

        $this->uebernehmen();
        self::assertSame([], $this->artifacts->fuerDokument($doc, ArtifactKind::PageImage));
        $stmt = $this->pdo()->prepare('SELECT executor FROM job WHERE typ = ? AND status = ?');
        $stmt->execute(['render_pages', JobStatus::Offen->value]);
        self::assertSame(JobExecutor::Browser->value, $stmt->fetchColumn());
    }

    // ------------------------------------------------------------- helpers

    private function dokument(int $originale = 1): int
    {
        $blobs = [];
        for ($i = 0; $i < $originale; ++$i) {
            $blobs[] = $this->blobService->storeString('Bild ' . $i, new BlobMeta(MagicBytes::JPEG), $this->vault)->id;
        }

        return $this->documents->insert(
            DocumentSource::Intern,
            null,
            $blobs,
            $this->vault->sealDataKey(DataKey::generate()),
            new \DateTimeImmutable(),
            DocumentStatus::BereitZurAuswertung,
            OcrStatus::Uebersprungen,
        );
    }

    /**
     * An online worker that claimed a job of $typ on $doc.
     *
     * @return array{int, string, array{id: int, lock: string}}
     */
    private function workerJob(string $typ, int $doc): array
    {
        [$id, $schluessel] = $this->koppeln();
        $this->workers->heartbeat($id, 1, '1.0.0', WorkerFaehigkeit::cases(), new \DateTimeImmutable());
        self::assertSame(JobExecutor::Worker, $this->uebergabe->einreihen($typ, null, $doc, $this->vault, new \DateTimeImmutable()));
        $antwort = $this->aufruf(
            $this->signiert($id, $schluessel, '/api/worker/jobs/claim', '{"max":1}'),
            fn(Request $r, array $p, Worker $w): Response => $this->api->claim($r, $w),
        );
        $job = self::daten($antwort)['jobs'][0] ?? null;
        self::assertIsArray($job, $antwort->body);

        return [$id, $schluessel, ['id' => (int) $job['id'], 'lock' => (string) $job['lock']]];
    }

    /**
     * A committed worker artifact with $json as `data_enc`, sealed to the
     * vault the way the worker does it - or, with $falscheZeile, encrypted
     * for another row.
     */
    private function datenArtefakt(int $doc, ArtifactKind $kind, int $jobId, int $seq, string $json, bool $falscheZeile = false): int
    {
        $dek = DataKey::generate();
        $id = $this->artifacts->reserviere($doc, $kind, $seq, WorkerKrypto::fuerTresorVersiegeln($dek, $this->vault->publicKey(), $this->vault->version), $jobId, new \DateTimeImmutable());
        self::assertNotNull($id);
        $daten = FieldCipher::encrypt($dek, $json, new FieldContext('document_artifact', $falscheZeile ? $id + 1000 : $id, 'data_enc'));
        self::assertTrue($this->artifacts->festschreiben($id, $kind, null, $daten));

        return $id;
    }

    /**
     * @param array{id: int, lock: string} $job
     */
    private function abschliessen(int $id, string $schluessel, array $job): void
    {
        $antwort = $this->aufruf(
            $this->signiert($id, $schluessel, '/api/worker/jobs/' . $job['id'] . '/complete', json_encode(['lock' => $job['lock']], JSON_THROW_ON_ERROR)),
            fn(Request $r, array $p, Worker $w): Response => $this->api->complete($r, $p, $w),
            ['id' => (string) $job['id']],
        );
        self::assertSame(200, $antwort->status, $antwort->body);
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
     * A worker extraction artifact: a valid attempt with the `extract-v1`
     * answer of the recorded fixture (or $daten), and whatever the worker
     * claims as its extraction.
     *
     * @param array<string, mixed>|null $daten
     * @param array<string, mixed>|null $extraktion
     * @param array<string, mixed> $aufruf overrides of the stored call
     */
    private function extraktion(bool $gueltig, ?array $daten = null, ?array $extraktion = null, array $aufruf = []): string
    {
        $antwort = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/llm/extract-v1/gueltig.json'), true, flags: JSON_THROW_ON_ERROR);
        $daten ??= json_decode($antwort['choices'][0]['message']['content'], true, flags: JSON_THROW_ON_ERROR);
        $aufruf += [
            'anbieter_id' => 1,
            'anbieter_name' => 'Test',
            'modell' => 'test',
            'prompt_version' => 'extract-v1',
            'reparatur' => false,
            'status' => $gueltig ? 'gueltig' : 'kein_json',
            'fallback' => false,
        ];

        return json_encode([
            'versuch' => [
                'status' => $aufruf['status'],
                'daten' => $gueltig ? $daten : null,
                'fehler' => [],
                'inhalt' => '{}',
                'reparatur' => false,
                'aufruf' => $aufruf,
            ],
            'meldung' => 'Vom Worker behauptet.',
            'extraktion' => $gueltig ? ($extraktion ?? $daten) : null,
            'hinweise' => [],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
