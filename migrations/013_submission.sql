-- The public submission (issue #24/M4-2, docs/spec/03-erfassung-und-ki.md
-- section 1, docs/spec/02-datenmodell.md "Fachdaten").
--
-- `submission` is written in two steps, the same shape as `file_blob`
-- (migrations/003_file_blob.sql): App\Repository\SubmissionRepository
-- reserves the row first (form_hash unique - a repeated request with the
-- same form token is caught here, not with a second row), then fills in the
-- reference number and the sealed payload once both are known inside the
-- same transaction. reference_code is therefore nullable, and MySQL/MariaDB
-- allow more than one NULL in a UNIQUE index.
CREATE TABLE submission (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    -- "R-2026-0147" (docs/spec/03-erfassung-und-ki.md section 1).
    reference_code VARCHAR(16) NULL,
    -- SHA-256 of the form token's nonce (App\Service\Submission\
    -- FormTokenData::hash()) - the one thing that ties this row to the visit
    -- that created it, without a session or anything secret.
    form_hash BINARY(32) NOT NULL,
    received_at DATETIME NOT NULL,
    -- VARCHAR and not ENUM: the authority is App\Domain\SubmissionStatus.
    status VARCHAR(16) NOT NULL,
    -- Vault version (1 byte) | box_seal(DEK, VK_pub); NULL until complete().
    dek_sealed VARBINARY(96) NULL,
    -- FieldCipher under this row's DEK, AAD "submission|<id>|payload_enc":
    -- {name, email, erstattung: {art, iban, kontoinhaber}, freitext,
    -- kostenstelle_hinweis}. Sealing needs no secret - that is what lets the
    -- public page encrypt without holding one (CLAUDE.md section 4).
    payload_enc VARBINARY(2048) NULL,
    UNIQUE KEY uq_submission_reference (reference_code),
    UNIQUE KEY uq_submission_form_hash (form_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_submission_received ON submission (received_at);

-- One receipt document (docs/spec/02-datenmodell.md "Fachdaten"). Only the
-- columns the public submission fills are here yet; `pdf_blob_id` (App\
-- Repository\DocumentRepository leaves it NULL for now - generating one PDF
-- per submission is issue #26/M4-4), `content_bi` (needs the vault-derived
-- blind index key, so only a logged-in session can compute it) and the
-- later status transitions arrive with the milestones that use them.
CREATE TABLE document (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    -- `einreichung` | `intern` | `archiv` | `erechnung` - authority
    -- App\Domain\DocumentSource.
    source VARCHAR(16) NOT NULL,
    -- NULL for anything that did not come from the public submission.
    submission_id BIGINT UNSIGNED NULL,
    -- Page order, as uploaded: a plain JSON array of file_blob ids. Ids are
    -- opaque integers, so this stays structural, not business data.
    original_blob_ids JSON NOT NULL,
    pdf_blob_id BIGINT UNSIGNED NULL,
    -- Authority App\Domain\DocumentStatus (docs/spec/02-datenmodell.md
    -- "Statusmodell document.status").
    status VARCHAR(24) NOT NULL,
    -- Authority App\Domain\OcrStatus.
    ocr_status VARCHAR(16) NOT NULL,
    -- Blind index for duplicate detection; only computable with an unlocked
    -- vault, so it starts NULL here.
    content_bi BINARY(32) NULL,
    -- Reserved for future encrypted columns of this row - none exist yet.
    dek_sealed VARBINARY(96) NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_document_submission FOREIGN KEY (submission_id) REFERENCES submission (id) ON DELETE SET NULL,
    CONSTRAINT fk_document_pdf_blob FOREIGN KEY (pdf_blob_id) REFERENCES file_blob (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_document_submission ON document (submission_id);
CREATE INDEX idx_document_status ON document (status);

-- Blobs the public form has uploaded but no submission has claimed yet
-- (App\Service\Submission\SubmissionUploadStore). A row is deleted the
-- moment its submission commits (App\Service\Submission\SubmissionService);
-- what is left after 24 h is a page someone uploaded and then removed again,
-- or an abandoned form, and App\Service\Cron\SubmissionUploadCleanupTask
-- deletes both the row and the blob it points at. ON DELETE CASCADE also
-- covers the cleanup direction: deleting the blob directly removes this row
-- with it.
CREATE TABLE submission_upload (
    blob_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    form_hash BINARY(32) NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_submission_upload_blob FOREIGN KEY (blob_id) REFERENCES file_blob (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_submission_upload_form_hash ON submission_upload (form_hash);
CREATE INDEX idx_submission_upload_created ON submission_upload (created_at);
