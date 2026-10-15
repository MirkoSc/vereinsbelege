-- The archive import (M12-3, issue #77, docs/spec/05-auswertung-und-export.md
-- section 3, docs/spec/02-datenmodell.md "Fachdaten").
--
-- One `archive_import` row per uploaded ZIP. The ZIP itself is not stored:
-- it stays as the upload chunks in `shared/var/tmp/upload/<upload_id>/`
-- (the one plaintext exception of `var/tmp`, CLAUDE.md section 2) until the
-- import is `fertig` or `abgebrochen`, then the chunks go; the cron removes
-- them after 24 h without a step anyway. Every file taken from it becomes a
-- `document` with `source = 'archiv'` and its own encrypted original blob.
--
-- Plaintext structure only:
-- - `upload_id` the 32 hex characters of the upload directory - random.
-- - `status` `vorschau` (uploaded, nothing written), `laeuft` (confirmed,
--   steps running), `fertig`, `abgebrochen` (discarded, or its upload
--   expired; the documents already written stay).
-- - `name_pattern` the file name pattern (App\Service\Archiv\
--   DateinamenMuster) - placeholders like `{lieferant} {datum}`, chosen on
--   the preview, fixed when the import starts.
-- - `total` entries in the ZIP's central directory (folders included);
--   `next_index` how many of them the step chain has handled. A step only
--   moves it from the value it read (App\Repository\ArchiveImportRepository
--   ::advance()), in the same transaction as the document it writes, so two
--   tabs or a repeated request never import an entry twice.
-- - `stats` counts only (App\Domain\ArchiveImportStats).
--
-- Vault data: `report_enc` - the files that were not imported, with the
-- reason (their paths name suppliers) - JSON under the row's DEK
-- `dek_sealed`, AAD "archive_import|<id>|report_enc".
CREATE TABLE archive_import (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    upload_id CHAR(32) NOT NULL,
    status VARCHAR(16) NOT NULL,
    name_pattern VARCHAR(300) NOT NULL,
    total INT UNSIGNED NOT NULL DEFAULT 0,
    next_index INT UNSIGNED NOT NULL DEFAULT 0,
    stats JSON NULL,
    dek_sealed VARBINARY(128) NOT NULL,
    report_enc MEDIUMBLOB NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_archive_import_status (status, created_at),
    CONSTRAINT fk_archive_import_created_by FOREIGN KEY (created_by) REFERENCES `user` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
