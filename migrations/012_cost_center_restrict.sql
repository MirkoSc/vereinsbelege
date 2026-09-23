-- Cost centers become manageable (M4-1, issue #23, docs/spec/02-datenmodell.md
-- "Fachdaten"). `cost_center` and `user_cost_center` themselves already exist
-- (migrations/010_role.sql) - only the delete behaviour changes.
--
-- Deleting a cost center still assigned to somebody must not silently take
-- away a "Vereinsverantwortlicher"'s scope (docs/spec/01-sicherheit.md
-- section 4): App\Service\MasterData\CostCenterService refuses that in PHP,
-- and this migration backs it with the same rule in the schema (RESTRICT
-- instead of CASCADE), the same shape as `fk_user_role_role`.
--
-- Two statements, not one combined DROP+ADD: MariaDB rejects re-adding a
-- foreign key under the same name within a single ALTER TABLE (errno 121).
ALTER TABLE user_cost_center DROP FOREIGN KEY fk_user_cost_center_cc;

ALTER TABLE user_cost_center
    ADD CONSTRAINT fk_user_cost_center_cc FOREIGN KEY (cost_center_id) REFERENCES cost_center (id) ON DELETE RESTRICT;
