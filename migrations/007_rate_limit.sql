-- Rate limit counters (docs/spec/02-datenmodell.md "Betrieb",
-- docs/spec/01-sicherheit.md section 3/5, issue #16/M3-3).
--
-- Taken over from MirkoSc/vereinskalender (CLAUDE.md section 7) and
-- generalised in one respect: that installation keyed the row on the raw IP
-- address, this one on a hash. Two reasons. The login has to count per IP
-- AND per account (docs/spec/01-sicherheit.md section 3), so the key is no
-- longer always an address; and section 5 wants the IP kept as a hash only,
-- discarded after seven days - a fixed-width digest is both at once.
--
-- The window is a fixed interval: a row carries the start of the interval it
-- counts in, and a request whose interval has moved on overwrites the row
-- instead of adding one (App\Service\RateLimiter). That keeps the table at
-- one row per active key rather than one per attempt, which is what makes it
-- affordable on shared hosting.
CREATE TABLE rate_limit (
    -- SHA-256 over "<purpose>:<value>" (App\Service\RateLimiter::key()).
    -- Namespaced by purpose, so the counter of an IP and the counter of an
    -- account can never collide.
    key_hash BINARY(32) NOT NULL PRIMARY KEY,
    window_start DATETIME NOT NULL,
    count INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The cron drops expired rows (App\Service\Cron\RateLimitCleanupTask); the
-- sweep runs over the window start, so it needs its own index - the primary
-- key says nothing about age.
CREATE INDEX idx_rate_limit_window ON rate_limit (window_start);
