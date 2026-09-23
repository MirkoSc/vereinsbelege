<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\RateLimitRepository;

/**
 * Fixed-window counter on top of the `rate_limit` table, taken over from
 * MirkoSc/vereinskalender (CLAUDE.md section 7) with two adjustments:
 *
 *   - the SQL moved into App\Repository\RateLimitRepository (CLAUDE.md
 *     section 8: SQL only in repositories);
 *   - the key is a purpose plus a value rather than an IP address. The login
 *     has to count per IP *and* per account (docs/spec/01-sicherheit.md
 *     section 3), and the public submission will count per IP again (section
 *     5) - one counter with a namespace instead of three tables.
 *
 * Only failures are counted; a success resets the counter (see
 * App\Service\Account\LoginService). Checking is read-only, so asking
 * "is this blocked?" before an attempt never itself uses up an attempt.
 *
 * The value never reaches the database: the row is keyed on
 * SHA-256("purpose:value"). For an IP address that is what
 * docs/spec/01-sicherheit.md section 5 asks for outright; for an account it
 * keeps the blind index out of a table that has no other reason to carry it.
 */
final readonly class RateLimiter
{
    /** Attempts per window before the door closes - per IP. */
    public const int LOGIN_LIMIT_PER_IP = 20;

    /**
     * Per account. Lower than the IP limit (an attacker can rotate
     * addresses, not the account they want), high enough that somebody
     * genuinely fumbling their password is not locked out of their own
     * installation by a third party.
     */
    public const int LOGIN_LIMIT_PER_ACCOUNT = 10;

    /** docs/spec/01-sicherheit.md section 3: brute force, not a burst. */
    public const int LOGIN_WINDOW_SECONDS = 900;

    /**
     * The public submission's window (docs/spec/01-sicherheit.md section 5,
     * issue #25/M4-3): "N Einreichungen/Stunde". The longest window any
     * purpose uses - App\Service\Cron\RateLimitCleanupTask's own RateLimiter
     * is built with this one so its sweep cutoff never falls inside a
     * shorter-lived counter's still-active window (e.g. the login one's).
     */
    public const int SUBMISSION_WINDOW_SECONDS = 3600;

    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private RateLimitRepository $rows,
        private int $windowSeconds = 60,
    ) {
        if ($this->windowSeconds < 1) {
            throw new \InvalidArgumentException('The rate limit window must be at least one second.');
        }
    }

    /**
     * Whether this key has used up its attempts in the current window.
     * An empty value (no IP address, e.g. on the command line) is never
     * blocked - it would be one shared counter for everybody.
     */
    public function isBlocked(string $purpose, string $value, int $limit, ?\DateTimeImmutable $now = null): bool
    {
        if ($value === '') {
            return false;
        }

        $row = $this->rows->find($this->keyHash($purpose, $value));

        return $row !== null
            && $row['windowStart'] === $this->windowStart($now ?? new \DateTimeImmutable())
            && $row['count'] >= $limit;
    }

    /**
     * Records one failed attempt. A window that has run out is replaced
     * rather than extended, so the table keeps one row per key.
     */
    public function registerFailure(string $purpose, string $value, ?\DateTimeImmutable $now = null): void
    {
        if ($value === '') {
            return;
        }

        $key = $this->keyHash($purpose, $value);
        $window = $this->windowStart($now ?? new \DateTimeImmutable());
        $row = $this->rows->find($key);

        if ($row === null || $row['windowStart'] !== $window) {
            $this->rows->startWindow($key, $window);

            return;
        }

        $this->rows->increment($key);
    }

    /**
     * Counts one more use of this key - the same increment as
     * registerFailure(), under a name that fits a counter which is not
     * about failures at all (the public submission's rate limit, issue
     * #25/M4-3: every accepted upload or submission counts, not only a
     * rejected one).
     */
    public function register(string $purpose, string $value, ?\DateTimeImmutable $now = null): void
    {
        $this->registerFailure($purpose, $value, $now);
    }

    /**
     * Clears the counter - what a successful attempt does, so that a user
     * who mistyped their password twice starts the next day from zero.
     */
    public function reset(string $purpose, string $value): void
    {
        if ($value === '') {
            return;
        }

        $this->rows->delete($this->keyHash($purpose, $value));
    }

    /**
     * Drops rows whose window is long gone. Called from the cron
     * (App\Service\Cron\RateLimitCleanupTask): a counter that nobody reads
     * anymore is a hashed IP address kept for nothing, and
     * docs/spec/01-sicherheit.md section 5 says those go away.
     *
     * @return int how many rows the sweep removed
     */
    public function cleanup(?\DateTimeImmutable $now = null): int
    {
        $cutoff = ($now ?? new \DateTimeImmutable())->modify(sprintf('-%d seconds', $this->windowSeconds * 2));

        return $this->rows->deleteOlderThan($cutoff);
    }

    /**
     * The start of the window the given moment falls into - the same value
     * for every request inside it, which is what makes the row a counter
     * instead of a log.
     */
    private function windowStart(\DateTimeImmutable $now): string
    {
        $timestamp = $now->getTimestamp();

        return $now->setTimestamp($timestamp - ($timestamp % $this->windowSeconds))->format(self::FORMAT);
    }

    private function keyHash(string $purpose, string $value): string
    {
        return hash('sha256', $purpose . ':' . $value, true);
    }
}
