<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Domain\ArchiveImportStats;
use App\Domain\ArtifactKind;
use App\Domain\Berechtigungen;
use App\Domain\BlobMeta;
use App\Domain\DocumentSource;
use App\Domain\DocumentStatus;
use App\Domain\Job;
use App\Domain\JobExecutor;
use App\Domain\JobStatus;
use App\Domain\OcrStatus;
use App\Domain\Role;
use App\Domain\SystemRole;
use App\Domain\Worker;
use App\Domain\WorkerFaehigkeit;
use App\Http\Request;
use App\Http\Response;
use App\Repository\ArchiveImportRepository;
use App\Repository\BlobRepository;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Document\Texterkennung;
use App\Service\Job\JobRunner;
use App\Service\Upload\MagicBytes;
use App\Service\Worker\BestandsOcr;
use App\Service\Worker\WorkerKrypto;
use App\Service\Worker\WorkerUebergabe;
use App\Service\Worker\WorkerUebernahme;
use App\Tests\Support\KiAuslesungFabrik;

/**
 * OCR backfill of the imported archive (issue #88, E-55,
 * docs/spec/07-worker.md section 6 "Stand #88"): selection by import run,
 * locked receipts included but unchanged, throttled so new receipts keep
 * priority, waits without a worker and resumes at its cursor, one run at a
 * time, stoppable; archive documents never go to the worker one by one.
 */
final class BestandsOcrTest extends WorkerTestCase
{
    private BestandsOcr $bestand;
    private ArchiveImportRepository $imports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->imports = new ArchiveImportRepository($this->pdo());
        $this->bestand = new BestandsOcr($this->uebergabe, $this->documents, $this->jobs, $this->imports);
    }

    public function testOnlyTheRunsArchiveDocumentsLockedOnesIncluded(): void
    {
        $intern = $this->dokument(DocumentSource::Intern);
        $vorher = $this->dokument(DocumentSource::Archiv);
        $gesperrt = $this->dokument(DocumentSource::Archiv, DocumentStatus::Festgeschrieben);
        $offen = $this->dokument(DocumentSource::Archiv);
        $abgelehnt = $this->dokument(DocumentSource::Archiv, DocumentStatus::Abgelehnt);
        $mitText = $this->dokument(DocumentSource::Archiv, ocr: OcrStatus::Fertig);
        $danach = $this->dokument(DocumentSource::Archiv);
        $lauf = $this->importLauf($gesperrt, $mitText);
        $this->onlineWorker();

        self::assertSame(1, $this->uebergabe->nachholbar(), 'The plain "OCR nachholen" leaves the archive to the backfill.');
        self::assertNotNull($this->bestand->starten($lauf, new \DateTimeImmutable()));
        $this->schritt();

        self::assertSame([$gesperrt, $offen], $this->ocrJobs());
        self::assertSame(DocumentStatus::Festgeschrieben, $this->documents->find($gesperrt)?->status);
        $job = $this->laufender();
        self::assertSame(['gesamt' => 3, 'mitText' => 1, 'beimWorker' => 2, 'offen' => 0], $this->bestand->stand($job));
        self::assertSame(JobStatus::Fertig, $this->schritt()->status, 'Nothing left: the run ends.');
        self::assertNotContains($intern, $this->ocrJobs());
        self::assertNotContains($vorher, $this->ocrJobs());
        self::assertNotContains($abgelehnt, $this->ocrJobs());
        self::assertNotContains($danach, $this->ocrJobs());
    }

    public function testAllArchiveDocumentsWithoutARun(): void
    {
        $a = $this->dokument(DocumentSource::Archiv);
        $b = $this->dokument(DocumentSource::Archiv);
        $this->onlineWorker();

        self::assertNotNull($this->bestand->starten(null, new \DateTimeImmutable()));
        $this->schritt();

        self::assertSame([$a, $b], $this->ocrJobs());
    }

    public function testThrottledSoNewReceiptsKeepPriority(): void
    {
        $archiv = [];
        for ($i = 0; $i < 8; ++$i) {
            $archiv[] = $this->dokument(DocumentSource::Archiv);
        }
        $this->onlineWorker();
        $this->bestand->starten(null, new \DateTimeImmutable());

        $ergebnis = $this->schritt();
        self::assertSame(array_slice($archiv, 0, BestandsOcr::NACHSCHUB), $this->ocrJobs());
        self::assertSame(JobStatus::Offen, $ergebnis->status);
        self::assertNotNull($ergebnis->lockedUntil, 'A full queue waits instead of keeping the browser busy.');
        self::assertSame($archiv[BestandsOcr::NACHSCHUB - 1], $this->bestandsZustand()['nach'], 'The cursor stands at the last one handed over.');

        // The worker finished three; a new receipt's OCR job came in.
        foreach (array_slice($this->ocrJobIds(), 0, 3) as $id) {
            $this->jobs->finish($id, JobStatus::Fertig);
        }
        $neu = $this->dokument(DocumentSource::Intern);
        self::assertSame(JobExecutor::Worker, $this->uebergabe->einreihen(WorkerUebergabe::OCR, null, $neu, $this->vault, new \DateTimeImmutable()));

        // 2 archive + 1 new are waiting: the backfill tops up to 5, not more
        // (once its wait is over).
        $this->pdo()->exec("UPDATE job SET locked_until = NULL WHERE typ = 'bestand_ocr'");
        $this->schritt();
        self::assertSame(BestandsOcr::NACHSCHUB, $this->jobs->zaehleOffen(JobExecutor::Worker, [WorkerUebergabe::OCR]));
        self::assertSame(7, $this->bestandsZustand()['uebergeben']);
        // The worker claims oldest first: the new receipt comes before the two added after it.
        $offen = $this->ocrJobIds();
        $neuJob = (int) $this->pdo()->query("SELECT id FROM job WHERE typ = 'ocr' AND ref_id = " . $neu)->fetchColumn();
        self::assertSame(2, count(array_filter($offen, static fn(int $id): bool => $id > $neuJob)));
    }

    public function testWaitsWithoutTheWorkerAndResumesAtItsCursor(): void
    {
        $a = $this->dokument(DocumentSource::Archiv);
        $b = $this->dokument(DocumentSource::Archiv);
        [$worker] = $this->onlineWorker();
        $this->bestand->starten(null, new \DateTimeImmutable());

        $this->workers->heartbeat($worker, 1, '1.0.0', WorkerFaehigkeit::cases(), new \DateTimeImmutable('-10 minutes'));
        $ergebnis = $this->schritt();
        self::assertSame(JobStatus::Offen, $ergebnis->status);
        self::assertNotNull($ergebnis->lockedUntil);
        self::assertSame([], $this->ocrJobs());
        self::assertSame(0, $this->bestandsZustand()['nach'], 'Nothing skipped while the worker was away.');

        // Back: picks up where it stood (the wait is over).
        $this->workers->heartbeat($worker, 1, '1.0.0', WorkerFaehigkeit::cases(), new \DateTimeImmutable());
        $this->pdo()->exec("UPDATE job SET locked_until = NULL WHERE typ = 'bestand_ocr'");
        $this->schritt();
        self::assertSame([$a, $b], $this->ocrJobs());
    }

    public function testOneRunAtATimeAndStoppable(): void
    {
        $this->dokument(DocumentSource::Archiv);
        $this->onlineWorker();

        self::assertNotNull($this->bestand->starten(null, new \DateTimeImmutable()));
        self::assertNull($this->bestand->starten(null, new \DateTimeImmutable()), 'One run at a time.');
        self::assertTrue($this->bestand->abbrechen(new \DateTimeImmutable()));
        self::assertNull($this->bestand->laufender());
        self::assertSame(JobStatus::Uebersprungen, $this->bestand->letzter()?->status);
        self::assertSame([], $this->ocrJobs());
        self::assertFalse($this->bestand->abbrechen(new \DateTimeImmutable()));

        self::assertNotNull($this->bestand->starten(null, new \DateTimeImmutable()), 'A new start after stopping.');
    }

    public function testARunWithoutDocumentsCannotStart(): void
    {
        $id = $this->imports->insert(str_repeat('b', 32), '{lieferant} {datum}', 0, $this->vault->sealDataKey(DataKey::generate()), null, new \DateTimeImmutable());

        self::assertNull($this->bestand->starten($id, new \DateTimeImmutable()), 'A preview has written nothing.');
        self::assertNull($this->bestand->starten(999_999, new \DateTimeImmutable()));
    }

    public function testTheBackfillIsLeftOutOfTheHeaderCount(): void
    {
        $this->dokument(DocumentSource::Archiv);
        $this->bestand->starten(null, new \DateTimeImmutable());
        $admin = new Berechtigungen([new Role(1, 'Admin', SystemRole::Admin, false, [])]);

        $runner = new JobRunner($this->jobs, [$this->bestand]);
        self::assertSame(0, $runner->offen($admin));
        // Still claimed and stepped: the worker is offline, so it waits.
        $runner->schritt($admin, $this->vault, new \DateTimeImmutable());
        self::assertSame(0, $this->bestandsZustand()['uebergeben']);
        self::assertNotNull($this->laufender()->lockedUntil);
    }

    public function testArchiveDocumentsDoNotGoToTheWorkerOneByOne(): void
    {
        $archiv = $this->dokument(DocumentSource::Archiv, ocr: OcrStatus::Ausstehend);
        $intern = $this->dokument(DocumentSource::Intern, ocr: OcrStatus::Ausstehend);
        $this->onlineWorker();
        $texterkennung = new Texterkennung($this->documents, $this->artifacts, $this->blobService, $this->uebergabe);

        foreach ([$archiv, $intern] as $doc) {
            $id = $this->jobs->enqueue(Texterkennung::JOB_TYP, JobExecutor::Session, 'document', $doc);
            $job = $this->jobs->find($id);
            self::assertNotNull($job);
            $texterkennung->schritt($job, $this->vault, new \DateTimeImmutable());
        }

        self::assertSame(OcrStatus::Uebersprungen, $this->documents->find($archiv)?->ocrStatus);
        self::assertSame([$intern], $this->ocrJobs(), 'Only the new receipt goes straight to the worker.');
    }

    public function testTheTakeoverOnALockedArchiveDocumentAddsOnlyTheTextLayer(): void
    {
        $doc = $this->dokument(DocumentSource::Archiv, DocumentStatus::Festgeschrieben);
        $vorher = $this->documents->find($doc);
        [$id, $schluessel] = $this->onlineWorker();
        $this->bestand->starten(null, new \DateTimeImmutable());
        $this->schritt();

        $antwort = $this->aufruf(
            $this->signiert($id, $schluessel, '/api/worker/jobs/claim', '{"max":1}'),
            fn(Request $r, array $p, Worker $w): Response => $this->api->claim($r, $w),
        );
        $job = self::daten($antwort)['jobs'][0] ?? null;
        self::assertIsArray($job, $antwort->body);
        $dek = DataKey::generate();
        $artefakt = $this->artifacts->reserviere($doc, ArtifactKind::Text, 0, WorkerKrypto::fuerTresorVersiegeln($dek, $this->vault->publicKey(), $this->vault->version), (int) $job['id'], new \DateTimeImmutable());
        self::assertNotNull($artefakt);
        $daten = FieldCipher::encrypt($dek, '{"brauchbar":true,"seiten":["Rechnung 7 Vereinsheim"]}', new FieldContext('document_artifact', $artefakt, 'data_enc'));
        self::assertTrue($this->artifacts->festschreiben($artefakt, ArtifactKind::Text, null, $daten));
        $abschluss = $this->aufruf(
            $this->signiert($id, $schluessel, '/api/worker/jobs/' . $job['id'] . '/complete', json_encode(['lock' => $job['lock']], JSON_THROW_ON_ERROR)),
            fn(Request $r, array $p, Worker $w): Response => $this->api->complete($r, $p, $w),
            ['id' => (string) $job['id']],
        );
        self::assertSame(200, $abschluss->status, $abschluss->body);

        $texterkennung = new Texterkennung($this->documents, $this->artifacts, $this->blobService);
        $uebernahme = new WorkerUebernahme($this->documents, $this->artifacts, new BlobRepository($this->pdo()), $this->jobs, $texterkennung, KiAuslesungFabrik::bauen($this->pdo(), $this->blobService, $this->crypto));
        $takeover = $this->jobs->claim(JobExecutor::Session, 'sitzung', 60, WorkerUebernahme::JOB_TYP);
        self::assertNotNull($takeover);
        $this->jobs->schrittErledigt($takeover->id, 'sitzung', $uebernahme->schritt($takeover, $this->vault, new \DateTimeImmutable()));

        $nachher = $this->documents->find($doc);
        self::assertNotNull($vorher);
        self::assertNotNull($nachher);
        self::assertSame(OcrStatus::Fertig, $nachher->ocrStatus);
        self::assertSame(['Rechnung 7 Vereinsheim'], $texterkennung->seitentexte($nachher, $this->vault));
        // The receipt itself is untouched: status, original, PDF.
        self::assertSame(DocumentStatus::Festgeschrieben, $nachher->status);
        self::assertSame($vorher->originalBlobIds, $nachher->originalBlobIds);
        self::assertSame($vorher->pdfBlobId, $nachher->pdfBlobId);
        self::assertSame(0, $this->bestand->stand($this->bestand->letzter() ?? self::fail('no run'))['offen']);
    }

    // ------------------------------------------------------------- helpers

    private function dokument(DocumentSource $source, DocumentStatus $status = DocumentStatus::BereitZurAuswertung, OcrStatus $ocr = OcrStatus::Uebersprungen): int
    {
        $blob = $this->blobService->storeString('Bild', new BlobMeta(MagicBytes::JPEG), $this->vault);

        return $this->documents->insert($source, null, [$blob->id], $this->vault->sealDataKey(DataKey::generate()), new \DateTimeImmutable(), $status, $ocr);
    }

    /** A finished import run that wrote documents $erste..$letzte. */
    private function importLauf(int $erste, int $letzte): int
    {
        $now = new \DateTimeImmutable();
        $id = $this->imports->insert(str_repeat('a', 32), '{lieferant} {datum}', 0, $this->vault->sealDataKey(DataKey::generate()), null, $now);
        self::assertTrue($this->imports->starte($id, '{lieferant} {datum}', $now));
        $this->imports->setzeStand($id, new ArchiveImportStats(belege: $letzte - $erste + 1, ersteDocumentId: $erste, letzteDocumentId: $letzte), null);
        self::assertTrue($this->imports->finish($id, $now));

        return $id;
    }

    /**
     * @return array{int, string}
     */
    private function onlineWorker(): array
    {
        [$id, $schluessel] = $this->koppeln();
        $this->workers->heartbeat($id, 1, '1.0.0', WorkerFaehigkeit::cases(), new \DateTimeImmutable());

        return [$id, $schluessel];
    }

    /** One step of the backfill, the way JobRunner runs it; returns the job afterwards. */
    private function schritt(): Job
    {
        $job = $this->jobs->claim(JobExecutor::Session, 'sitzung', 60, BestandsOcr::JOB_TYP);
        self::assertNotNull($job);
        $this->jobs->schrittErledigt($job->id, 'sitzung', $this->bestand->schritt($job, $this->vault, new \DateTimeImmutable()));

        return $this->jobs->find($job->id) ?? self::fail('job gone');
    }

    private function laufender(): Job
    {
        return $this->bestand->laufender() ?? self::fail('no running backfill');
    }

    /**
     * @return array{import: int, von: int, bis: int, nach: int, uebergeben: int, ausgelassen: int}
     */
    private function bestandsZustand(): array
    {
        return BestandsOcr::zustand($this->bestand->letzter() ?? self::fail('no backfill'));
    }

    /**
     * Documents with a pending worker OCR job, oldest job first.
     *
     * @return list<int>
     */
    private function ocrJobs(): array
    {
        $stmt = $this->pdo()->prepare('SELECT ref_id FROM job WHERE typ = ? AND executor = ? AND status IN (?, ?) ORDER BY id');
        $stmt->execute([WorkerUebergabe::OCR, JobExecutor::Worker->value, JobStatus::Offen->value, JobStatus::Laeuft->value]);

        return array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @return list<int>
     */
    private function ocrJobIds(): array
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM job WHERE typ = ? AND executor = ? AND status IN (?, ?) ORDER BY id');
        $stmt->execute([WorkerUebergabe::OCR, JobExecutor::Worker->value, JobStatus::Offen->value, JobStatus::Laeuft->value]);

        return array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
