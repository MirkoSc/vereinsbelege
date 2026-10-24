-- The optional processing worker: pairing and authentication (issue #51/
-- M7b-1, docs/spec/07-worker.md sections 4 and 5, docs/spec/
-- 02-datenmodell.md "Betrieb", E-28).
--
-- `worker`: one row per paired worker (v1: at most one not `gesperrt`).
-- - `public_key` the worker's X25519 key W_pub (crypto_box_seal target for
--   later grants, issue #52), `key_fingerprint` the hex fingerprint the
--   admin compares with the worker console before confirming.
-- - `secret_enc` the 32-byte access key the worker signs every request
--   with (HMAC-SHA256), encrypted under the SERVER key: verifying an HMAC
--   needs the key itself, so a plain hash is not enough (E-28). The server
--   key lives in shared/config.php, never in the database or its backup.
--   NULL once the worker is unpaired - a revoked worker cannot sign again.
-- - `status` `gekoppelt` (paired, fingerprint not confirmed - heartbeat
--   only), `aktiv`, `gesperrt` (App\Domain\WorkerStatus).
-- - `protocol_version`, `app_version`, `capabilities` (JSON list of
--   App\Domain\WorkerFaehigkeit), `last_heartbeat_at`: what the last
--   heartbeat reported.
-- - `config_sealed` / `config_version`: configuration sealed to W_pub,
--   handed out on heartbeat when newer than the worker's (filled by later
--   issues; NULL until then).
--
-- `worker_pairing`: one-time pairing codes (8 characters, 15 minutes). Only
-- an HMAC of the code under a key derived from the server key is stored.
--
-- `worker_nonce`: replay protection for signed requests. A nonce is
-- accepted once per worker; the cron drops rows older than the signature's
-- time window allows to replay.
--
-- Only new tables: the previous release ignores them.
CREATE TABLE worker (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(64) NOT NULL,
    public_key VARBINARY(32) NOT NULL,
    key_fingerprint CHAR(64) NOT NULL,
    secret_enc VARBINARY(128) NULL,
    status VARCHAR(16) NOT NULL,
    protocol_version SMALLINT UNSIGNED NOT NULL,
    app_version VARCHAR(32) NOT NULL DEFAULT '',
    capabilities VARCHAR(255) NOT NULL DEFAULT '[]',
    last_heartbeat_at DATETIME NULL,
    config_sealed MEDIUMBLOB NULL,
    config_version INT UNSIGNED NOT NULL DEFAULT 0,
    paired_at DATETIME NOT NULL,
    confirmed_at DATETIME NULL,
    confirmed_by BIGINT UNSIGNED NULL,
    revoked_at DATETIME NULL,
    revoked_by BIGINT UNSIGNED NULL,
    KEY idx_worker_status (status),
    CONSTRAINT fk_worker_confirmed_by FOREIGN KEY (confirmed_by) REFERENCES `user` (id) ON DELETE SET NULL,
    CONSTRAINT fk_worker_revoked_by FOREIGN KEY (revoked_by) REFERENCES `user` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE worker_pairing (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code_hash BINARY(32) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    worker_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_worker_pairing_code (code_hash),
    CONSTRAINT fk_worker_pairing_worker FOREIGN KEY (worker_id) REFERENCES worker (id) ON DELETE SET NULL,
    CONSTRAINT fk_worker_pairing_created_by FOREIGN KEY (created_by) REFERENCES `user` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE worker_nonce (
    worker_id BIGINT UNSIGNED NOT NULL,
    nonce VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    seen_at DATETIME NOT NULL,
    PRIMARY KEY (worker_id, nonce),
    KEY idx_worker_nonce_seen (seen_at),
    CONSTRAINT fk_worker_nonce_worker FOREIGN KEY (worker_id) REFERENCES worker (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
