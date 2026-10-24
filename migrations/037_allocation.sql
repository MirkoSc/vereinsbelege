-- Matching receipt <-> booking (M10-1, issue #65, docs/spec/
-- 04-bank-und-abgleich.md section 5 "Modell", docs/spec/02-datenmodell.md
-- "Fachdaten", E-27).
--
-- `allocation` links one receipt (`invoice`) with one booking
-- (`bank_transaction`) and says how much of the receipt that booking
-- settles: n:m with partial amounts, so one transfer may pay several
-- invoices (Sammelzahlung), several transfers one invoice (Raten), and a
-- cash discount is closed as a difference with a reason on the invoice.
-- The amount is club data: `amount_enc` (integer cents, FieldCipher under
-- the row's DEK in `dek_sealed`, AAD "allocation|<id>|amount_enc"), written
-- in two steps inside one transaction like every other vault row.
-- Plaintext structure: the two ids, how the link came about
-- (App\Domain\ZuordnungMethode: `auto`, `vorschlag_bestaetigt`, `manuell`),
-- the score and the signals that fired (`rule_trace`, M10-2 - keys and
-- points only, never a value of the booking or the receipt), who and when.
--
-- One row per pair (`uq_allocation_pair`): a second amount between the same
-- receipt and booking would be a correction of the first, not a new link.
-- Both foreign keys RESTRICT: a receipt or booking with allocations is
-- released first (App\Service\Bank\Zuordnung::loesen()), so no status can
-- silently go stale. `created_by` SET NULL as everywhere.
CREATE TABLE allocation (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    invoice_id BIGINT UNSIGNED NOT NULL,
    transaction_id BIGINT UNSIGNED NOT NULL,
    dek_sealed VARBINARY(128) NOT NULL,
    amount_enc BLOB NOT NULL,
    method VARCHAR(24) NOT NULL,
    score SMALLINT NULL,
    rule_trace JSON NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_allocation_pair (invoice_id, transaction_id),
    KEY idx_allocation_transaction (transaction_id),
    CONSTRAINT fk_allocation_invoice FOREIGN KEY (invoice_id) REFERENCES invoice (id) ON DELETE RESTRICT,
    CONSTRAINT fk_allocation_transaction FOREIGN KEY (transaction_id) REFERENCES bank_transaction (id) ON DELETE RESTRICT,
    CONSTRAINT fk_allocation_created_by FOREIGN KEY (created_by) REFERENCES `user` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The receipt's side of the matching - three plaintext structure columns,
-- none of them carries an amount:
-- - `payment_kind` (App\Domain\Zahlungsweg): how the receipt is settled -
--   `konto` (a booking on one of the club's accounts, bank or cash box, is
--   expected - the default), `erstattung` (the submitter paid and the club
--   reimburses by transfer), `bar` (paid in cash without a cash-book entry:
--   the receipt is the proof of payment, nothing to match) or `keine` (no
--   payment expected: a credit note netted against an invoice, a document
--   for information). Chosen in the review page; the archive import's
--   `Kasse` folder and a submission's "Erstattung" prefill it.
-- - `payment_status` (App\Domain\PaymentStatus): DERIVED from the
--   allocations' sum against the gross amount, and from `payment_kind`, by
--   App\Service\Bank\Zuordnung alone - never set by hand, never shown as an
--   editable field. It is stored so SQL can filter and sort the lists of
--   M10-5 (unpaid, due, open reimbursements) without decrypting every
--   receipt; decrypting is what the sum needs. `offen`, `teilbezahlt`,
--   `bezahlt`, `erstattung_offen`, `erstattet`, `kein_zahlungsbezug`.
-- - `difference_reason` (App\Domain\Differenzgrund): why the receipt counts
--   as settled although the allocations fall short of the gross amount -
--   `skonto`, `gutschrift` (netted with a credit note), `sonstiges`. NULL
--   while nothing is closed that way. The difference itself is not stored:
--   it is gross minus allocated, computed after decrypting.
-- Every receipt so far is `konto` and `offen`: nothing was matched before
-- this release. Not touched by `locked_at`: a locked receipt may still be
-- paid - the lock covers what the receipt says, not what happened to it.
ALTER TABLE invoice
    ADD COLUMN payment_kind VARCHAR(16) NOT NULL DEFAULT 'konto' AFTER direction,
    ADD COLUMN payment_status VARCHAR(24) NOT NULL DEFAULT 'offen' AFTER payment_kind,
    ADD COLUMN difference_reason VARCHAR(16) NULL AFTER payment_status,
    ADD KEY idx_invoice_payment (payment_status, due_date);

-- `bank_transaction.doc_status` (App\Domain\BankTransactionDocStatus) gains
-- `teilweise` - the allocations do not yet reach the booking's amount (a
-- collective transfer with one invoice still missing). No schema change:
-- the column is VARCHAR(16). `zugeordnet` and `teilweise` are derived from
-- the allocations by App\Service\Bank\Zuordnung; `fehlt`/`nicht_noetig`
-- remain what import, rules and the person decide (M9-6).
