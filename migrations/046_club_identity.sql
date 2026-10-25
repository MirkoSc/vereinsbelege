-- The club's own VAT id and tax number (issue #245, decision E-67,
-- docs/spec/03-erfassung-und-ki.md section 7 "Stand #245",
-- docs/spec/02-datenmodell.md "Fachdaten").
--
-- `club_identity`: at most one row (id 1). Vault data: `data_enc`
-- {ust_id, steuernummer} under the row's `dek_sealed`, AAD
-- "club_identity|1|data_enc". Like the club's own IBANs (`bank_account`),
-- they name no supplier: a reverse-charge or B2B invoice prints "Ihre
-- USt-IdNr." and the AI may read it as the seller's. Learning a supplier's
-- VAT id or tax number from a receipt (and step 1 of the resolution) skips
-- them; learning those two waits until the row exists - an admin saved the
-- page, possibly with both empty ("the club has none").
--
-- No blind index on purpose: the values are compared after decrypting in
-- the session, and the key rotation (#90) re-seals `dek_sealed` like any
-- other table - nothing to derive again.
--
-- Only a new table: the previous release ignores it.
CREATE TABLE club_identity (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    dek_sealed VARBINARY(128) NOT NULL,
    data_enc BLOB NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_club_identity_updated_by FOREIGN KEY (updated_by) REFERENCES `user` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
