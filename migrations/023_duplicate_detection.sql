-- Duplicate detection (M6-6, issue #40, docs/spec/02-datenmodell.md
-- "Statusmodell document.status" - `duplikat_verdacht` - and "Fachdaten").
--
-- The suspicion itself is not stored: App\Repository\
-- DocumentDuplicateRepository derives it on every read from two blind
-- indexes, so it can never go stale - `document.content_bi` (the same
-- original files) and `invoice.supplier_id` + `invoice.number_bi` (the same
-- supplier and invoice number, migrations/019). Only the decision "these two
-- are different receipts" is a row of its own.
--
-- `content_bi` exists since migrations/013 and is written once by the
-- session job `detect_duplicate` (App\Service\Document\Duplikatindex) - it
-- needs the vault-derived blind index key. The index makes the lookup a
-- point query.
CREATE INDEX idx_document_content ON document (content_bi);

-- "Bewusst behalten": a pair of documents someone looked at and kept both
-- of. One row per pair, the smaller id first, so (a, b) and (b, a) are the
-- same decision and the primary key keeps it unique. Ids only, plaintext
-- like `supplier_key` - nothing here is club data. Who decided and why is
-- in the audit log (`beleg.duplikat_behalten`).
CREATE TABLE document_duplicate_kept (
    document_low_id BIGINT UNSIGNED NOT NULL,
    document_high_id BIGINT UNSIGNED NOT NULL,
    kept_by BIGINT UNSIGNED NULL,
    kept_at DATETIME NOT NULL,
    PRIMARY KEY (document_low_id, document_high_id),
    KEY idx_document_duplicate_kept_high (document_high_id),
    CONSTRAINT chk_document_duplicate_kept_order CHECK (document_low_id < document_high_id),
    CONSTRAINT fk_document_duplicate_kept_low FOREIGN KEY (document_low_id) REFERENCES document (id) ON DELETE CASCADE,
    CONSTRAINT fk_document_duplicate_kept_high FOREIGN KEY (document_high_id) REFERENCES document (id) ON DELETE CASCADE,
    CONSTRAINT fk_document_duplicate_kept_by FOREIGN KEY (kept_by) REFERENCES `user` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every document received before this release gets its content index
-- computed by the next signed-in session, like every new one does
-- (App\Service\Submission\SubmissionService, InterneErfassung). Values as in
-- App\Repository\JobRepository::enqueue(): type, executor `session`, status
-- `offen`, empty state. The previous release has no handler for the type
-- and never claims it.
INSERT INTO job (typ, ref_type, ref_id, executor, status, step, state, created_at, updated_at)
SELECT 'detect_duplicate', 'document', id, 'session', 'offen', '', '[]', NOW(), NOW()
FROM document
WHERE content_bi IS NULL;
