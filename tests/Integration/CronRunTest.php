<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\JobExecutor;
use App\Domain\JobStatus;
use App\Repository\CronLockRepository;
use App\Repository\JobRepository;
use App\Repository\SettingRepository;
use App\Service\Cron\CronRunner;
use App\Service\Cron\CronTask;
use App\Service\Cron\JobCleanupTask;
use App\Service\Migration\Migrator;
use App\Support\FileLogger;
use App\Tests\Support\DatabaseTestCase;

/**
 * The cron run against a real server: the overlap lock and the throttled
 * cleanup of issue #99, plus the task that cleans the job table.
 */
final class CronRunTest extends DatabaseTestCase
{
    private \DateTimeImmutable $t0;

    private SettingRepository $settings;

    private CronLockRepository $lock;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->t0 = new \DateTimeImmutable('2026-03-01 12:00:00');
        $this->settings = new SettingRepository($this->pdo());
        $this->lock = new CronLockRepository($this->pdo());
    }

    /**
     * @param list<CronTask> $jedesMal
     * @param list<CronTask> $aufraeumen
     */
    private function runner(array $jedesMal = [], array $aufraeumen = [], ?FileLogger $logger = null): CronRunner
    {
        return new CronRunner($this->lock, $this->settings, $jedesMal, $aufraeumen, $logger);
    }

    private function counter(string $name = 'zaehler'): CountingTask
    {
        return new CountingTask($name);
    }

    public function testRunExecutesEveryTaskAndFreesTheLock(): void
    {
        $task = $this->counter();

        $ergebnis = $this->runner([$task])->run($this->t0);

        self::assertFalse($ergebnis->laeuftBereits);
        self::assertSame(1, $task->calls);
        self::assertSame('ok', $ergebnis->toArray()['status']);
        // Free again: the next call gets the lock at the same instant.
        self::assertNotNull($this->lock->acquire(60, $this->t0));
    }

    /** #99: two overlapping calls - the second one does not work. */
    public function testSecondRunWhileFirstHoldsTheLockDoesNothing(): void
    {
        $task = $this->counter();
        self::assertNotNull($this->lock->acquire(CronRunner::LOCK_TTL_SECONDS, $this->t0));

        $ergebnis = $this->runner([$task])->run($this->t0->modify('+10 seconds'));

        self::assertTrue($ergebnis->laeuftBereits);
        self::assertSame(0, $task->calls);
        self::assertSame('laeuft_bereits', $ergebnis->toArray()['status']);
    }

    /** #99: a run that died must not block the cron for good. */
    public function testExpiredLockIsTakenOver(): void
    {
        $task = $this->counter();
        $this->lock->acquire(CronRunner::LOCK_TTL_SECONDS, $this->t0);

        $ergebnis = $this->runner([$task])->run($this->t0->modify('+' . (CronRunner::LOCK_TTL_SECONDS + 1) . ' seconds'));

        self::assertFalse($ergebnis->laeuftBereits);
        self::assertSame(1, $task->calls);
    }

    public function testLockIsFreedEvenWhenARunCrashes(): void
    {
        $kaputt = new class implements CronTask {
            public function name(): string
            {
                return 'kaputt';
            }

            public function run(\DateTimeImmutable $now): int
            {
                throw new \RuntimeException('boom');
            }
        };

        $this->runner([$kaputt])->run($this->t0);

        self::assertNotNull($this->lock->acquire(60, $this->t0));
    }

    public function testReleaseDoesNotFreeSomeoneElsesLock(): void
    {
        $meins = $this->lock->acquire(60, $this->t0);
        self::assertNotNull($meins);
        // Ours ran out and another run took over.
        $fremd = $this->lock->acquire(60, $this->t0->modify('+61 seconds'));
        self::assertNotNull($fremd);

        $this->lock->release($meins, $this->t0->modify('+62 seconds'));

        self::assertNull($this->lock->acquire(60, $this->t0->modify('+63 seconds')), 'the other run is still locked');
    }

    /** #99: housekeeping once per interval, not once per call. */
    public function testCleanupRunsOncePerInterval(): void
    {
        $aufraeumen = $this->counter('aufraeumen');
        $runner = $this->runner(aufraeumen: [$aufraeumen]);

        self::assertTrue($runner->run($this->t0)->aufgeraeumt);
        self::assertFalse($runner->run($this->t0->modify('+1 minute'))->aufgeraeumt);
        self::assertFalse($runner->run($this->t0->modify('+59 minutes'))->aufgeraeumt);
        self::assertTrue($runner->run($this->t0->modify('+60 minutes'))->aufgeraeumt);
        self::assertSame(2, $aufraeumen->calls);
    }

    public function testCleanupIntervalIsConfigurable(): void
    {
        $this->settings->set(CronRunner::SETTING_INTERVAL, '7200');
        $aufraeumen = $this->counter('aufraeumen');
        $runner = $this->runner(aufraeumen: [$aufraeumen]);

        $runner->run($this->t0);

        self::assertFalse($runner->run($this->t0->modify('+90 minutes'))->aufgeraeumt);
        self::assertTrue($runner->run($this->t0->modify('+120 minutes'))->aufgeraeumt);
    }

    public function testRegularTasksRunOnEveryCall(): void
    {
        $task = $this->counter();
        $runner = $this->runner([$task], [$this->counter('aufraeumen')]);

        $runner->run($this->t0);
        $runner->run($this->t0->modify('+1 minute'));
        $runner->run($this->t0->modify('+2 minutes'));

        self::assertSame(3, $task->calls);
    }

    public function testFailingTaskDoesNotStopTheOthersAndLeaksNoMessage(): void
    {
        $logFile = sys_get_temp_dir() . '/vb-cron-' . bin2hex(random_bytes(4)) . '.log';
        $kaputt = new class implements CronTask {
            public function name(): string
            {
                return 'kaputt';
            }

            public function run(\DateTimeImmutable $now): int
            {
                throw new \RuntimeException('Rechnung Mustermann 1234,56 EUR');
            }
        };
        $danach = $this->counter('danach');

        try {
            $ergebnis = $this->runner([$kaputt, $danach], logger: new FileLogger($logFile))->run($this->t0);

            self::assertSame(1, $danach->calls);
            self::assertSame('fehler', $ergebnis->toArray()['status']);
            self::assertSame(\RuntimeException::class, $ergebnis->aufgaben[0]['fehler'] ?? null);
            self::assertStringNotContainsString('Mustermann', (string) json_encode($ergebnis->toArray()));
            self::assertStringContainsString('kaputt', (string) file_get_contents($logFile));
        } finally {
            @unlink($logFile);
            @unlink($logFile . '.1');
        }
    }

    public function testJobCleanupTaskRemovesOldFinishedJobsOnly(): void
    {
        $jobs = new JobRepository($this->pdo());
        $alt = $jobs->enqueue('x', JobExecutor::Session, now: $this->t0->modify('-10 days'));
        $jobs->finish($alt, JobStatus::Fertig, now: $this->t0->modify('-10 days'));
        $frisch = $jobs->enqueue('x', JobExecutor::Session, now: $this->t0->modify('-1 day'));
        $jobs->finish($frisch, JobStatus::Fertig, now: $this->t0->modify('-1 day'));
        $offen = $jobs->enqueue('x', JobExecutor::Session, now: $this->t0->modify('-30 days'));

        $ergebnis = $this->runner(aufraeumen: [new JobCleanupTask($jobs)])->run($this->t0);

        self::assertSame([['name' => 'jobs_aufraeumen', 'erledigt' => 1]], $ergebnis->aufgaben);
        self::assertNull($jobs->find($alt));
        self::assertNotNull($jobs->find($frisch));
        self::assertNotNull($jobs->find($offen));
    }
}

/** Test double: counts its calls. */
final class CountingTask implements CronTask
{
    public int $calls = 0;

    public function __construct(private readonly string $name)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function run(\DateTimeImmutable $now): int
    {
        return ++$this->calls;
    }
}
