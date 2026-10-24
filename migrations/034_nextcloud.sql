-- The Nextcloud filing of the export (issue #95,
-- docs/spec/05-auswertung-und-export.md section 2 "Nextcloud-Ablage",
-- docs/spec/02-datenmodell.md "Fachdaten").
--
-- `nextcloud_target`: the club's WebDAV folder, at most one row (id 1).
-- Vault data: `data_enc` {url, benutzer, passwort} under the row's
-- `dek_sealed`, AAD "nextcloud_target|1|data_enc" - the app password opens
-- the club's cloud, so only an unlocked session can use it (CLAUDE.md
-- section 4). Writing needs no secret, reading needs the vault.
--
-- `export_run_upload`: the step chain that files one export run into the
-- Nextcloud, one row per run. Plaintext structure only - a cursor and
-- counters, never a path or a name:
-- - `status` `laeuft` / `fertig` (App\Domain\NextcloudAblageStatus).
-- - `next_index` the next file of the run in upload order (index.csv last),
--   `total` the files of the run when the chain started, `plan_hash` an
--   HMAC under the vault's blind index key over paths and blob ids in
--   upload order (never a plain hash of names): a step that finds another
--   plan - a receipt deleted, rejected, re-dated, its PDF finished - starts
--   over from the first file, which then counts as already there.
-- - `hochgeladen`, `vorhanden` (already in the cloud with the same content,
--   skipped), `umbenannt` (a different file had the name; filed with a
--   " (2)" suffix instead of overwriting it).
--
-- Only new tables: the previous release ignores them.
CREATE TABLE nextcloud_target (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    dek_sealed VARBINARY(128) NOT NULL,
    data_enc BLOB NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_nextcloud_target_updated_by FOREIGN KEY (updated_by) REFERENCES `user` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE export_run_upload (
    export_run_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    status VARCHAR(16) NOT NULL,
    next_index INT UNSIGNED NOT NULL DEFAULT 0,
    total INT UNSIGNED NOT NULL,
    plan_hash CHAR(64) NOT NULL,
    hochgeladen INT UNSIGNED NOT NULL DEFAULT 0,
    vorhanden INT UNSIGNED NOT NULL DEFAULT 0,
    umbenannt INT UNSIGNED NOT NULL DEFAULT 0,
    started_by BIGINT UNSIGNED NULL,
    started_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    CONSTRAINT fk_export_run_upload_run FOREIGN KEY (export_run_id) REFERENCES export_run (id) ON DELETE CASCADE,
    CONSTRAINT fk_export_run_upload_started_by FOREIGN KEY (started_by) REFERENCES `user` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
