-- Outgoing mail queue (docs/spec/06-betrieb.md section 3,
-- docs/spec/02-datenmodell.md "Betrieb", issue #14).
--
-- Sending never needs the vault: recipient, subject and body are encrypted
-- with the server key (App\Service\Crypto\ServerCrypto), the same level that
-- protects the AI provider keys, so both the request that enqueues a mail and
-- the cron that retries it can read a row without a logged-in session
-- (CLAUDE.md section 4).
--
-- There is no separate lock column. A claim sets status `laeuft` and pushes
-- `next_try_at` a few minutes out as a provisional lock; the real due date is
-- written once the attempt is known to have succeeded or failed. A crashed
-- send is therefore picked up again once that placeholder passes, the same
-- effect `locked_until` has on `job` - without adding a column the spec does
-- not list. Column order in the index follows the WHERE of the claim query.
CREATE TABLE mail_queue (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    -- 255 bytes cover the longest valid address (RFC 5321); +41 bytes
    -- overhead (version 1 + nonce 24 + MAC 16, App\Service\Crypto\ServerCrypto).
    to_enc VARBINARY(296) NOT NULL,
    subject_enc VARBINARY(1024) NOT NULL,
    body_enc BLOB NOT NULL,
    -- `offen` | `laeuft` | `gesendet` | `fehler` as VARCHAR and not ENUM: the
    -- authority is the PHP enum (App\Domain\MailStatus), like `job.status`.
    status VARCHAR(16) NOT NULL DEFAULT 'offen',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    next_try_at DATETIME NOT NULL,
    -- Exception class only, never its message (CLAUDE.md section 4): an SMTP
    -- or database message can quote the recipient or the credentials.
    last_error VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_faellig (status, next_try_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
