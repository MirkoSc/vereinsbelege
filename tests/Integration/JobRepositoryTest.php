<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\JobExecutor;
use App\Domain\JobStatus;
use App\Repository\JobRepository;
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
}
