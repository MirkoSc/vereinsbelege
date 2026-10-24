-- Worker grants, worker context and the worker's artifact upload (issue
-- #52/M7b-2, docs/spec/07-worker.md section 3 "Verschlüsselung -
-- Minimalrechte", docs/spec/02-datenmodell.md "Betrieb", E-39).
--
-- The worker never gets the vault private key. What it may read is handed
-- to it document by document:
--
-- `worker_grant`: one row per blob of a document the worker may read. The
--   blob's data key, sealed to the worker's own public key W_pub
--   (crypto_box_seal: 32 + 48 = 80 bytes). Only readable with W_priv, which
--   never leaves the worker. A grant is useless for any other worker and
--   gives nothing without a claimed job on the same document (App\Api\
--   WorkerApiController::blob()). Deleted once no worker job of the
--   document waits or runs any more, when the worker is unpaired, and by
--   the cron after `expires_at` at the latest.
--
-- `worker_context`: the part of a submission the AI may see (free text and
--   cost center hint - never name, e-mail address or IBAN), sealed to W_pub
--   as one small envelope per document. Same life cycle as the grants.
--
-- `worker_upload`: an artifact upload the worker has announced but not yet
--   committed. Its `document_artifact` row exists from the announcement on
--   (the worker needs the row id for the AAD of `data_enc`), with the kind
--   prefixed by `~` so that no reader, which always asks for a kind, sees
--   it before the commit (App\Repository\DocumentArtifactRepository::
--   PENDING). The ciphertext waits in shared/var/tmp (only ciphertext, as
--   CLAUDE.md section 2 requires). The cron drops announcements that were
--   never committed.
--
-- `worker.config_hash`: keyed hash of the configuration last sealed to the
--   worker (AI provider profiles including API keys, 07 section 3
--   "KI-Zugangsdaten"), so a heartbeat can tell whether it must seal anew.
--
-- Only new tables and a new nullable column: the previous release ignores
-- them.
CREATE TABLE worker_grant (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    worker_id BIGINT UNSIGNED NOT NULL,
    document_id BIGINT UNSIGNED NOT NULL,
    blob_id BIGINT UNSIGNED NOT NULL,
    sealed_dek VARBINARY(96) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_worker_grant_blob (worker_id, document_id, blob_id),
    KEY idx_worker_grant_document (document_id),
    KEY idx_worker_grant_expires (expires_at),
    CONSTRAINT fk_worker_grant_worker FOREIGN KEY (worker_id) REFERENCES worker (id) ON DELETE CASCADE,
    CONSTRAINT fk_worker_grant_document FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE,
    CONSTRAINT fk_worker_grant_blob FOREIGN KEY (blob_id) REFERENCES file_blob (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE worker_context (
    worker_id BIGINT UNSIGNED NOT NULL,
    document_id BIGINT UNSIGNED NOT NULL,
    sealed_payload VARBINARY(8192) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (worker_id, document_id),
    KEY idx_worker_context_document (document_id),
    KEY idx_worker_context_expires (expires_at),
    CONSTRAINT fk_worker_context_worker FOREIGN KEY (worker_id) REFERENCES worker (id) ON DELETE CASCADE,
    CONSTRAINT fk_worker_context_document FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE worker_upload (
    artifact_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    worker_id BIGINT UNSIGNED NOT NULL,
    -- No foreign key, like document_artifact.job_id: jobs are cleaned up.
    job_id BIGINT UNSIGNED NOT NULL,
    -- job.attempts of the claim that announced the upload.
    attempt INT UNSIGNED NOT NULL,
    -- `blob` (secretstream, becomes a file_blob) | `daten` (FieldCipher,
    -- becomes document_artifact.data_enc).
    art VARCHAR(8) NOT NULL,
    -- Blob uploads only: version byte | secretstream header.
    header VARBINARY(32) NULL,
    -- Announced ciphertext length and SHA-256, checked at the commit.
    cipher_size BIGINT UNSIGNED NOT NULL,
    cipher_sha256 BINARY(32) NOT NULL,
    -- The App\Service\Upload\UploadService id of the waiting ciphertext.
    upload_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_worker_upload_job (job_id),
    KEY idx_worker_upload_worker (worker_id),
    KEY idx_worker_upload_created (created_at),
    CONSTRAINT fk_worker_upload_artifact FOREIGN KEY (artifact_id) REFERENCES document_artifact (id) ON DELETE CASCADE,
    CONSTRAINT fk_worker_upload_worker FOREIGN KEY (worker_id) REFERENCES worker (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE worker ADD COLUMN config_hash BINARY(32) NULL;
