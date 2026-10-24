-- Recurring invoices (issue #57/M8-1, docs/spec/03-erfassung-und-ki.md
-- section 9, docs/spec/02-datenmodell.md "Fachdaten", E-26): a series of
-- receipts of one supplier that come at a regular rhythm (rent, insurance,
-- fees), so a missing one stands out (M8-2).
--
-- `recurring_series` is club data, so the vault covers it (CLAUDE.md
-- section 5): label, expected amount (integer cents), currency and contract
-- reference live in `data_enc` (JSON, FieldCipher under the row's DEK in
-- `dek_sealed`, AAD "recurring_series|<id>|data_enc"). Plaintext are only
-- the structural fields SQL filters and sorts on: supplier, direction,
-- rhythm, status, the next expected date and its tolerance.
--
-- `period` is the spec's `interval` (a reserved word in MySQL), values of
-- App\Domain\SeriesPeriod. `status` (App\Domain\SeriesStatus) replaces the
-- spec's `confirmed`/`active`: `vorgeschlagen` (found by the
-- `detect_recurring` job, waiting for a person), `bestaetigt`, `verworfen`.
--
-- Which receipts belong to a series is a table of its own, not
-- `invoice.recurring_series_id`: a locked receipt is immutable
-- (docs/spec/01-sicherheit.md section 7), and most receipts a series is
-- found in are locked already. An invoice belongs to one series at most
-- (primary key). The links of a dismissed series stay - its receipts are
-- not offered again.
--
-- No dependency on any other migration of this release.
CREATE TABLE recurring_series (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    supplier_id BIGINT UNSIGNED NOT NULL,
    -- Authority App\Domain\InvoiceDirection.
    direction VARCHAR(16) NOT NULL,
    -- Authority App\Domain\SeriesPeriod.
    period VARCHAR(16) NOT NULL,
    -- Authority App\Domain\SeriesStatus.
    status VARCHAR(16) NOT NULL,
    next_expected DATE NULL,
    tolerance_days SMALLINT UNSIGNED NOT NULL,
    dek_sealed VARBINARY(128) NOT NULL,
    data_enc BLOB NOT NULL,
    decided_by BIGINT UNSIGNED NULL,
    decided_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_recurring_series_supplier (supplier_id, direction, status),
    KEY idx_recurring_series_status (status, next_expected),
    CONSTRAINT fk_recurring_series_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recurring_series_decided_by FOREIGN KEY (decided_by) REFERENCES `user` (id) ON DELETE SET NULL,
    CONSTRAINT fk_recurring_series_updated_by FOREIGN KEY (updated_by) REFERENCES `user` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE recurring_series_invoice (
    invoice_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    series_id BIGINT UNSIGNED NOT NULL,
    KEY idx_recurring_series_invoice_series (series_id),
    CONSTRAINT fk_recurring_series_invoice_invoice FOREIGN KEY (invoice_id) REFERENCES invoice (id) ON DELETE CASCADE,
    CONSTRAINT fk_recurring_series_invoice_series FOREIGN KEY (series_id) REFERENCES recurring_series (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Receipts checked before this release: one `detect_recurring` job per
-- supplier and direction, for its newest checked receipt - the job looks at
-- all receipts of the supplier anyway. Values as in App\Repository\
-- JobRepository::enqueue(). The previous release has no handler for the
-- type and never claims it.
INSERT INTO job (typ, ref_type, ref_id, executor, status, step, state, created_at, updated_at)
SELECT 'detect_recurring', 'document', i.document_id, 'session', 'offen', '', '[]', NOW(), NOW()
FROM invoice i
WHERE i.checked_at IS NOT NULL
  AND i.supplier_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM invoice n
      WHERE n.supplier_id = i.supplier_id AND n.direction = i.direction AND n.checked_at IS NOT NULL
        AND (n.invoice_date > i.invoice_date OR (n.invoice_date = i.invoice_date AND n.id > i.id))
  );
