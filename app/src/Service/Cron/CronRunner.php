<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Repository\CronLockRepository;
use App\Repository\SettingRepository;
use App\Support\FileLogger;

/**
 * One run of the cron endpoint (docs/spec/06-betrieb.md section 4, issue #99).
 *
 * The host may call every minute, which raises two problems this class owns:
 *   - runs can overlap -> a lock with a time to live; a run that finds it held
 *     ends at once and without an error;
 *   - housekeeping would run 1440 times a day -> the cleanup tasks only run
 *     when the configured interval has passed since the last time.
 *
 * Tasks in `jedesMal` (mail queue from M3-1) run on every call. A failing task
 * is logged and reported by class name; it does not stop the others.
 */
final class CronRunner
{
    /** Longer than any run should take, short enough not to park the cron for long. */
    public const int LOCK_TTL_SECONDS = 300;

    public const int DEFAULT_CLEANUP_INTERVAL_SECONDS = 3600;

    public const string SETTING_INTERVAL = 'cron_aufraeum_intervall_s';

    public const string SETTING_LAST_CLEANUP = 'cron_letztes_aufraeumen';

    private const string FORMAT = 'Y-m-d H:i:s';

    /**
     * @param list<CronTask> $jedesMal runs on every call
     * @param list<CronTask> $aufraeumen runs at most once per interval
     */
    public function __construct(
        private readonly CronLockRepository $lock,
        private readonly SettingRepository $settings,
        private readonly array $jedesMal = [],
        private readonly array $aufraeumen = [],
        private readonly ?FileLogger $logger = null,
    ) {
    }

    public function run(?\DateTimeImmutable $now = null): CronRunResult
    {
        $now ??= new \DateTimeImmutable();
        $start = hrtime(true);

        $token = $this->lock->acquire(self::LOCK_TTL_SECONDS, $now);
        if ($token === null) {
            return CronRunResult::alreadyRunning();
        }

        try {
            $aufgaben = $this->runAll($this->jedesMal, $now);

            $aufgeraeumt = $this->cleanupDue($now);
            if ($aufgeraeumt) {
                // Stamped BEFORE the tasks: a task that crashes the run (or a
                // host that cuts it off) must not be retried every minute.
                $this->settings->set(self::SETTING_LAST_CLEANUP, $now->format(self::FORMAT));
                $aufgaben = [...$aufgaben, ...$this->runAll($this->aufraeumen, $now)];
            }
        } finally {
            $this->lock->release($token);
        }

        return CronRunResult::completed($aufgaben, $aufgeraeumt, intdiv(hrtime(true) - $start, 1_000_000));
    }

    private function cleanupDue(\DateTimeImmutable $now): bool
    {
        if ($this->aufraeumen === []) {
            return false;
        }

        $letztes = $this->settings->get(self::SETTING_LAST_CLEANUP);
        if ($letztes === '') {
            return true;
        }

        $interval = (int) $this->settings->get(
            self::SETTING_INTERVAL,
            (string) self::DEFAULT_CLEANUP_INTERVAL_SECONDS,
        );

        try {
            $zuletzt = new \DateTimeImmutable($letztes);
        } catch (\Exception) {
            return true;
        }

        return $now->getTimestamp() - $zuletzt->getTimestamp() >= max(60, $interval);
    }

    /**
     * @param list<CronTask> $tasks
     * @return list<array{name: string, erledigt?: int, fehler?: string}>
     */
    private function runAll(array $tasks, \DateTimeImmutable $now): array
    {
        $ergebnis = [];
        foreach ($tasks as $task) {
            try {
                $ergebnis[] = ['name' => $task->name(), 'erledigt' => $task->run($now)];
            } catch (\Throwable $e) {
                // Class and message only, like Http\Kernel: never the trace.
                $this->logger?->append(sprintf('Cron task %s failed: %s: %s', $task->name(), $e::class, $e->getMessage()));
                // The response carries the class alone - a message can quote data.
                $ergebnis[] = ['name' => $task->name(), 'fehler' => $e::class];
            }
        }

        return $ergebnis;
    }
}
