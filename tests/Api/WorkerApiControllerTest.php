<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Domain\JobExecutor;
use App\Domain\JobStatus;
use App\Domain\Worker;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Response;
use App\Service\Worker\WorkerProtokoll;
use App\Tests\Service\Worker\WorkerTestCase;

/**
 * The worker API end to end through the signature check
 * (docs/spec/07-worker.md section 5, issue #51/M7b-1): pairing over HTTP,
 * heartbeat, claim, complete and fail - and the protocol version check.
 */
final class WorkerApiControllerTest extends WorkerTestCase
{
    public function testPairingOverTheApiHandsOutTheKeyOnce(): void
    {
        $code = (string) $this->kopplung->codeErzeugen(null, '', new \DateTimeImmutable());
        $body = json_encode([
            'code' => $code,
            'public_key' => base64_encode(sodium_crypto_box_publickey(sodium_crypto_box_keypair())),
            'name' => 'Pi',
            'protokoll_version' => WorkerProtokoll::VERSION,
            'version' => '1.0.0',
        ], JSON_THROW_ON_ERROR);

        $antwort = $this->api->pair(new Request(HttpMethod::Post, '/api/worker/pair', ip: '203.0.113.5', rawBody: $body));
        self::assertSame(200, $antwort->status);
        $daten = self::daten($antwort);
        self::assertSame('gekoppelt', $daten['status']);
        self::assertSame(WorkerProtokoll::SCHLUESSEL_BYTES, strlen((string) base64_decode((string) $daten['schluessel'], true)));
        self::assertMatchesRegularExpression('/^([0-9A-F]{4} ){7}[0-9A-F]{4}$/', (string) $daten['fingerabdruck']);

        $nochmal = $this->api->pair(new Request(HttpMethod::Post, '/api/worker/pair', ip: '203.0.113.5', rawBody: $body));
        self::assertSame(403, $nochmal->status);
        self::assertArrayNotHasKey('schluessel', self::daten($nochmal));
    }

    public function testPairingRefusesAnIncompatibleProtocolWithAClearMessage(): void
    {
        $antwort = $this->api->pair(new Request(HttpMethod::Post, '/api/worker/pair', rawBody: json_encode([
            'code' => 'AAAA-AAAA',
            'public_key' => base64_encode(random_bytes(32)),
            'protokoll_version' => 99,
        ], JSON_THROW_ON_ERROR)));

        self::assertSame(409, $antwort->status);
        self::assertSame('protokoll', self::daten($antwort)['fehler']);
        self::assertStringContainsString('Worker-Update nötig', (string) self::daten($antwort)['meldung']);
    }

    public function testAnUnconfirmedWorkerMayOnlySendHeartbeats(): void
    {
        [$id, $schluessel] = $this->koppeln(bestaetigen: false);
        $this->jobs->enqueue('ocr', JobExecutor::Worker, 'document', 1);

        $herz = $this->heartbeat($id, $schluessel);
        self::assertSame(200, $herz->status);
        self::assertSame('gekoppelt', self::daten($herz)['status']);
        self::assertSame(0, self::daten($herz)['wartend']);

        $claim = $this->claim($id, $schluessel);
        self::assertSame(403, $claim->status);
        self::assertSame('nicht_bestaetigt', self::daten($claim)['fehler']);
        self::assertSame(JobStatus::Offen, $this->jobs->find(1)?->status);
    }

    public function testTheHeartbeatRecordsVersionAndCapabilitiesAndCountsWaitingJobs(): void
    {
        [$id, $schluessel] = $this->koppeln();
        $this->jobs->enqueue('ocr', JobExecutor::Worker, 'document', 1);
        $this->jobs->enqueue('ai_extract', JobExecutor::Worker, 'document', 1);
        $this->jobs->enqueue('ocr', JobExecutor::Session, 'document', 2);

        $antwort = $this->heartbeat($id, $schluessel, ['ocr', 'quatsch']);

        self::assertSame(200, $antwort->status);
        self::assertSame('aktiv', self::daten($antwort)['status']);
        self::assertSame(1, self::daten($antwort)['wartend']);
        self::assertNull(self::daten($antwort)['config']);
        $worker = $this->workers->find($id);
        self::assertSame('2.0.0', $worker?->appVersion);
        self::assertNotNull($worker->letzterHeartbeat);
        self::assertSame(['ocr'], array_map(static fn($f) => $f->value, $worker->faehigkeiten));
    }

    public function testAnIncompatibleProtocolIsRecordedAndClaimsAreRefused(): void
    {
        [$id, $schluessel] = $this->koppeln();
        $this->jobs->enqueue('ocr', JobExecutor::Worker, 'document', 1);

        $herz = $this->heartbeat($id, $schluessel, ['ocr'], WorkerProtokoll::VERSION + 1);
        self::assertSame(409, $herz->status);
        self::assertSame('protokoll', self::daten($herz)['fehler']);
        self::assertSame(WorkerProtokoll::VERSION + 1, $this->workers->find($id)?->protokollVersion);

        self::assertSame(409, $this->claim($id, $schluessel)->status);
        self::assertSame(JobStatus::Offen, $this->jobs->find(1)?->status);
    }

    public function testAClaimHandsOutOnlyWorkerJobsOfItsCapabilities(): void
    {
        [$id, $schluessel] = $this->koppeln();
        $ocr = $this->jobs->enqueue('ocr', JobExecutor::Worker, 'document', 1);
        $this->jobs->enqueue('ai_extract', JobExecutor::Worker, 'document', 1);
        $this->jobs->enqueue('ocr', JobExecutor::Session, 'document', 2);
        $this->heartbeat($id, $schluessel, ['ocr']);

        $antwort = $this->claim($id, $schluessel, 5);

        self::assertSame(200, $antwort->status);
        $jobs = self::daten($antwort)['jobs'];
        self::assertIsArray($jobs);
        self::assertCount(1, $jobs);
        self::assertSame($ocr, $jobs[0]['id']);
        self::assertSame(1, $jobs[0]['versuch']);
        self::assertStringStartsWith('w' . $id . '-', (string) $jobs[0]['lock']);
        self::assertSame(JobStatus::Laeuft, $this->jobs->find($ocr)?->status);
    }

    public function testCompleteNeedsTheLockOfTheClaim(): void
    {
        [$id, $schluessel] = $this->koppeln();
        $jobId = $this->jobs->enqueue('ocr', JobExecutor::Worker, 'document', 1);
        $this->heartbeat($id, $schluessel, ['ocr']);
        $lock = (string) self::daten($this->claim($id, $schluessel))['jobs'][0]['lock'];

        self::assertSame(409, $this->abschluss($id, $schluessel, $jobId, 'complete', ['lock' => $lock . 'x'])->status);
        $antwort = $this->abschluss($id, $schluessel, $jobId, 'complete', ['lock' => $lock]);

        self::assertSame(200, $antwort->status);
        self::assertSame(JobStatus::Fertig, $this->jobs->find($jobId)?->status);
        // Done is done: the job is no longer this worker's.
        self::assertSame(404, $this->abschluss($id, $schluessel, $jobId, 'complete', ['lock' => $lock])->status);
    }

    public function testAJobHeldByAnotherHolderLooksUnknown(): void
    {
        [$id, $schluessel] = $this->koppeln();
        $fremd = $this->jobs->enqueue('ocr', JobExecutor::Worker, 'document', 1);
        $session = $this->jobs->enqueue('ocr', JobExecutor::Session, 'document', 2);
        $this->jobs->claim(JobExecutor::Worker, 'w999-abc', 1800, typen: ['ocr']);
        $this->jobs->claim(JobExecutor::Session, 'session-1', 1800, typen: ['ocr']);

        foreach ([[$fremd, 'w999-abc'], [$session, 'session-1'], [4711, 'x']] as [$jobId, $lock]) {
            $antwort = $this->abschluss($id, $schluessel, $jobId, 'complete', ['lock' => $lock]);
            self::assertSame(404, $antwort->status);
            self::assertSame('job_unbekannt', self::daten($antwort)['fehler']);
        }
        self::assertSame(JobStatus::Laeuft, $this->jobs->find($fremd)?->status);
    }

    public function testAFailIsRetriedUntilTheAttemptsRunOut(): void
    {
        [$id, $schluessel] = $this->koppeln();
        $jobId = $this->jobs->enqueue('ocr', JobExecutor::Worker, 'document', 1);
        $this->heartbeat($id, $schluessel, ['ocr']);

        for ($versuch = 1; $versuch <= 3; ++$versuch) {
            $job = self::daten($this->claim($id, $schluessel))['jobs'][0];
            self::assertSame($versuch, $job['versuch']);
            $antwort = $this->abschluss($id, $schluessel, $jobId, 'fail', ['lock' => $job['lock'], 'fehler' => 'Ocr\\Timeout']);
            self::assertSame($versuch < 3 ? 'offen' : 'fehler', self::daten($antwort)['status']);
        }

        $job = $this->jobs->find($jobId);
        self::assertSame(JobStatus::Fehler, $job?->status);
        self::assertSame('Ocr\\Timeout', $job->lastError);
        self::assertSame([], self::daten($this->claim($id, $schluessel))['jobs']);
    }

    public function testAFinalFailStopsAtOnceAndAMessageIsNeverStored(): void
    {
        [$id, $schluessel] = $this->koppeln();
        $jobId = $this->jobs->enqueue('ocr', JobExecutor::Worker, 'document', 1);
        $this->heartbeat($id, $schluessel, ['ocr']);
        $lock = self::daten($this->claim($id, $schluessel))['jobs'][0]['lock'];

        $antwort = $this->abschluss($id, $schluessel, $jobId, 'fail', [
            'lock' => $lock,
            'fehler' => 'Rechnung von Max Mustermann, IBAN DE02 1203 0000 0000 2020 51',
            'endgueltig' => true,
        ]);

        self::assertSame('fehler', self::daten($antwort)['status']);
        self::assertSame('WorkerFehler', $this->jobs->find($jobId)?->lastError);
    }

    public function testAJobPastItsAttemptsIsClosedInsteadOfHandedOut(): void
    {
        [$id, $schluessel] = $this->koppeln();
        $jobId = $this->jobs->enqueue('ocr', JobExecutor::Worker, 'document', 1);
        $this->pdo()->exec('UPDATE job SET attempts = 3 WHERE id = ' . $jobId);
        $this->heartbeat($id, $schluessel, ['ocr']);

        self::assertSame([], self::daten($this->claim($id, $schluessel))['jobs']);
        self::assertSame(JobStatus::Fehler, $this->jobs->find($jobId)?->status);
    }

    /**
     * @param list<string> $faehigkeiten
     */
    private function heartbeat(int $id, string $schluessel, array $faehigkeiten = [], int $protokoll = WorkerProtokoll::VERSION): Response
    {
        $body = json_encode(['protokoll_version' => $protokoll, 'version' => '2.0.0', 'faehigkeiten' => $faehigkeiten, 'config_version' => 0], JSON_THROW_ON_ERROR);

        return $this->aufruf(
            $this->signiert($id, $schluessel, '/api/worker/heartbeat', $body),
            fn(Request $r, array $p, Worker $w): Response => $this->api->heartbeat($r, $w),
        );
    }

    private function claim(int $id, string $schluessel, int $max = 1): Response
    {
        return $this->aufruf(
            $this->signiert($id, $schluessel, '/api/worker/jobs/claim', json_encode(['max' => $max], JSON_THROW_ON_ERROR)),
            fn(Request $r, array $p, Worker $w): Response => $this->api->claim($r, $w),
        );
    }

    /**
     * @param array<string, mixed> $daten
     */
    private function abschluss(int $id, string $schluessel, int $jobId, string $aktion, array $daten): Response
    {
        return $this->aufruf(
            $this->signiert($id, $schluessel, sprintf('/api/worker/jobs/%d/%s', $jobId, $aktion), json_encode($daten, JSON_THROW_ON_ERROR)),
            fn(Request $r, array $p, Worker $w): Response => $aktion === 'complete' ? $this->api->complete($r, $p, $w) : $this->api->fail($r, $p, $w),
            ['id' => (string) $jobId],
        );
    }
}
