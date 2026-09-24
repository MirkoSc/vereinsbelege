-- Processing results of a document, one row per artifact
-- (docs/spec/02-datenmodell.md "Fachdaten", docs/spec/03-erfassung-und-ki.md
-- section 5, issue #30/M4-8). The first producer is `render_pages`
-- (App\Service\Document\PdfRasterung, kind `page_image`); `pdfa`, `text` and
-- `extraction` are the spec's other kinds and follow with their own jobs.
--
-- Every artifact gets its own sealed DEK, not just the ones that store
-- `data_enc` inline: the optional worker (07-worker.md) never holds the
-- document's row DEK, only per-artifact DEKs sealed to it in `worker_grant`,
-- so an artifact it is asked to write needs a DEK of its own regardless of
-- whether the payload ends up as `data_enc` or as a `file_blob`. For
-- `page_image` the actual page is a blob with its own encryption
-- (`file_blob.dek_sealed`); this column is generated the same way for every
-- kind anyway rather than making it conditional on `kind`.
--
-- `job_id` is not a foreign key, the same as `job.ref_id` has none
-- (migrations/002_job.sql): the job row is cleaned up after 7 days
-- (`JobCleanupTask`) while the artifact it produced stays. It only tells two
-- rendering runs of the same document apart, so a superseded run's blobs can
-- be found and dropped.
CREATE TABLE document_artifact (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    document_id BIGINT UNSIGNED NOT NULL,
    -- `page_image` | `pdfa` | `text` | `extraction` as VARCHAR and not ENUM,
    -- same reasoning as `job.executor`/`job.status`: the authority is the
    -- PHP enum App\Domain\ArtifactKind.
    kind VARCHAR(16) NOT NULL,
    -- Page order within a kind, 0-based. For `page_image` this runs across
    -- every source PDF of the document, not per source (App\Service\
    -- Document\PdfRasterung).
    seq INT UNSIGNED NOT NULL,
    -- The rendered page (kind `page_image`) or another blob-shaped result.
    -- NULL for a kind that stores its payload in `data_enc` instead
    -- (`text`, `extraction`). CASCADE: deleting the blob (a superseded
    -- rendering run's page, App\Service\Document\PdfRasterung) removes the
    -- row with it - there is nothing left to keep.
    blob_id BIGINT UNSIGNED NULL,
    dek_sealed VARBINARY(96) NOT NULL,
    -- FieldCipher under this row's own DEK, AAD
    -- "document_artifact|<id>|data_enc". Unused for `page_image` (the
    -- payload is `blob_id`'s own ciphertext).
    data_enc VARBINARY(4096) NULL,
    -- `session` | `browser` | `worker`, same VARCHAR reasoning as `job.executor`.
    producer VARCHAR(16) NOT NULL,
    job_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_document_artifact_document FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE,
    CONSTRAINT fk_document_artifact_blob FOREIGN KEY (blob_id) REFERENCES file_blob (id) ON DELETE CASCADE,
    -- One row per page per rendering run: a repeated upload of the same page
    -- (the browser retries, or a second tab resumes the same job) writes
    -- nothing twice (App\Repository\DocumentArtifactRepository::insert()
    -- checks this before storing, so it never has to rely on the database
    -- rejecting the second write).
    UNIQUE KEY uq_document_artifact_run (document_id, kind, job_id, seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_document_artifact_kind ON document_artifact (document_id, kind);
