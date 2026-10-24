-- Vault key rotation (issue #90, docs/spec/01-sicherheit.md section 2
-- "Schlüsselrotation", decision E-32).
--
-- `vault` grows two timestamps that describe a generation's lifecycle:
--   recovery_confirmed_at - the admin typed back the last group of the
--                           printed recovery key. A rotation may only finish
--                           once the NEW generation's key is confirmed;
--                           every generation that exists today was confirmed
--                           in the installer, so the timestamp is taken over
--                           from created_at.
--   retired_at            - the generation was replaced: every data key is
--                           sealed to its successor and its grants are
--                           deleted. The row itself stays (foreign keys,
--                           history, and the paper key of an old backup is
--                           recognised by it); nothing ever deletes it.
-- A rotation in progress is exactly "more than one generation without
-- retired_at" - no separate state table to keep in step.
ALTER TABLE vault
    ADD COLUMN recovery_confirmed_at DATETIME NULL,
    ADD COLUMN retired_at DATETIME NULL;

UPDATE vault SET recovery_confirmed_at = created_at WHERE recovery_confirmed_at IS NULL;

-- `audit_log.dek_sealed` is part of the hash chain (App\Service\Audit\
-- AuditChain::canonicalJson()): re-sealing it in place would break the
-- chain exactly the way a tampered row does. The re-sealed key therefore
-- goes into a column OUTSIDE the chain; readers take dek_resealed when it
-- is set and dek_sealed otherwise. The original stays as written - it is
-- what the row's hash covers.
ALTER TABLE audit_log
    ADD COLUMN dek_resealed VARBINARY(96) NULL;
