-- Festschreibung (M6-4, issue #38, docs/spec/01-sicherheit.md section 7,
-- docs/spec/02-datenmodell.md "Fachdaten"): who locked a checked receipt,
-- and when.
--
-- Both columns are structure (plaintext), like `checked_by`/`checked_at`:
-- they carry no club data. A receipt is locked while `locked_at` is set and
-- its document is in the status `festgeschrieben` - the two are written in
-- one transaction (App\Service\Invoice\Festschreibung) and cleared together
-- when the lock is lifted. The reason for lifting it lives in the audit log.
--
-- Nullable and without a backfill: until now no receipt could be locked.
ALTER TABLE invoice
    ADD COLUMN locked_by BIGINT UNSIGNED NULL AFTER checked_at,
    ADD COLUMN locked_at DATETIME NULL AFTER locked_by,
    ADD CONSTRAINT fk_invoice_locked_by FOREIGN KEY (locked_by) REFERENCES `user` (id) ON DELETE SET NULL;
