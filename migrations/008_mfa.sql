-- Second factor (docs/spec/01-sicherheit.md section 3, docs/spec/
-- 02-datenmodell.md "Benutzer und Sicherheit", issue #17/M3-4): TOTP,
-- e-mail code, backup codes, remembered devices.
--
-- `user.mfa_method` is the one authoritative answer to "has this account set
-- up a second factor at all" - App\Http\LoginGuard reads it to force
-- enrollment, the login reads it to decide whether TOTP or e-mail applies.
-- NULL means "not set up yet", not "none": `mfa_required` (migration 006)
-- already defaults every account to requiring one.
ALTER TABLE `user`
    ADD COLUMN mfa_method VARCHAR(16) NULL AFTER mfa_required;

-- One TOTP secret per user (docs/spec/01-sicherheit.md section 2:
-- `totp_secret_enc` is server-key ciphertext, like a mail address - it has
-- to be readable to verify a code before any vault is open).
-- `confirmed_at` stays NULL between "secret generated" and "first code
-- confirmed" (App\Service\Account\MfaEnrollment): a secret nobody has
-- confirmed is not yet the account's method and must not be treated as one.
CREATE TABLE mfa_totp (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    -- version(1) | nonce(24) | secretbox(20-byte secret + MAC 16) = 61 bytes;
    -- rounded up for headroom without pinning the secret length in the schema.
    secret_enc VARBINARY(96) NOT NULL,
    confirmed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_mfa_totp_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The open e-mail-code request, at most one per user - a fresh code
-- overwrites the row instead of accumulating one per attempt
-- (docs/spec/01-sicherheit.md section 3: "6 Ziffern, 10 min gültig, max. 5
-- Versuche, nur Hash gespeichert"). `code_hash` is the blind-index HMAC of
-- App\Service\Crypto\ServerCrypto keyed with purpose `mfa.email_code`
-- (App\Service\Account\MfaService) - the same primitive `user.email_bi`
-- already uses, not a new one.
CREATE TABLE mfa_email_code (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    code_hash BINARY(32) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_mfa_email_code_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ten one-time codes per user, generated together at setup and whenever they
-- are regenerated (docs/spec/01-sicherheit.md section 3). `used_at` marks a
-- spent code rather than deleting the row, so "9 von 10 noch gültig" can be
-- shown without a separate counter.
CREATE TABLE mfa_backup_code (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    code_hash BINARY(32) NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_mfa_backup_code_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE,
    UNIQUE KEY uq_mfa_backup_code (user_id, code_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- "Dieses Gerät 30 Tage merken" (docs/spec/01-sicherheit.md section 3):
-- one row per remembered browser, revocable per user and per row.
-- `token_hash` uses the same blind-index HMAC as the other `*_hash` columns
-- here, purpose `trusted_device.token`.
CREATE TABLE trusted_device (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash BINARY(32) NOT NULL,
    label VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    expires_at DATETIME NOT NULL,
    CONSTRAINT fk_trusted_device_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE,
    UNIQUE KEY uq_trusted_device_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Swept by the cron (App\Service\Cron\TrustedDeviceCleanupTask, follow-up of
-- the same shape as RateLimitCleanupTask) once expired rows no longer let
-- anyone in.
CREATE INDEX idx_trusted_device_expires ON trusted_device (expires_at);
