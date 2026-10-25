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
        self::assertSame('Ausgelesen.', $this->kiAuslesung->auszug($document, $this->vault)?->meldung);
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

    private function dokument(): int
    {
        $blob = $this->blobService->storeString('Bild', new BlobMeta(MagicBytes::JPEG), $this->vault);

        return $this->documents->insert(
            DocumentSource::Intern,
            null,
            [$blob->id],
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

    private function extraktion(bool $gueltig): string
    {
        $aufruf = [
            'anbieter_id' => 1,
            'anbieter_name' => 'Test',
            'modell' => 'test',
            'prompt_version' => 'v1',
            'reparatur' => false,
            'status' => $gueltig ? 'gueltig' : 'kein_json',
            'fallback' => false,
        ];

        return json_encode([
            'versuch' => [
                'status' => $aufruf['status'],
                'daten' => $gueltig ? ['total_gross_cents' => 1234] : null,
                'fehler' => [],
                'inhalt' => '{}',
                'reparatur' => false,
                'aufruf' => $aufruf,
            ],
            'meldung' => 'Ausgelesen.',
            'extraktion' => $gueltig ? ['total_gross_cents' => 1234] : null,
            'hinweise' => [],
        ], JSON_THROW_ON_ERROR);
    }
}
