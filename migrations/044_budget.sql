-- Budgets per fiscal year (issue #92, docs/spec/05-auswertung-und-export.md
-- section 1 "Stand #92", docs/spec/02-datenmodell.md, E-58).
--
-- One planned expense amount (Soll) for a category, a cost center or the
-- combination of both, per calendar year (= fiscal year, E-16). The actual
-- figure (Ist) is never stored: the report page computes it from the
-- ReportService.
--
-- `year`, `category_id`, `cost_center_id` are plaintext structure: the cost
-- center scope of a Vereinsverantwortlicher filters on `cost_center_id` in
-- SQL (App\Domain\Zugriffsbereich). The amount is vault data: JSON
-- {"amount": <int cents>, "currency": "EUR"} in `data_enc`, encrypted with
-- the row's own DEK, AAD "budget|<id>|data_enc".
--
-- At most one budget per year and pair. A NULL never equals another NULL in
-- a UNIQUE key, so the key runs over two generated columns with 0 for "any".
-- At least one of category/cost center is set (checked by the service and
-- the CHECK constraint).
--
-- ON DELETE RESTRICT like every table with a category or cost center
-- (docs/spec/02-datenmodell.md): CategoryRepository::usageCount() and
-- CostCenterRepository::budgetCount() count budgets, so a category or cost
-- center with a budget is deactivated, not deleted - no budget vanishes
-- without its own audit entry. (RESTRICT also keeps the table valid on
-- MySQL, which forbids CASCADE on the base column of a generated column.)
CREATE TABLE budget (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    year SMALLINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NULL,
    cost_center_id BIGINT UNSIGNED NULL,
    category_key BIGINT UNSIGNED AS (COALESCE(category_id, 0)) STORED,
    cost_center_key BIGINT UNSIGNED AS (COALESCE(cost_center_id, 0)) STORED,
    dek_sealed VARBINARY(128) NOT NULL,
    data_enc BLOB NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_budget_year_pair (year, category_key, cost_center_key),
    KEY idx_budget_cost_center (cost_center_id, year),
    CONSTRAINT chk_budget_target CHECK (category_id IS NOT NULL OR cost_center_id IS NOT NULL),
    CONSTRAINT fk_budget_category FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE RESTRICT,
    CONSTRAINT fk_budget_cost_center FOREIGN KEY (cost_center_id) REFERENCES cost_center (id) ON DELETE RESTRICT,
    CONSTRAINT fk_budget_created_by FOREIGN KEY (created_by) REFERENCES `user` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
