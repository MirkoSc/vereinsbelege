-- Discarded matching suggestions (M10-4, issue #68, docs/spec/
-- 04-bank-und-abgleich.md section 5 "Stand M10-4", docs/spec/
-- 02-datenmodell.md "Fachdaten", E-35, E-41).
--
-- Suggestions themselves are never stored - they are scored afresh in the
-- session on every run (E-35). What is stored is the user's "no": this
-- receipt does not belong to this booking. App\Service\Bank\Abgleich leaves
-- such a pair out of the scoring, so it is neither suggested again nor
-- allocated automatically; allocating it by hand stays possible, and
-- "Wieder vorschlagen" deletes the row.
--
-- No vault data: two ids, who and when. Both foreign keys CASCADE - the row
-- is only a hint about a pair and means nothing once either side is gone.
-- `created_by` SET NULL as everywhere.
CREATE TABLE match_rejection (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    invoice_id BIGINT UNSIGNED NOT NULL,
    transaction_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_match_rejection_pair (invoice_id, transaction_id),
    KEY idx_match_rejection_transaction (transaction_id),
    CONSTRAINT fk_match_rejection_invoice FOREIGN KEY (invoice_id) REFERENCES invoice (id) ON DELETE CASCADE,
    CONSTRAINT fk_match_rejection_transaction FOREIGN KEY (transaction_id) REFERENCES bank_transaction (id) ON DELETE CASCADE,
    CONSTRAINT fk_match_rejection_created_by FOREIGN KEY (created_by) REFERENCES `user` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
