-- Accounts and cash counts (M9-1, issue #59, docs/spec/04-bank-und-abgleich.md
-- section 1, docs/spec/02-datenmodell.md "Fachdaten" and "Konten").
--
-- One model for both kinds of money the club keeps: `kind` (App\Domain\
-- BankAccountKind) is `bank` for a current or savings account and `kasse`
-- for a cash box. Plaintext, so later lists (import, bookings) filter in SQL;
-- it is fixed once the account exists.
--
-- Club data, so the vault covers it (CLAUDE.md section 5), each column under
-- the row's DEK in `dek_sealed`:
-- - `data_enc` {name, iban, bic, bank} - AAD "bank_account|<id>|data_enc";
--   a cash box has only a name.
-- - `opening_balance_enc` {amount (integer cents), currency} - AAD
--   "bank_account|<id>|opening_balance_enc". The balance as of
--   `opening_date`; may be negative for a bank account, not for a cash box.
-- `iban_bi` is the blind index of the normalised IBAN, purpose
-- "bank_account.iban" (docs/spec/01-sicherheit.md section 2). NULL without
-- an IBAN (cash box, savings book without one). UNIQUE: one IBAN, one
-- account - the statement import (M9-2/M9-4) finds the account by it.
-- `opening_date` and `active` are structure. An account in use is
-- deactivated rather than deleted; App\Repository\BankAccountRepository::
-- usageCount() decides what "in use" means.
--
-- The row is written in two steps inside one transaction: the AAD of the
-- ciphertexts names the id, which only exists after the INSERT
-- (App\Service\Bank\BankAccountService).
CREATE TABLE bank_account (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kind VARCHAR(16) NOT NULL,
    dek_sealed VARBINARY(128) NOT NULL,
    data_enc BLOB NOT NULL,
    iban_bi BINARY(32) NULL,
    opening_balance_enc BLOB NOT NULL,
    opening_date DATE NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_bank_account_iban (iban_bi),
    KEY idx_bank_account_kind (kind, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A cash count ("Kassensturz") of a cash box: what the box should hold on
-- `counted_on` and what was counted. Append-only - a miscount is followed by
-- a new count, never edited away.
--
-- `data_enc` {expected, counted (integer cents), currency, note} under the
-- row's own DEK, AAD "cash_count|<id>|data_enc". The difference is derived
-- (counted - expected), not stored. `expected` is fixed at the time of the
-- count: bookings entered later do not rewrite an old count.
-- `counted_on` is plaintext structure - the period scope of external roles
-- filters on it (App\Domain\Zugriffsbereich).
-- `account_id` ON DELETE RESTRICT: a cash box with counts is not deleted
-- (counted in BankAccountRepository::usageCount()).
CREATE TABLE cash_count (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    counted_on DATE NOT NULL,
    dek_sealed VARBINARY(128) NOT NULL,
    data_enc BLOB NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    KEY idx_cash_count_account (account_id, counted_on),
    CONSTRAINT fk_cash_count_account FOREIGN KEY (account_id) REFERENCES bank_account (id) ON DELETE RESTRICT,
    CONSTRAINT fk_cash_count_created_by FOREIGN KEY (created_by) REFERENCES `user` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
