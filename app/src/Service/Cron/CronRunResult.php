<?php

declare(strict_types=1);

namespace App\Service\Cron;

/**
 * Outcome of one cron run, as returned to the caller. Carries task names,
 * counts and exception CLASS names only - the response goes to whoever holds
 * the token, and a message could quote data (CLAUDE.md section 4).
 */
final readonly class CronRunResult
{
    /**
     * @param list<array{name: string, erledigt?: int, fehler?: string}> $aufgaben
     */
    private function __construct(
        public bool $laeuftBereits,
        public array $aufgaben,
        public bool $aufgeraeumt,
        public int $dauerMs,
    ) {
    }

    /**
     * @param list<array{name: string, erledigt?: int, fehler?: string}> $aufgaben
     */
    public static function completed(array $aufgaben, bool $aufgeraeumt, int $dauerMs): self
    {
        return new self(false, $aufgaben, $aufgeraeumt, $dauerMs);
    }

    /** Another run holds the lock: not an error, the next minute tries again. */
    public static function alreadyRunning(): self
    {
        return new self(true, [], false, 0);
    }

    public function hasFailures(): bool
    {
        foreach ($this->aufgaben as $aufgabe) {
            if (isset($aufgabe['fehler'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->laeuftBereits ? 'laeuft_bereits' : ($this->hasFailures() ? 'fehler' : 'ok'),
            'aufgaben' => $this->aufgaben,
            'aufgeraeumt' => $this->aufgeraeumt,
            'dauer_ms' => $this->dauerMs,
        ];
    }
}
