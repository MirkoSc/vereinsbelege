-- Password change and reset (docs/spec/01-sicherheit.md section 2
-- "Benutzer-Lebenszyklus", section 3 "Passwort-Reset", docs/spec/
-- 02-datenmodell.md "Benutzer und Sicherheit", issue #18/M3-5).

-- One-time links sent by mail. `typ` is `reset` for now; the invitation of
-- M3-7 (`invite`, 72 h) uses the same table, which is why it is not called
-- `password_reset`. VARCHAR and not ENUM for the same reason as
-- `user.status`: the authority is the PHP side
-- (App\Service\Account\PasswordReset).
--
-- Only a digest of the token is stored: `token_hash` is the blind-index HMAC
-- of App\Service\Crypto\ServerCrypto with purpose `auth_token.reset` - the
-- same primitive as `user.email_bi` and the `mfa_*`/`trusted_device` hashes.
-- A database reader cannot turn a row back into a working link.
CREATE TABLE auth_token (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    typ VARCHAR(16) NOT NULL,
    token_hash BINARY(32) NOT NULL,
    expires_at DATETIME NOT NULL,
    -- Set when the link is used; a used row is never accepted again
    -- ("einmalig", docs/spec/01-sicherheit.md section 3).
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_auth_token_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE,
    UNIQUE KEY uq_auth_token_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Swept by the cron (App\Service\Cron\AuthTokenCleanupTask).
CREATE INDEX idx_auth_token_expires ON auth_token (expires_at);

-- Ending sessions without a session registry. Sessions are plain PHP session
-- files, so there is no list of them to delete; instead every session copies
-- this counter at login (App\Http\Session::login()) and App\Http\LoginGuard
-- ends any session whose copy no longer matches. A reset raises it (ends
-- every session of the account), a password change raises it and hands the
-- new value to the one session that made the change.
-- Default 0: a session started before this migration carries no counter,
-- which reads as 0 and therefore survives the update.
ALTER TABLE `user`
    ADD COLUMN session_epoch INT UNSIGNED NOT NULL DEFAULT 0 AFTER mfa_method;
