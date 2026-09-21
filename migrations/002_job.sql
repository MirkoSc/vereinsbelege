-- Queue of processing steps (docs/spec/06-betrieb.md section 4,
-- docs/spec/02-datenmodell.md "Betrieb").
--
-- Jobs are NOT run by the cron: decrypting needs an unlocked vault, which
-- only a logged-in session has (CLAUDE.md section 4). A job is picked up by
-- the browser of a signed-in user (executor `session`/`browser`, from M4) or
-- by the optional worker (M7b) - `claim` in App\Repository\JobRepository is
-- the single place that hands one out.
--
-- The table is plaintext, like `setting`: nothing in here may carry business
-- data. `state` holds ids and step counters, `last_error` the exception class
-- - never an amount, a supplier, a file name or a database message, because
-- those quote row data.
CREATE TABLE job (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    typ VARCHAR(64) NOT NULL,
    -- Which row the job belongs to, e.g. ('document', 42). Empty/NULL for
    -- jobs that stand on their own.
    ref_type VARCHAR(32) NOT NULL DEFAULT '',
    ref_id BIGINT UNSIGNED NULL,
    -- `session` | `browser` | `worker` and
    -- `offen` | `laeuft` | `fertig` | `fehler` | `uebersprungen` as VARCHAR
    -- and not ENUM: the authority is the PHP enum (App\Domain\JobExecutor,
    -- App\Domain\JobStatus), and a new value must not cost an ALTER TABLE on
    -- a table that may hold a lot of rows.
    executor VARCHAR(16) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'offen',
    step VARCHAR(64) NOT NULL DEFAULT '',
    state JSON NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(255) NULL,
    -- The lock that keeps two open tabs (or a tab and the worker) from doing
    -- the same job twice. It expires on its own so a crashed run does not
    -- park the job forever.
    locked_by VARCHAR(64) NULL,
    locked_until DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    -- Column order follows the WHERE of the claim query.
    KEY idx_claim (status, executor, locked_until, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
