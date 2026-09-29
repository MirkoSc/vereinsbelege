-- Suppliers and payers (M6-2, issue #36, docs/spec/02-datenmodell.md
-- "Fachdaten" and "Lieferanten", docs/spec/03-erfassung-und-ki.md section 7).
--
-- Club data, so the vault covers it (CLAUDE.md section 5): everything a
-- person could be recognised by - name, aliases, address, IBANs, tax and
-- creditor ids, mandate references - lives in `data_enc` (JSON, FieldCipher
-- under the row's DEK in `dek_sealed`, AAD "supplier|<id>|data_enc").
-- Plaintext are only the structural fields SQL filters on.
--
-- `role` (App\Domain\SupplierRole): `lieferant`, `zahler` or `beide` - which
-- side of the books the partner usually appears on. Filters the list and
-- later the choice in the review page (M6-3).
-- `default_category_id` points at `category` with ON DELETE RESTRICT and is
-- counted in App\Repository\CategoryRepository::usageCount().
-- `default_sphere` stays NULL while the tax spheres are switched off (E-15).
-- `created_via` is `manuell` for the page of this release; `ki` and `archiv`
-- come with the automatic resolution (M7-6) and the archive import.
-- `merged_into` is prepared for merging duplicates (M6-5).
--
-- The row is written in two steps inside one transaction: the AAD of
-- `data_enc` names the id, which only exists after the INSERT
-- (App\Service\MasterData\SupplierService).
CREATE TABLE supplier (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    role VARCHAR(16) NOT NULL,
    dek_sealed VARBINARY(128) NOT NULL,
    data_enc BLOB NOT NULL,
    default_category_id BIGINT UNSIGNED NULL,
    default_sphere VARCHAR(32) NULL,
    created_via VARCHAR(16) NOT NULL,
    needs_review TINYINT(1) NOT NULL DEFAULT 0,
    merged_into BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_supplier_role (role, merged_into),
    CONSTRAINT fk_supplier_category FOREIGN KEY (default_category_id) REFERENCES category (id) ON DELETE RESTRICT,
    CONSTRAINT fk_supplier_merged FOREIGN KEY (merged_into) REFERENCES supplier (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Blind indexes of a supplier's identifying values, several per kind: one
-- row per name/alias, IBAN, VAT id, tax number, creditor id and mandate
-- reference (docs/spec/01-sicherheit.md section 2: HMAC with the key derived
-- from the vault, purpose "supplier.<kind>"). The automatic resolution
-- (M7-6) looks a value up here without decrypting a single supplier.
--
-- Rewritten as a whole on every save. Whether an IBAN, VAT id, tax number or
-- creditor id may belong to only one supplier is checked by
-- App\Service\MasterData\SupplierService, not by a UNIQUE key - merging
-- (M6-5) moves the keys over and must not trip over a constraint halfway.
CREATE TABLE supplier_key (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    supplier_id BIGINT UNSIGNED NOT NULL,
    kind VARCHAR(16) NOT NULL,
    value_bi BINARY(32) NOT NULL,
    UNIQUE KEY uq_supplier_key (supplier_id, kind, value_bi),
    KEY idx_supplier_key_lookup (kind, value_bi),
    CONSTRAINT fk_supplier_key_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
