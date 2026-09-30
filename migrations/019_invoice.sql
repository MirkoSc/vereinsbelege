-- The captured receipt (M6-3, issue #37, docs/spec/02-datenmodell.md
-- "Fachdaten", docs/spec/03-erfassung-und-ki.md section 6 "Prüfansicht"):
-- what a person enters in the review page for one document.
--
-- Club data, so the vault covers it (CLAUDE.md section 5): invoice number,
-- amounts (integer cents), taxes, currency, purpose and notes live in
-- `data_enc` (JSON, FieldCipher under the row's DEK in `dek_sealed`, AAD
-- "invoice|<id>|data_enc"). Plaintext are only the structural fields SQL
-- filters and sorts on: dates, direction, type, the ids of supplier,
-- category and cost center.
--
-- One row per document (`uq_invoice_document`). `supplier_id`,
-- `category_id` and `cost_center_id` point at their master data with
-- ON DELETE RESTRICT and are counted in the repositories' usage counts, so
-- a supplier, category or cost center in use is never deleted.
-- `number_bi` is the blind index of the invoice number (purpose
-- "invoice.number"), next to `supplier_id` for the duplicate check (M6-6).
-- `sphere` stays NULL while the tax spheres are switched off (E-15).
--
-- Not yet here, each arrives with the milestone that fills it:
-- `recurring_series_id` (M8, the table it points at does not exist yet),
-- `locked_by`/`locked_at` (M6-4 Festschreibung), `payment_status` (the
-- matching of M9/M10).
--
-- The row is written in two steps inside one transaction: the AAD of
-- `data_enc` names the id, which only exists after the INSERT
-- (App\Service\Invoice\Pruefung).
CREATE TABLE invoice (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    document_id BIGINT UNSIGNED NOT NULL,
    -- Authority App\Domain\InvoiceType.
    doc_type VARCHAR(16) NOT NULL,
    -- Authority App\Domain\InvoiceDirection: `ausgabe` or `einnahme`.
    direction VARCHAR(16) NOT NULL,
    supplier_id BIGINT UNSIGNED NULL,
    invoice_date DATE NOT NULL,
    due_date DATE NULL,
    service_from DATE NULL,
    service_to DATE NULL,
    category_id BIGINT UNSIGNED NULL,
    sphere VARCHAR(32) NULL,
    cost_center_id BIGINT UNSIGNED NULL,
    checked_by BIGINT UNSIGNED NULL,
    checked_at DATETIME NULL,
    dek_sealed VARBINARY(128) NOT NULL,
    data_enc BLOB NOT NULL,
    number_bi BINARY(32) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_invoice_document (document_id),
    KEY idx_invoice_date (invoice_date),
    KEY idx_invoice_number (supplier_id, number_bi),
    CONSTRAINT fk_invoice_document FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE RESTRICT,
    CONSTRAINT fk_invoice_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE RESTRICT,
    CONSTRAINT fk_invoice_category FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE RESTRICT,
    CONSTRAINT fk_invoice_cost_center FOREIGN KEY (cost_center_id) REFERENCES cost_center (id) ON DELETE RESTRICT,
    CONSTRAINT fk_invoice_checked_by FOREIGN KEY (checked_by) REFERENCES `user` (id) ON DELETE SET NULL,
    CONSTRAINT fk_invoice_created_by FOREIGN KEY (created_by) REFERENCES `user` (id) ON DELETE SET NULL,
    CONSTRAINT fk_invoice_updated_by FOREIGN KEY (updated_by) REFERENCES `user` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
