-- The export runs behind the option "nur noch nicht exportierte" (M12-4,
-- issue #179, docs/spec/05-auswertung-und-export.md section 2,
-- docs/spec/02-datenmodell.md "Fachdaten").
--
-- The server streams the ZIP and never learns whether the download arrived
-- in full. So every export is a run: creating it records which documents it
-- holds, and only the user's confirmation ("Vollständig gespeichert") makes
-- them count as exported - for the whole club. An aborted download is a run
-- that stays `offen` and marks nothing.
--
-- Plaintext structure only, no vault data:
-- - `status` `offen` (created, downloadable, marks nothing), `bestaetigt`
--   (its documents count as exported), `zurueckgesetzt` (a confirmed run
--   taken back - marks nothing again). App\Domain\ExportRunStatus.
-- - `filter` the form fields of App\Service\Export\ExportFilter::alsFelder()
--   - dates, status and ids only, never a name.
-- - `belege`, `dateien` counts of the preview the run was created from.
--
-- Only new tables: the previous release ignores them.
CREATE TABLE export_run (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    status VARCHAR(16) NOT NULL,
    filter JSON NOT NULL,
    belege INT UNSIGNED NOT NULL,
    dateien INT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    confirmed_by BIGINT UNSIGNED NULL,
    confirmed_at DATETIME NULL,
    reset_by BIGINT UNSIGNED NULL,
    reset_at DATETIME NULL,
    KEY idx_export_run_created_by (created_by, id),
    CONSTRAINT fk_export_run_created_by FOREIGN KEY (created_by) REFERENCES `user` (id) ON DELETE SET NULL,
    CONSTRAINT fk_export_run_confirmed_by FOREIGN KEY (confirmed_by) REFERENCES `user` (id) ON DELETE SET NULL,
    CONSTRAINT fk_export_run_reset_by FOREIGN KEY (reset_by) REFERENCES `user` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The documents of a run, written with it in one transaction. The filter
-- "nur noch nicht exportierte" asks per document whether a confirmed run
-- holds it - hence the index on document_id.
CREATE TABLE export_run_document (
    export_run_id BIGINT UNSIGNED NOT NULL,
    document_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (export_run_id, document_id),
    KEY idx_export_run_document_document (document_id),
    CONSTRAINT fk_export_run_document_run FOREIGN KEY (export_run_id) REFERENCES export_run (id) ON DELETE CASCADE,
    CONSTRAINT fk_export_run_document_document FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
