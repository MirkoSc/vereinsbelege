<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\JobExecutor;
use App\Domain\JobStatus;
use App\Repository\JobRepository;
use App\Service\Job\JobSchrittErgebnis;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;

/**
 * The `job` table and its lock (`locked_until`, docs/spec/06-betrieb.md
 * section 4) against a real server, on the schema the installer creates.
 */
final class JobRepositoryTest extends DatabaseTestCase
{
    private JobRepository $jobs;

    private \DateTimeImmutable $t0;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->jobs = new JobRepository($this->pdo());
        $this->t0 = new \DateTimeImmutable('2026-03-01 12:00:00');
    }

    public function testEnqueueStartsOpenWithoutLock(): void
    {
        $id = $this->jobs->enqueue('ki_auslesen', JobExecutor::Session, 'document', 42, ['seite' => 1], 'start', $this->t0);

        $job = $this->jobs->find($id);
        self::assertNotNull($job);
        self::assertSame(JobStatus::Offen, $job->status);
        self::assertSame(JobExecutor::Session, $job->executor);
        self::assertSame('document', $job->refType);
        self::assertSame(42, $job->refId);
        self::assertSame(['seite' => 1], $job->state);
        self::assertSame(0, $job->attempts);
        self::assertNull($job->lockedBy);
        self::assertNull($job->lockedUntil);
    }

    public function testClaimLocksTheJob(): void
    {
        $id = $this->jobs->enqueue('x', JobExecutor::Session, now: $this->t0);

        $job = $this->jobs->claim(JobExecutor::Session, 'tab-a', 30, now: $this->t0);

        self::assertNotNull($job);
        self::assertSame($id, $job->id);
        self::assertSame(JobStatus::Laeuft, $job->status);
        self::assertSame('tab-a', $job->lockedBy);
        self::assertSame(1, $job->attempts);
        self::assertEquals($this->t0->modify('+30 seconds'), $job->lockedUntil);
    }

    /** The point of the lock: two open tabs must not process the same job. */
    public function testSecondClaimWhileLockedGetsNothing(): void
    {
        $this->jobs->enqueue('x', JobExecutor::Session, now: $this->t0);

        self::assertNotNull($this->jobs->claim(JobExecutor::Session, 'tab-a', 30, now: $this->t0));

        self::assertNull($this->jobs->claim(JobExecutor::Session, 'tab-b', 30, now: $this->t0->modify('+10 seconds')));
        // Exactly at expiry the lock is over (locked_until <= now).
        self::assertNotNull($this->jobs->claim(JobExecutor::Session, 'tab-b', 30, now: $this->t0->modify('+30 seconds')));
    }

    /** A crashed holder must not park the job forever. */
    public function testExpiredLockIsTakenOver(): void
    {
        $id = $this->jobs->enqueue('x', JobExecutor::Session, now: $this->t0);
        $this->jobs->claim(JobExecutor::Session, 'tab-a', 30, now: $this->t0);

        $job = $this->jobs->claim(JobExecutor::Session, 'tab-b', 30, now: $this->t0->modify('+31 seconds'));

        self::assertNotNull($job);
        self::assertSame($id, $job->id);
        self::assertSame('tab-b', $job->lockedBy);
        self::assertSame(2, $job->attempts);
    }

    public function testClaimSkipsLockedJobAndTakesTheNextOne(): void
    {
        $erster = $this->jobs->enqueue('x', JobExecutor::Session, now: $this->t0);
        $zweiter = $this->jobs->enqueue('x', JobExecutor::Session, now: $this->t0);

        self::assertSame($erster, $this->jobs->claim(JobExecutor::Session, 'a', 30, now: $this->t0)?->id);
        self::assertSame($zweiter, $this->jobs->claim(JobExecutor::Session, 'b', 30, now: $this->t0)?->id);
        self::assertNull($this->jobs->claim(JobExecutor::Session, 'c', 30, now: $this->t0));
    }

    public function testClaimFiltersByExecutorAndType(): void
    {
        $this->jobs->enqueue('rendern', JobExecutor::Browser, now: $this->t0);
        $ocr = $this->jobs->enqueue('ocr', JobExecutor::Worker, now: $this->t0);

        self::assertNull($this->jobs->claim(JobExecutor::Session, 'a', 30, now: $this->t0));
        self::assertNull($this->jobs->claim(JobExecutor::Worker, 'w', 30, 'rendern', $this->t0));
        self::assertSame($ocr, $this->jobs->claim(JobExecutor::Worker, 'w', 30, 'ocr', $this->t0)?->id);
    }

    public function testFinishedAndFailedJobsAreNotClaimed(): void
    {
        $fertig = $this->jobs->enqueue('x', JobExecutor::Session, now: $this->t0);
        $fehler = $this->jobs->enqueue('x', JobExecutor::Session, now: $this->t0);
        $this->jobs->finish($fertig, JobStatus::Fertig, now: $this->t0);
        $this->jobs->fail($fehler, 'RuntimeException', $this->t0);

        self::assertNull($this->jobs->claim(JobExecutor::Session, 'a', 30, now: $this->t0->modify('+1 day')));
    }

    public function testHeartbeatExtendsOnlyForTheHolder(): void
    {
        $id = $this->jobs->enqueue('x', JobExecutor::Session, now: $this->t0);
        $this->jobs->claim(JobExecutor::Session, 'tab-a', 30, now: $this->t0);

        self::assertFalse($this->jobs->heartbeat($id, 'tab-b', 30, $this->t0->modify('+5 seconds')));
        self::assertTrue($this->jobs->heartbeat($id, 'tab-a', 30, $this->t0->modify('+20 seconds')));
        self::assertEquals($this->t0->modify('+50 seconds'), $this->jobs->find($id)?->lockedUntil);

        // Once the lock ran out and someone else took over, the old holder stops.
        $this->jobs->claim(JobExecutor::Session, 'tab-b', 30, now: $this->t0->modify('+60 seconds'));
        self::assertFalse($this->jobs->heartbeat($id, 'tab-a', 30, $this->t0->modify('+61 seconds')));
    }

    public function testFinishKeepsStateWhenNoneGivenAndClearsTheLock(): void
    {
        $id = $this->jobs->enqueue('x', JobExecutor::Session, state: ['n' => 3], now: $this->t0);
        $this->jobs->claim(JobExecutor::Session, 'a', 30, now: $this->t0);

        $this->jobs->finish($id, JobStatus::Fertig, now: $this->t0);

        $job = $this->jobs->find($id);
        self::assertSame(JobStatus::Fertig, $job?->status);
        self::assertSame(['n' => 3], $job?->state);
        self::assertNull($job?->lockedBy);
        self::assertNull($job?->lockedUntil);
    }

    public function testFailStoresOnlyTheErrorClass(): void
    {
        $id = $this->jobs->enqueue('x', JobExecutor::Session, now: $this->t0);

        $this->jobs->fail($id, \RuntimeException::class, $this->t0);

        $job = $this->jobs->find($id);
        self::assertSame(JobStatus::Fehler, $job?->status);
        self::assertSame('RuntimeException', $job?->lastError);
    }

    public function testReleaseMakesTheJobAvailableAgain(): void
    {
        $id = $this->jobs->enqueue('x', JobExecutor::Session, now: $this->t0);
        $this->jobs->claim(JobExecutor::Session, 'a', 30, now: $this->t0);

        $this->jobs->release($id, $this->t0);

        self::assertSame(JobStatus::Offen, $this->jobs->find($id)?->status);
        self::assertSame($id, $this->jobs->claim(JobExecutor::Session, 'b', 30, now: $this->t0)?->id);
    }

    /** issue #29/M4-7: JobRunner claims only the job types an account's rights allow. */
    public function testClaimFiltersByAListOfTypes(): void
    {
        $pdf = $this->jobs->enqueue('pdf_erzeugen', JobExecutor::Session, now: $this->t0);
        $this->jobs->enqueue('ki_auslesen', JobExecutor::Session, now: $this->t0);

        $job = $this->jobs->claim(JobExecutor::Session, 'a', 30, now: $this->t0, typen: ['pdf_erzeugen']);

        self::assertSame($pdf, $job?->id);
    }

    /** An account with no right for any job type must not claim anything - not "any type". */
    public function testAnEmptyTypeListClaimsNothing(): void
    {
        $this->jobs->enqueue('pdf_erzeugen', JobExecutor::Session, now: $this->t0);

        self::assertNull($this->jobs->claim(JobExecutor::Session, 'a', 30, now: $this->t0, typen: []));
    }

    public function testSchrittErledigtWritesTheResultAndFreesTheLockAndResetsAttempts(): void
    {
        $id = $this->jobs->enqueue('pdf_erzeugen', JobExecutor::Session, now: $this->t0);
        $this->jobs->claim(JobExecutor::Session, 'a', 30, now: $this->t0);
        // A second claim of the same job by a different holder (after the
        // lock ran out) would raise attempts past 1 - schrittErledigt() must
        // reset it, not just leave it as the claim left it.
        $this->jobs->release($id, $this->t0);
        $this->jobs->claim(JobExecutor::Session, 'a', 30, now: $this->t0);

        $geschafft = $this->jobs->schrittErledigt(
            $id,
            'a',
            JobSchrittErgebnis::weiter('seite', ['arbeit' => [1, 2]]),
            $this->t0,
        );

        self::assertTrue($geschafft);
        $job = $this->jobs->find($id);
        self::assertSame(JobStatus::Offen, $job?->status);
        self::assertSame('seite', $job?->step);
        self::assertSame(['arbeit' => [1, 2]], $job?->state);
        self::assertSame(0, $job?->attempts);
        self::assertNull($job?->lockedBy);
        self::assertNull($job?->lockedUntil);
    }

    /** Only the holder of the lock may write a step's result. */
    public function testSchrittErledigtDoesNothingForAnyoneButTheHolder(): void
    {
        $id = $this->jobs->enqueue('pdf_erzeugen', JobExecutor::Session, now: $this->t0);
        $this->jobs->claim(JobExecutor::Session, 'a', 30, now: $this->t0);

        $geschafft = $this->jobs->schrittErledigt($id, 'b', JobSchrittErgebnis::fertig(), $this->t0);

        self::assertFalse($geschafft);
        self::assertSame(JobStatus::Laeuft, $this->jobs->find($id)?->status);
    }

    public function testFailWithALockedByOnlyWorksForTheHolder(): void
    {
        $id = $this->jobs->enqueue('x', JobExecutor::Session, now: $this->t0);
        $this->jobs->claim(JobExecutor::Session, 'a', 30, now: $this->t0);

        $this->jobs->fail($id, \RuntimeException::class, $this->t0, 'b');
        self::assertSame(JobStatus::Laeuft, $this->jobs->find($id)?->status, 'a stranger must not fail the job');

        $this->jobs->fail($id, \RuntimeException::class, $this->t0, 'a');
        self::assertSame(JobStatus::Fehler, $this->jobs->find($id)?->status);
    }

    public function testZaehleOffenCountsOpenAndRunningJobsOfTheGivenTypes(): void
    {
        $this->jobs->enqueue('pdf_erzeugen', JobExecutor::Session, now: $this->t0);
        $laufend = $this->jobs->enqueue('pdf_erzeugen', JobExecutor::Session, now: $this->t0);
        $this->jobs->claim(JobExecutor::Session, 'a', 30, 'pdf_erzeugen', $this->t0);
        $fertig = $this->jobs->enqueue('pdf_erzeugen', JobExecutor::Session, now: $this->t0);
        $this->jobs->finish($fertig, JobStatus::Fertig, now: $this->t0);
        $this->jobs->enqueue('ki_auslesen', JobExecutor::Session, now: $this->t0);
        $this->jobs->enqueue('pdf_erzeugen', JobExecutor::Worker, now: $this->t0);

        self::assertSame(2, $this->jobs->zaehleOffen(JobExecutor::Session, ['pdf_erzeugen']));
        self::assertSame(3, $this->jobs->zaehleOffen(JobExecutor::Session, ['pdf_erzeugen', 'ki_auslesen']));
        self::assertSame(0, $this->jobs->zaehleOffen(JobExecutor::Session, []));
        self::assertNotNull($laufend);
    }

    public function testDeleteFinishedBeforeOnlyRemovesOldFinishedJobs(): void
    {
        $alt = $this->jobs->enqueue('x', JobExecutor::Session, now: $this->t0);
        $this->jobs->finish($alt, JobStatus::Fertig, now: $this->t0);
        $altUebersprungen = $this->jobs->enqueue('x', JobExecutor::Session, now: $this->t0);
        $this->jobs->finish($altUebersprungen, JobStatus::Uebersprungen, now: $this->t0);
        $altFehler = $this->jobs->enqueue('x', JobExecutor::Session, now: $this->t0);
        $this->jobs->fail($altFehler, 'RuntimeException', $this->t0);
        $altOffen = $this->jobs->enqueue('x', JobExecutor::Session, now: $this->t0);
        $neu = $this->jobs->enqueue('x', JobExecutor::Session, now: $this->t0->modify('+2 days'));
        $this->jobs->finish($neu, JobStatus::Fertig, now: $this->t0->modify('+2 days'));

        $geloescht = $this->jobs->deleteFinishedBefore($this->t0->modify('+1 day'));

        self::assertSame(2, $geloescht);
        self::assertNull($this->jobs->find($alt));
        self::assertNull($this->jobs->find($altUebersprungen));
        self::assertNotNull($this->jobs->find($altFehler), 'failed jobs stay for a human to look at');
        self::assertNotNull($this->jobs->find($altOffen));
        self::assertNotNull($this->jobs->find($neu));
    }

    /** issue #30/M4-8: PdfErzeugung enqueues render_pages at most once per document. */
    public function testGibtEsFindsAJobOfAnyStatusForThisRow(): void
    {
        self::assertFalse($this->jobs->gibtEs('render_pages', 'document', 42));

        $id = $this->jobs->enqueue('render_pages', JobExecutor::Browser, 'document', 42, now: $this->t0);
        self::assertTrue($this->jobs->gibtEs('render_pages', 'document', 42));
        self::assertFalse($this->jobs->gibtEs('render_pages', 'document', 43), 'anderes Dokument');
        self::assertFalse($this->jobs->gibtEs('pdf_erzeugen', 'document', 42), 'anderer Typ');

        // Even finished, it still counts as "one was created".
        $this->jobs->finish($id, JobStatus::Fertig, now: $this->t0);
        self::assertTrue($this->jobs->gibtEs('render_pages', 'document', 42));
    }

    /** issue #30/M4-8: the browser job's many small requests share one claim. */
    public function testFortschrittWritesStateAndExtendsTheLockOnlyForTheHolder(): void
    {
        $id = $this->jobs->enqueue('render_pages', JobExecutor::Browser, now: $this->t0);
        $this->jobs->claim(JobExecutor::Browser, 'browser-a', 120, now: $this->t0);

        self::assertFalse($this->jobs->fortschritt($id, 'browser-b', ['seq' => 1], 120, $this->t0));

        self::assertTrue($this->jobs->fortschritt($id, 'browser-a', ['seq' => 1], 120, $this->t0->modify('+30 seconds')));
        $job = $this->jobs->find($id);
        self::assertSame(JobStatus::Laeuft, $job?->status);
        self::assertSame('browser-a', $job?->lockedBy, 'the lock stays held, unlike schrittErledigt()');
        self::assertSame(['seq' => 1], $job?->state);
        self::assertEquals($this->t0->modify('+150 seconds'), $job?->lockedUntil);
    }

    /** A page just stored is progress, the same reasoning schrittErledigt() follows. */
    public function testFortschrittResetsAttempts(): void
    {
        $id = $this->jobs->enqueue('render_pages', JobExecutor::Browser, now: $this->t0);
        $this->jobs->claim(JobExecutor::Browser, 'a', 120, now: $this->t0);
        $this->jobs->release($id, $this->t0);
        $this->jobs->claim(JobExecutor::Browser, 'a', 120, now: $this->t0);

        $this->jobs->fortschritt($id, 'a', ['seq' => 1], 120, $this->t0);

        self::assertSame(0, $this->jobs->find($id)?->attempts);
    }

    /** Once the lock expired and someone else claimed the job, the old holder must not write over it. */
    public function testFortschrittDoesNothingOnceTheLockWasTakenOver(): void
    {
        $id = $this->jobs->enqueue('render_pages', JobExecutor::Browser, now: $this->t0);
        $this->jobs->claim(JobExecutor::Browser, 'a', 30, now: $this->t0);
        $this->jobs->claim(JobExecutor::Browser, 'b', 30, now: $this->t0->modify('+31 seconds'));

        self::assertFalse($this->jobs->fortschritt($id, 'a', ['seq' => 1], 120, $this->t0->modify('+32 seconds')));
    }

    public function testReleaseWithALockedByOnlyWorksForTheHolder(): void
    {
        $id = $this->jobs->enqueue('render_pages', JobExecutor::Browser, now: $this->t0);
        $this->jobs->claim(JobExecutor::Browser, 'a', 30, now: $this->t0);

        $this->jobs->release($id, $this->t0, 'b');
        self::assertSame(JobStatus::Laeuft, $this->jobs->find($id)?->status, 'a stranger must not release the job');

        $this->jobs->release($id, $this->t0, 'a');
        self::assertSame(JobStatus::Offen, $this->jobs->find($id)?->status);
    }
}
