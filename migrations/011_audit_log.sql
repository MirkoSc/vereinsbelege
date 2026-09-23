-- Audit log with a hash chain (docs/spec/01-sicherheit.md section 6,
-- docs/spec/02-datenmodell.md "Benutzer und Sicherheit", issue #21/M3-8).
--
-- The replacement for event sourcing (E-05): append-only, and every row
-- carries hash = SHA-256(prev_hash || canonical JSON of the row), so a row
-- changed, removed or reordered afterwards breaks the chain from that point
-- on (App\Service\Audit\AuditChain).
--
-- The id is NOT auto-increment on purpose. The writer computes it (head id +
-- 1) because it needs it BEFORE the insert: it is part of the AAD of
-- details_enc and of the hashed row, and the row is written in exactly one
-- INSERT, never touched again. Two writers racing for the same id collide on
-- the primary key and the loser re-reads the head (App\Service\Audit\
-- AuditLog) - that serialises the chain without SELECT ... FOR UPDATE
-- (hosting finding #98).
CREATE TABLE audit_log (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    ts DATETIME NOT NULL,
    -- The acting account; NULL for events without one (a failed login).
    -- No foreign key: the log must outlive the accounts it names.
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(64) NOT NULL,
    entity VARCHAR(32) NULL,
    entity_id BIGINT UNSIGNED NULL,
    -- HMAC of the client IP under the server key ("audit.ip"): shows
    -- "same address as ...", never the address, and cannot be reversed by
    -- trying all IPv4 addresses without the key.
    ip_hash BINARY(32) NULL,
    -- Vault-encrypted details (AAD audit_log|id|details_enc) and their row
    -- key, sealed to the vault public key. Both NULL for an event without
    -- details.
    details_enc VARBINARY(4096) NULL,
    dek_sealed VARBINARY(96) NULL,
    prev_hash BINARY(32) NOT NULL,
    hash BINARY(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The filters of /app/audit (App\Repository\AuditLogRepository::page()).
CREATE INDEX idx_audit_log_ts ON audit_log (ts);
CREATE INDEX idx_audit_log_user ON audit_log (user_id);
CREATE INDEX idx_audit_log_action ON audit_log (action);
CREATE INDEX idx_audit_log_entity ON audit_log (entity, entity_id);
