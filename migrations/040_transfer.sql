-- Transfers between the club's own accounts (M10-6, issue #70, docs/spec/
-- 04-bank-und-abgleich.md section 5 "Stand M10-6", docs/spec/
-- 02-datenmodell.md "Fachdaten", E-27, E-38).
--
-- Money moved from one own account to another (bank -> bank, bank -> cash
-- box and back) is neither income nor expense. `transfer` pairs the two
-- bookings - booking <-> booking, never an allocation (that links a receipt
-- with a booking, E-27): `out_transaction_id` is the expense on the paying
-- account, `in_transaction_id` the income on the receiving one. Same
-- amount, opposite directions, two different accounts - checked by
-- App\Service\Bank\Umbuchungen under row locks of both bookings.
--
-- No vault data in this row: two ids, how the pair came about
-- (App\Domain\UmbuchungMethode: `auto` - recognised by the counterparty
-- IBAN being an own account, `manuell`), who and when. The amounts stay in
-- the bookings' own `data_enc`.
--
-- Each booking is in at most one transfer (the two UNIQUE keys; a booking's
-- direction decides which column it can be in). Both foreign keys RESTRICT:
-- a paired booking is unpaired first (App\Service\Bank\Umbuchungen::
-- loesen()), so neither its status nor the partner can silently go stale.
-- `created_by` SET NULL as everywhere.
CREATE TABLE transfer (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    out_transaction_id BIGINT UNSIGNED NOT NULL,
    in_transaction_id BIGINT UNSIGNED NOT NULL,
    method VARCHAR(16) NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_transfer_out (out_transaction_id),
    UNIQUE KEY uq_transfer_in (in_transaction_id),
    CONSTRAINT fk_transfer_out FOREIGN KEY (out_transaction_id) REFERENCES bank_transaction (id) ON DELETE RESTRICT,
    CONSTRAINT fk_transfer_in FOREIGN KEY (in_transaction_id) REFERENCES bank_transaction (id) ON DELETE RESTRICT,
    CONSTRAINT fk_transfer_created_by FOREIGN KEY (created_by) REFERENCES `user` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- `bank_transaction.doc_source` (App\Domain\BankTransactionSetBy) gains
-- `umbuchung`: both bookings of a transfer need no receipt
-- (`doc_required` 0, `doc_status` `nicht_noetig`) because they are a
-- transfer - set and taken back only by App\Service\Bank\Umbuchungen. No
-- rule and no "Einordnung" touches such a row's receipt status, and the
-- sums of income and expense leave it out. No schema change: the column is
-- VARCHAR(16).
