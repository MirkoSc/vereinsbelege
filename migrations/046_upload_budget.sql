-- Byte budget of the public upload (security review N-04, issue #242;
-- docs/spec/01-sicherheit.md section 5).
--
-- The rate limit counts uploads, not bytes - a bot within the count limit
-- could still fill the webspace or the database with large files. This
-- table holds the bytes reserved per window: per IP address (an IPv6
-- address collapsed to its /64) per hour, and for all public uploads
-- together per calendar day (App\Service\Submission\UploadBudget).
--
-- Its own table instead of a second use of `rate_limit`: the windows
-- differ in length (an hour vs. a day), and the cron sweeps `rate_limit`
-- by one cutoff for all rows, which would drop a daily counter after two
-- hours. Here every row knows when its window ends.
CREATE TABLE upload_budget (
    -- SHA-256 over "<purpose>:<value>", as in `rate_limit`: the address
    -- never reaches the table in the clear.
    key_hash BINARY(32) NOT NULL PRIMARY KEY,
    -- Both in UTC (App\Repository\UploadBudgetRepository): local time
    -- repeats an hour at the autumn clock change.
    window_start DATETIME NOT NULL,
    window_end DATETIME NOT NULL,
    bytes BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- App\Service\Cron\UploadBudgetCleanupTask deletes rows whose window has
-- ended.
CREATE INDEX idx_upload_budget_end ON upload_budget (window_end);

-- What is announced but not finished yet, per address (IPv6: /64): the
-- part of the budget that bounds `var/tmp`. Announcing alone must not use
-- up the daily budget for everybody - the day only counts stored bytes -
-- so an open upload is a reservation that comes back on finish, abort or
-- once the cron gives up on it.
CREATE TABLE upload_offen (
    key_hash BINARY(32) NOT NULL PRIMARY KEY,
    bytes BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per open public upload: which counter it holds and how much, so
-- the reservation can be given back by upload id alone.
CREATE TABLE upload_reservierung (
    upload_id CHAR(32) NOT NULL PRIMARY KEY,
    key_hash BINARY(32) NOT NULL,
    bytes BIGINT UNSIGNED NOT NULL,
    -- UTC, like the windows above.
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_upload_reservierung_created ON upload_reservierung (created_at);
