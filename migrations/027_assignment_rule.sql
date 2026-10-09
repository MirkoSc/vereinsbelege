-- Rules "kein Beleg nötig" / category for bookings (M9-6, issue #64,
-- docs/spec/04-bank-und-abgleich.md section 5 "Stand M9-6",
-- docs/spec/02-datenmodell.md "Fachdaten").
--
-- One `assignment_rule` row per rule. What it looks for names counterparties
-- and words of the club's bookings, so the vault covers it (CLAUDE.md
-- section 5): `data_enc` {label, stichwort, gegenseite} under the row's DEK,
-- AAD "assignment_rule|<id>|data_enc", written in two steps inside one
-- transaction like `bank_transaction`. A rule is matched in PHP after
-- decrypting (App\Service\Bank\Regelabgleich) - a blind index could only
-- find whole values, not "Zinsen" in "Abschluss Zinsen 3/26".
--
-- Plaintext structure: `direction` (App\Domain\BankTransactionDirection,
-- NULL = both), what the rule does - `no_receipt` and/or `category_id` - and
-- `active`. `category_id` is ON DELETE RESTRICT and counted in
-- App\Repository\CategoryRepository::usageCount(). The receipt side
-- (supplier, invoices) comes with M7-7 and adds its own columns.
CREATE TABLE assignment_rule (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    dek_sealed VARBINARY(128) NOT NULL,
    data_enc BLOB NOT NULL,
    direction VARCHAR(16) NULL,
    no_receipt TINYINT(1) NOT NULL,
    category_id BIGINT UNSIGNED NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_assignment_rule_active (active),
    CONSTRAINT fk_assignment_rule_category FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE RESTRICT,
    CONSTRAINT fk_assignment_rule_created_by FOREIGN KEY (created_by) REFERENCES `user` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Where the receipt status and the category of a booking come from, so the
-- effect of a rule can be shown and taken back:
-- - `doc_source` App\Domain\BankTransactionSetBy: `standard` (the default of
--   the direction), `regel`, `manuell`.
-- - `category_source` the same without `standard`; NULL while the booking
--   has no category.
-- - `rule_id` the rule that set one of them. RESTRICT: deleting a rule first
--   takes its effect back (App\Service\Bank\Buchungsregeln::loeschen()).
-- The defaults keep the previous release writing valid rows.
ALTER TABLE bank_transaction
    ADD COLUMN rule_id BIGINT UNSIGNED NULL AFTER category_id,
    ADD COLUMN doc_source VARCHAR(16) NOT NULL DEFAULT 'standard' AFTER doc_status,
    ADD COLUMN category_source VARCHAR(16) NULL AFTER rule_id,
    ADD KEY idx_bank_transaction_rule (rule_id),
    ADD CONSTRAINT fk_bank_transaction_rule FOREIGN KEY (rule_id) REFERENCES assignment_rule (id) ON DELETE RESTRICT;

-- Every category so far was chosen by hand: manual bookings require one,
-- the import writes none.
UPDATE bank_transaction SET category_source = 'manuell' WHERE category_id IS NOT NULL;
