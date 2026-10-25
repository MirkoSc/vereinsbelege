-- Payment runs: SEPA credit transfer files (pain.001) for reimbursements
-- (issue #85, docs/spec/04-bank-und-abgleich.md section 5 "Stand SEPA-
-- Zahllauf", docs/spec/02-datenmodell.md "Fachdaten", E-42).
--
-- A run is one file for the bank: the club's paying account and one
-- transfer per payee (name + IBAN from the sealed submission). Everything
-- the file needs - payer name, IBAN, BIC, payees, amounts, remittance
-- texts, message ids - is vault data in `data_enc` under the row's own DEK
-- (`dek_sealed`), AAD "payment_run|<id>|data_enc". The file itself is
-- never stored: App\Service\Bank\Zahllaeufe builds it from this row on
-- every download, in the session.
--
-- Plaintext structure only: the paying account, the requested execution
-- date, the status (App\Domain\ZahllaufStatus: `angewiesen` - handed to
-- the bank, `verworfen` - taken back, e.g. the bank refused the file) and
-- who did what when. Whether a run is settled is not stored: it is
-- derived from its receipts' `payment_status`, which the matching
-- (App\Service\Bank\Zuordnung) sets once the bookings are found.
CREATE TABLE payment_run (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    bank_account_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(16) NOT NULL,
    execution_date DATE NOT NULL,
    dek_sealed VARBINARY(128) NOT NULL,
    data_enc BLOB NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    discarded_by BIGINT UNSIGNED NULL,
    discarded_at DATETIME NULL,
    KEY idx_payment_run_status (status, created_at),
    CONSTRAINT fk_payment_run_account FOREIGN KEY (bank_account_id) REFERENCES bank_account (id) ON DELETE RESTRICT,
    CONSTRAINT fk_payment_run_created_by FOREIGN KEY (created_by) REFERENCES `user` (id) ON DELETE SET NULL,
    CONSTRAINT fk_payment_run_discarded_by FOREIGN KEY (discarded_by) REFERENCES `user` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The receipts a run reimburses - ids only, the amounts are in the run's
-- `data_enc`. `active_invoice_id` repeats `invoice_id` while the run is
-- `angewiesen` and is NULL once it is discarded: its UNIQUE key is what
-- keeps one receipt from being ordered twice, even with two people
-- creating runs at the same moment. Discarding a run frees its receipts.
CREATE TABLE payment_run_item (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    payment_run_id BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    active_invoice_id BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_payment_run_item (payment_run_id, invoice_id),
    UNIQUE KEY uq_payment_run_item_active (active_invoice_id),
    KEY idx_payment_run_item_invoice (invoice_id),
    CONSTRAINT fk_payment_run_item_run FOREIGN KEY (payment_run_id) REFERENCES payment_run (id) ON DELETE RESTRICT,
    CONSTRAINT fk_payment_run_item_invoice FOREIGN KEY (invoice_id) REFERENCES invoice (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
