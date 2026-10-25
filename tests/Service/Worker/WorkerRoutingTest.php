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
use App\Repository\MailQueueRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Service\Crypto\DataKey;
use App\Service\Ki\KiFaehigkeiten;
use App\Service\Mail\Mailer;
use App\Service\Mail\MailSettingsRepository;
use App\Service\Mail\MailTemplates;
use App\Service\Mail\WorkerAlarm;
use App\Service\Upload\MagicBytes;
use App\Service\Worker\WorkerFallback;
use App\Service\Worker\WorkerArtefakte;
use App\Service\Worker\WorkerRouting;
use App\Service\Worker\WorkerUebergabe;

/**
 * Routing and fallback of the worker module (issue #53/M7b-3,
 * docs/spec/07-worker.md section 6 and the Pflicht-Tests there): module
 * off -> never a worker job; on + online -> worker; offline -> fallback
 * after the period; OCR skipped and catchable; plus the review points of
 * #52 (AI keys only with module and capability).
 */
final class WorkerRoutingTest extends WorkerTestCase
{
    public function testModuleOffNeverQueuesWorkerJobs(): void
    {
        [$doc] = $this->dokument();
        [$id] = $this->onlineWorker();
        $this->settings->set(WorkerRouting::SETTING_AKTIV, '0');

        self::assertSame(JobExecutor::Browser, $this->uebergabe->einreihen('render_pages', JobExecutor::Browser, $doc, $this->vault, new \DateTimeImmutable()));
        self::assertNull($this->uebergabe->einreihen(WorkerUebergabe::OCR, null, $doc, $this->vault, new \DateTimeImmutable()), 'OCR has no route without the worker.');
        self::assertSame(0, $this->zaehle('SELECT COUNT(*) FROM job WHERE executor = ?', [JobExecutor::Worker->value]));
        self::assertSame(0, $this->zaehle('SELECT COUNT(*) FROM worker_grant WHERE worker_id = ?', [$id]));
    }

    public function testActiveAndOnlineRoutesToTheWorkerWithGrants(): void
    {
        [$doc, $blobId] = $this->dokument();
        [$id] = $this->onlineWorker();

        self::assertSame(JobExecutor::Worker, $this->uebergabe->einreihen('ai_extract', JobExecutor::Session, $doc, $this->vault, new \DateTimeImmutable()));
        self::assertSame([$blobId], $this->grants->blobIdsFuer($id, $doc, new \DateTimeImmutable()));
        // One pending job per type: a repeated step queues nothing twice.
        self::assertNull($this->uebergabe->einreihen('ai_extract', JobExecutor::Session, $doc, $this->vault, new \DateTimeImmutable()));
        self::assertSame(1, $this->zaehle('SELECT COUNT(*) FROM job WHERE typ = ?', ['ai_extract']));
    }

    public function testOfflineOrWithoutTheCapabilityOrDuringARotationTheBrowserRuns(): void
    {
        [$doc] = $this->dokument();
        [$id] = $this->onlineWorker(['ocr', 'ai']);
        // Capability missing: render_pages stays in the browser.
        self::assertSame(JobExecutor::Browser, $this->uebergabe->einreihen('render_pages', JobExecutor::Browser, $doc, $this->vault, new \DateTimeImmutable()));

        // Rotation: no grants from a session (issue #90) -> session route.
        $this->rotation = true;
        self::assertSame(JobExecutor::Session, $this->uebergabe->einreihen('ai_extract', JobExecutor::Session, $doc, $this->vault, new \DateTimeImmutable()));
        $this->rotation = false;

        // Heartbeat older than 5 minutes: offline.
        $this->workers->heartbeat($id, 1, '1.0.0', [WorkerFaehigkeit::Ocr], new \DateTimeImmutable('-6 minutes'));
        self::assertNull($this->uebergabe->einreihen(WorkerUebergabe::OCR, null, $doc, $this->vault, new \DateTimeImmutable()));
        self::assertSame(0, $this->zaehle('SELECT COUNT(*) FROM job WHERE executor = ?', [JobExecutor::Worker->value]));
    }

    public function testOfflineWorkerJobsFallBackAfterThePeriodAndOcrBecomesCatchable(): void
    {
        [$doc] = $this->dokument(OcrStatus::Uebersprungen);
        [$id] = $this->onlineWorker();
        $now = new \DateTimeImmutable();
        $render = $this->viaWorker('render_pages', $doc);
        $ki = $this->viaWorker('ai_extract', $doc);
        $ocr = $this->viaWorker(WorkerUebergabe::OCR, $doc);
        self::assertSame(0, $this->uebergabe->nachholbar(), 'A pending OCR job is not catchable.');

        // Silent for 30 minutes - within the default 60: nothing moves.
        $this->workers->heartbeat($id, 1, '1.0.0', WorkerFaehigkeit::cases(), $now->modify('-30 minutes'));
        self::assertSame(0, $this->fallback->ausfuehren($now));

        // Silent for 61 minutes: everything goes back.
        $this->workers->heartbeat($id, 1, '1.0.0', WorkerFaehigkeit::cases(), $now->modify('-61 minutes'));
        self::assertSame(3, $this->fallback->ausfuehren($now));
        self::assertSame([JobExecutor::Browser, JobStatus::Offen], $this->stand($render));
        self::assertSame([JobExecutor::Session, JobStatus::Offen], $this->stand($ki));
        self::assertSame([JobExecutor::Worker, JobStatus::Uebersprungen], $this->stand($ocr));
        self::assertSame(0, $this->zaehle('SELECT COUNT(*) FROM worker_grant WHERE document_id = ?', [$doc]), 'Grants go with the last worker job.');

        // OCR nachholen once the worker is back.
        self::assertSame(1, $this->uebergabe->nachholbar());
        $this->workers->heartbeat($id, 1, '1.0.0', WorkerFaehigkeit::cases(), $now);
        self::assertSame(1, $this->uebergabe->ocrNachholen($this->vault, 50, $now));
        self::assertSame(0, $this->uebergabe->nachholbar());
        self::assertTrue($this->jobs->wartetAufWorker('document', $doc));
    }

    public function testThereIsNoSeparateImageCleanupJob(): void
    {
        // Issue #248: `ocr` straightens and rotates the PDF/A itself, the GD
        // fallback cleans up without a browser - no worker job of its own.
        self::assertSame(['ocr'], WorkerFaehigkeit::Ocr->jobTypen());
        self::assertSame([], WorkerArtefakte::erlaubteKinds('image_cleanup'));
        self::assertNotContains('image_cleanup', WorkerRouting::VORGAENGER['ai_extract']);
    }

    public function testOcrCanBeCaughtUpForOneReceipt(): void
    {
        [$doc] = $this->dokument(OcrStatus::Uebersprungen);
        [$anderer] = $this->dokument(OcrStatus::Uebersprungen);
        [$fertig] = $this->dokument(OcrStatus::Fertig);
        $now = new \DateTimeImmutable();

        // No worker: nothing to offer, nothing queued.
        self::assertFalse($this->uebergabe->ocrNachholbarFuer($doc, $now));
        self::assertFalse($this->uebergabe->ocrNachholenFuer($doc, $this->vault, $now));

        $this->onlineWorker();
        self::assertTrue($this->uebergabe->ocrNachholbarFuer($doc, $now));
        self::assertFalse($this->uebergabe->ocrNachholbarFuer($fertig, $now), 'Only a skipped OCR is caught up.');
        self::assertFalse($this->uebergabe->ocrNachholenFuer($fertig, $this->vault, $now));

        self::assertTrue($this->uebergabe->ocrNachholenFuer($doc, $this->vault, $now));
        self::assertTrue($this->jobs->wartetAufWorker('document', $doc));
        self::assertFalse($this->jobs->wartetAufWorker('document', $anderer), 'Only this receipt, not the batch.');
        self::assertNotSame(0, $this->zaehle('SELECT COUNT(*) FROM worker_grant WHERE document_id = ?', [$doc]));

        // Pending now: neither offered nor queued twice.
        self::assertFalse($this->uebergabe->ocrNachholbarFuer($doc, $now));
        self::assertFalse($this->uebergabe->ocrNachholenFuer($doc, $this->vault, $now));
        self::assertSame(1, $this->zaehle('SELECT COUNT(*) FROM job WHERE typ = ? AND ref_id = ?', [WorkerUebergabe::OCR, $doc]));
    }

    public function testSwitchingTheModuleOffFallsBackAtOnceAndClaimsNothing(): void
    {
        [$doc] = $this->dokument();
        [$id, $schluessel] = $this->onlineWorker();
        $job = $this->viaWorker('ai_extract', $doc);
        $this->settings->set(WorkerRouting::SETTING_AKTIV, '0');

        self::assertSame([], self::daten($this->claim($id, $schluessel))['jobs']);
        self::assertSame(1, $this->fallback->ausfuehren(new \DateTimeImmutable()));
        self::assertSame([JobExecutor::Session, JobStatus::Offen], $this->stand($job));
    }

    public function testAJobTheWorkerHoldsIsNotTakenAway(): void
    {
        [$doc] = $this->dokument();
        [$id, $schluessel] = $this->onlineWorker();
        $job = $this->viaWorker('ai_extract', $doc);
        self::assertCount(1, self::daten($this->claim($id, $schluessel))['jobs']);

        $this->settings->set(WorkerRouting::SETTING_AKTIV, '0');
        self::assertSame(0, $this->fallback->ausfuehren(new \DateTimeImmutable()), 'Its lock is still valid.');
        self::assertSame([JobExecutor::Worker, JobStatus::Laeuft], $this->stand($job));
    }

    public function testWhatAFallenBackJobCommittedIsNotTakenForTheNewRun(): void
    {
        [$doc] = $this->dokument();
        [$id, $schluessel] = $this->onlineWorker();
        $job = $this->viaWorker('ai_extract', $doc);
        self::assertCount(1, self::daten($this->claim($id, $schluessel))['jobs']);
        $zeile = $this->artifacts->reserviere($doc, ArtifactKind::Extraction, 0, $this->vault->sealDataKey(DataKey::generate()), $job, new \DateTimeImmutable());
        self::assertNotNull($zeile);
        self::assertTrue($this->artifacts->festschreiben($zeile, ArtifactKind::Extraction, null, 'unverschluesselt'));

        // The worker died holding it: lock expired, period over.
        $this->pdo()->prepare('UPDATE job SET locked_until = ? WHERE id = ?')->execute([new \DateTimeImmutable('-1 minute')->format('Y-m-d H:i:s'), $job]);
        $this->workers->heartbeat($id, 1, '1.0.0', WorkerFaehigkeit::cases(), new \DateTimeImmutable('-2 hours'));
        self::assertSame(1, $this->fallback->ausfuehren(new \DateTimeImmutable()));

        self::assertSame([JobExecutor::Session, JobStatus::Offen], $this->stand($job));
        self::assertSame([], $this->artifacts->fuerDokument($doc, ArtifactKind::Extraction), 'Hidden, not part of the session run.');
        self::assertSame('!extraction', $this->artifacts->rohzeile($zeile)['kind'] ?? null);
    }

    public function testJobsOfACapabilityTheWorkerDroppedFallBackRightAway(): void
    {
        [$doc] = $this->dokument();
        [$id] = $this->onlineWorker();
        $render = $this->viaWorker('render_pages', $doc);
        $this->workers->heartbeat($id, 1, '1.0.0', [WorkerFaehigkeit::Ai], new \DateTimeImmutable());

        self::assertSame(1, $this->fallback->ausfuehren(new \DateTimeImmutable()));
        self::assertSame([JobExecutor::Browser, JobStatus::Offen], $this->stand($render));
    }

    public function testAFinalFailureQueuesTheSessionRun(): void
    {
        [$doc] = $this->dokument();
        [$id, $schluessel] = $this->onlineWorker();
        $this->viaWorker('ai_extract', $doc);
        $job = self::daten($this->claim($id, $schluessel))['jobs'][0];

        $antwort = $this->aufruf(
            $this->signiert($id, $schluessel, '/api/worker/jobs/' . $job['id'] . '/fail', json_encode(['lock' => $job['lock'], 'fehler' => 'Absturz', 'endgueltig' => true], JSON_THROW_ON_ERROR)),
            fn(Request $r, array $p, Worker $w): Response => $this->api->fail($r, $p, $w),
            ['id' => (string) $job['id']],
        );
        self::assertSame(200, $antwort->status, $antwort->body);
        self::assertSame(1, $this->zaehle('SELECT COUNT(*) FROM job WHERE typ = ? AND executor = ? AND status = ?', ['ai_extract', JobExecutor::Session->value, JobStatus::Offen->value]));
    }

    public function testTheWorkersAiExtractWaitsForOcrOfTheSameDocument(): void
    {
        [$doc] = $this->dokument();
        [$id, $schluessel] = $this->onlineWorker(['ai']);
        // Queued while the worker still reported OCR.
        $ocr = $this->jobs->enqueue(WorkerUebergabe::OCR, JobExecutor::Worker, 'document', $doc);
        $this->viaWorker('ai_extract', $doc);

        self::assertSame([], self::daten($this->claim($id, $schluessel))['jobs'], 'OCR still pending: the AI waits.');
        $this->jobs->finish($ocr, JobStatus::Fertig);
        self::assertCount(1, self::daten($this->claim($id, $schluessel))['jobs']);
    }

    public function testAiKeysOnlyWithTheModuleOnAndTheAiCapability(): void
    {
        $this->anbieter->insert('Testanbieter', 'https://api.example.org/v1', 'gpt-test', new KiFaehigkeiten(true, true, 4, 1000), 60, true, 'sk-geheim-1');
        [$id, $schluessel] = $this->koppeln();

        $profile = fn(array $faehigkeiten, int $bekannt): ?array => $this->konfigProfile($id, $schluessel, $faehigkeiten, $bekannt);
        self::assertSame([], $profile(['ocr'], 0), 'Without the ai capability: no profile, no key.');
        $mitKey = array_values(array_filter($profile(['ocr', 'ai'], 1) ?? [], static fn(array $a): bool => $a['name'] === 'Testanbieter'));
        self::assertSame('sk-geheim-1', $mitKey[0]['api_key'] ?? null);

        $this->settings->set(WorkerRouting::SETTING_AKTIV, '0');
        self::assertSame([], $profile(['ocr', 'ai'], 2), 'Module off: the keys are taken back.');
    }

    public function testTheAlarmGoesOutOnceAfterADayWhenJobsWereDue(): void
    {
        $queue = new MailQueueRepository($this->pdo(), $this->crypto);
        $mailer = new Mailer($queue, new MailSettingsRepository($this->settings, $this->crypto), new MailTemplates(dirname(__DIR__, 3) . '/app/views/mail'));
        $users = new UserRepository($this->pdo());
        $fallback = new WorkerFallback(
            $this->routing,
            $this->workers,
            $this->jobs,
            $this->freigaben,
            $this->settings,
            $this->artifacts,
            new WorkerAlarm($mailer, $this->crypto, $users, new UserAccessRepository($this->pdo())),
        );
        $this->admin($users);

        [$doc] = $this->dokument();
        [$id] = $this->onlineWorker();
        $now = new \DateTimeImmutable();

        // Silent for a day, but nothing was due for it: no alarm.
        $this->workers->heartbeat($id, 1, '1.0.0', WorkerFaehigkeit::cases(), $now->modify('-25 hours'));
        $fallback->ausfuehren($now);
        self::assertSame(0, $this->mails());

        // A job was due and went back: one alarm, once per outage.
        $this->jobs->enqueue('ai_extract', JobExecutor::Worker, 'document', $doc);
        self::assertSame(1, $fallback->ausfuehren($now));
        self::assertSame(1, $this->mails());
        $this->jobs->enqueue('render_pages', JobExecutor::Worker, 'document', $doc);
        $fallback->ausfuehren($now);
        self::assertSame(1, $this->mails(), 'Once per outage.');
    }

    // ------------------------------------------------------------- helpers

    /**
     * @return array{int, int} document id, blob id
     */
    private function dokument(OcrStatus $ocr = OcrStatus::Keine): array
    {
        $blob = $this->blobService->storeString('Bild', new BlobMeta(MagicBytes::JPEG), $this->vault);
        $doc = $this->documents->insert(
            DocumentSource::Intern,
            null,
            [$blob->id],
            $this->vault->sealDataKey(DataKey::generate()),
            new \DateTimeImmutable(),
            DocumentStatus::BereitZurAuswertung,
            $ocr,
        );

        return [$doc, $blob->id];
    }

    /**
     * @param list<string> $faehigkeiten
     * @return array{int, string}
     */
    private function onlineWorker(array $faehigkeiten = ['ocr', 'render', 'ai']): array
    {
        [$id, $schluessel] = $this->koppeln();
        $this->workers->heartbeat($id, 1, '1.0.0', array_map(WorkerFaehigkeit::from(...), $faehigkeiten), new \DateTimeImmutable());

        return [$id, $schluessel];
    }

    private function viaWorker(string $typ, int $doc): int
    {
        self::assertSame(JobExecutor::Worker, $this->uebergabe->einreihen($typ, null, $doc, $this->vault, new \DateTimeImmutable()));

        return (int) $this->pdo()->query('SELECT MAX(id) FROM job')->fetchColumn();
    }

    /**
     * @return array{JobExecutor, JobStatus}
     */
    private function stand(int $jobId): array
    {
        $job = $this->jobs->find($jobId);
        self::assertNotNull($job);

        return [$job->executor, $job->status];
    }

    /**
     * @param list<int|string> $parameter
     */
    private function zaehle(string $sql, array $parameter): int
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($parameter);

        return (int) $stmt->fetchColumn();
    }

    private function claim(int $id, string $schluessel): Response
    {
        return $this->aufruf(
            $this->signiert($id, $schluessel, '/api/worker/jobs/claim', '{"max":5}'),
            fn(Request $r, array $p, Worker $w): Response => $this->api->claim($r, $w),
        );
    }

    /**
     * The AI profiles of the configuration a heartbeat hands out, or null
     * when it hands out none (unchanged).
     *
     * @param list<string> $faehigkeiten
     * @return list<array<string, mixed>>|null
     */
    private function konfigProfile(int $id, string $schluessel, array $faehigkeiten, int $bekannt): ?array
    {
        $body = json_encode(['protokoll_version' => 1, 'version' => '2.0.0', 'faehigkeiten' => $faehigkeiten, 'config_version' => $bekannt], JSON_THROW_ON_ERROR);
        $antwort = $this->aufruf(
            $this->signiert($id, $schluessel, '/api/worker/heartbeat', $body),
            fn(Request $r, array $p, Worker $w): Response => $this->api->heartbeat($r, $w),
        );
        $config = self::daten($antwort)['config'];
        if ($config === null) {
            return null;
        }
        $json = sodium_crypto_box_seal_open((string) base64_decode((string) $config['versiegelt'], true), $this->workerSchluesselpaar);
        self::assertIsString($json);

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR)['ki_anbieter'];
    }

    private function admin(UserRepository $users): int
    {
        $id = $users->insert(
            $this->crypto->encrypt('admin@example.org'),
            $this->crypto->blindIndex()->forValue('user.email', 'admin@example.org'),
            $this->crypto->encrypt('Admin'),
            password_hash('x', PASSWORD_DEFAULT),
        );
        $this->pdo()->prepare("INSERT INTO user_role (user_id, role_id) SELECT ?, id FROM role WHERE name = 'Admin'")->execute([$id]);

        return $id;
    }

    private function mails(): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn();
    }
}
