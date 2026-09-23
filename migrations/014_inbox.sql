-- The inbox (issue #27/M4-5, docs/spec/02-datenmodell.md "Fachdaten" and
-- "Statusmodell document.status"): what the status transitions of the inbox
-- need on `document`. Only nullable columns - the previous release keeps
-- working against this schema (CLAUDE.md section 8).
--
-- `cost_center_id` is a structural field in plaintext like
-- `invoice.cost_center_id`: the inbox filters by it, and the "eigene
-- Kostenstelle" scope of `inbox.view` (docs/spec/01-sicherheit.md section 4)
-- is enforced on it in SQL (App\Domain\Zugriffsbereich::sqlBedingung()).
-- The public submission fills it from the visitor's choice; the inbox can
-- change it. RESTRICT like `user_cost_center` (migrations/012): a cost
-- center still carrying receipts is deactivated, not deleted
-- (App\Service\MasterData\CostCenterService checks first).
--
-- `resubmit_on` is the Wiedervorlage date - plaintext DATE so "due again"
-- is a WHERE clause. `status_note_enc` is the reason of a rejection or the
-- note of a Wiedervorlage: business text, so FieldCipher under the row's
-- DEK (`dek_sealed`), AAD "document|<id>|status_note_enc".
ALTER TABLE document
    ADD COLUMN cost_center_id BIGINT UNSIGNED NULL AFTER submission_id,
    ADD COLUMN resubmit_on DATE NULL AFTER ocr_status,
    ADD COLUMN status_note_enc VARBINARY(4096) NULL AFTER resubmit_on,
    ADD COLUMN status_changed_at DATETIME NULL AFTER status_note_enc,
    ADD COLUMN status_changed_by BIGINT UNSIGNED NULL AFTER status_changed_at;

ALTER TABLE document
    ADD CONSTRAINT fk_document_cost_center FOREIGN KEY (cost_center_id) REFERENCES cost_center (id) ON DELETE RESTRICT;

CREATE INDEX idx_document_status_resubmit ON document (status, resubmit_on);

CREATE INDEX idx_document_created ON document (created_at);
