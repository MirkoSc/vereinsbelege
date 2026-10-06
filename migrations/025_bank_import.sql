-- Statement import and bank transactions (M9-4, issue #62, docs/spec/
-- 04-bank-und-abgleich.md section 4, docs/spec/02-datenmodell.md
-- "Fachdaten" and "Konten").
--
-- One `bank_import` row per uploaded statement file. Plaintext only - ids,
-- dates, counters, states; nothing here is club data:
-- - `account_id` NULL while the preview still asks "Welches Konto?" (the
--   file named no account the application knows). ON DELETE RESTRICT and
--   counted in App\Repository\BankAccountRepository::usageCount().
-- - `format` `mt940` or `csv:<csv_profile.id>` (App\Service\Bank\
--   ImportFormat) - how to read the file again for every step.
-- - `file_blob_id` the original file, encrypted (App\Service\Storage\
--   BlobService). RESTRICT: the original stays as long as the import does.
-- - `source_bi` blind index of the account line `:25:` of an MT940 file,
--   purpose "bank_import.source". An account picked for such a file is
--   found again through the last finished import with the same line
--   ("Merken", 04 section 2). NULL for CSV - the file names no account.
-- - `status` App\Domain\BankImportStatus: `vorschau` (uploaded, nothing
--   written), `laeuft` (confirmed, steps running), `fertig`.
-- - `next_index` how many postings of the file the step chain has handled;
--   a step only moves it from the value it read (App\Repository\
--   BankImportRepository::advance()), so two tabs cannot write the same
--   postings.
-- - `stats` {gesamt, neu, duplikat, fehler, vorgemerkt, vor_stichtag} -
--   counts only. `balance_check` App\Domain\BalanceCheck. Differences in
--   cents are never stored here: amounts are vault data, the preview works
--   them out from the file again.
-- - `period_from`/`period_to` the first and last booking date of the file -
--   structure, like `bank_transaction.booking_date`.
CREATE TABLE bank_import (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_id BIGINT UNSIGNED NULL,
    format VARCHAR(32) NOT NULL,
    file_blob_id BIGINT UNSIGNED NOT NULL,
    source_bi BINARY(32) NULL,
    status VARCHAR(16) NOT NULL,
    next_index INT UNSIGNED NOT NULL DEFAULT 0,
    stats JSON NULL,
    balance_check VARCHAR(16) NULL,
    period_from DATE NULL,
    period_to DATE NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    imported_by BIGINT UNSIGNED NULL,
    imported_at DATETIME NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_bank_import_account (account_id, status),
    KEY idx_bank_import_source (source_bi),
    KEY idx_bank_import_status (status, created_at),
    CONSTRAINT fk_bank_import_account FOREIGN KEY (account_id) REFERENCES bank_account (id) ON DELETE RESTRICT,
    CONSTRAINT fk_bank_import_blob FOREIGN KEY (file_blob_id) REFERENCES file_blob (id) ON DELETE RESTRICT,
    CONSTRAINT fk_bank_import_created_by FOREIGN KEY (created_by) REFERENCES `user` (id) ON DELETE SET NULL,
    CONSTRAINT fk_bank_import_imported_by FOREIGN KEY (imported_by) REFERENCES `user` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One booking on an account. Club data, so the vault covers it (CLAUDE.md
-- section 5): `data_enc` {amount (integer cents, signed), currency,
-- counterparty_name, counterparty_iban, purpose, eref, mref, cred, gvc,
-- booking_text} under the row's DEK, AAD "bank_transaction|<id>|data_enc",
-- written in two steps inside one transaction like `bank_account`.
--
-- Plaintext structure, so SQL can filter and sort: `booking_date`,
-- `value_date`, `direction` (App\Domain\BankTransactionDirection, from the
-- sign), `category_id`, `doc_required`/`doc_status` (App\Domain\
-- BankTransactionDocStatus; an expense needs a receipt, income does not -
-- E-17), `source` (`import`, later `manuell` - M9-5).
--
-- `dedup_bi` blind index of account, booking date, amount, normalised
-- purpose, counterparty IBAN and the running number among identical keys
-- of the file (App\Service\Bank\Dedupschluessel, purpose
-- "bank_transaction.dedup"). UNIQUE per account: the same export imported
-- twice adds nothing. NULL for manual bookings (M9-5).
-- `counterparty_bi` blind index of the counterparty IBAN, purpose
-- "bank_transaction.counterparty" - for rules (M9-6) and matching (M10).
--
-- `account_id`, `import_id` and `category_id` are ON DELETE RESTRICT and
-- counted where the parent decides about deleting
-- (BankAccountRepository::usageCount(), CategoryRepository::usageCount()).
CREATE TABLE bank_transaction (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    import_id BIGINT UNSIGNED NULL,
    booking_date DATE NOT NULL,
    value_date DATE NULL,
    direction VARCHAR(16) NOT NULL,
    dek_sealed VARBINARY(128) NOT NULL,
    data_enc BLOB NOT NULL,
    dedup_bi BINARY(32) NULL,
    counterparty_bi BINARY(32) NULL,
    category_id BIGINT UNSIGNED NULL,
    doc_required TINYINT(1) NOT NULL,
    doc_status VARCHAR(16) NOT NULL,
    source VARCHAR(16) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_bank_transaction_dedup (account_id, dedup_bi),
    KEY idx_bank_transaction_account (account_id, booking_date),
    KEY idx_bank_transaction_counterparty (counterparty_bi),
    CONSTRAINT fk_bank_transaction_account FOREIGN KEY (account_id) REFERENCES bank_account (id) ON DELETE RESTRICT,
    CONSTRAINT fk_bank_transaction_import FOREIGN KEY (import_id) REFERENCES bank_import (id) ON DELETE RESTRICT,
    CONSTRAINT fk_bank_transaction_category FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
