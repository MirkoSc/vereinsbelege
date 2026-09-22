-- First admin, vault wrapping and vault grant (docs/spec/06-betrieb.md
-- section 1, docs/spec/01-sicherheit.md section 2, docs/spec/02-datenmodell.md
-- "Benutzer und Sicherheit", issue #15/M3-2).
--
-- No `role`/`user_role` here on purpose: roles and the `Permission` enum are
-- M3-6. Until then the row this migration's writer (the installer) creates
-- is simply the one user who holds a vault grant - there is nothing to
-- assign a role to yet. M3-6 adds the tables and assigns the existing row
-- the admin role, it does not touch this migration.
--
-- `user` carries operating data only (email, display name) and is therefore
-- encrypted with the SERVER key (App\Service\Crypto\ServerCrypto), not the
-- vault - the login has to look an account up before any session unlocks a
-- vault (CLAUDE.md section 4). `user_key` and `vault_grant` hold no
-- plaintext at all: the private keys they wrap exist only sealed or wrapped
-- (docs/spec/01-sicherheit.md section 2, "Speicherformate").
CREATE TABLE `user` (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    -- 255 bytes cover the longest valid address (RFC 5321); +41 bytes
    -- overhead (version 1 + nonce 24 + MAC 16, App\Service\Crypto\ServerCrypto).
    email_enc VARBINARY(296) NOT NULL,
    -- Login lookup: HMAC-SHA256 of the lowercased address, keyed from the
    -- server key (ServerCrypto::blindIndex()) - the only index that must work
    -- before a session exists.
    email_bi BINARY(32) NOT NULL UNIQUE,
    display_name_enc VARBINARY(1024) NOT NULL,
    -- password_hash(): PASSWORD_ARGON2ID, fallback PASSWORD_BCRYPT if the
    -- host's PHP was built without libargon2 (docs/spec/01-sicherheit.md
    -- section 3). Deliberately not the KEK salt below - a leaked hash must
    -- not help attack the wrapped vault key, and the other way round.
    password_hash VARCHAR(255) NOT NULL,
    -- `eingeladen` | `aktiv` | `gesperrt` as VARCHAR and not ENUM: the
    -- authority is the PHP enum (App\Domain\UserStatus), the same pattern as
    -- `job.status`/`mail_queue.status`.
    status VARCHAR(16) NOT NULL DEFAULT 'aktiv',
    -- External roles only (Kassenpruefer/Steuerberater, docs/spec/01-sicherheit.md
    -- section 4); NULL for a regular account.
    expires_at DATETIME NULL,
    mfa_required TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    last_login_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One key pair per user (docs/spec/01-sicherheit.md section 2). The private
-- key never has a storable plaintext form - it exists only wrapped here, with
-- a KEK derived from the user's own password (App\Service\Crypto\UserKey).
CREATE TABLE user_key (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    public_key VARBINARY(32) NOT NULL,
    -- version(1) | nonce(24) | secretbox(U_priv 32 + MAC 16).
    wrapped_private_key VARBINARY(73) NOT NULL,
    kdf_salt VARBINARY(16) NOT NULL,
    kdf_ops INT UNSIGNED NOT NULL,
    kdf_mem INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_user_key_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The vault private key, sealed to one user's public key
-- (App\Service\Crypto\VaultGrant) - the hinge of the whole vault model (E-01,
-- docs/spec/01-sicherheit.md section 2). Revoking a user is deleting their
-- row here; nothing more.
CREATE TABLE vault_grant (
    user_id BIGINT UNSIGNED NOT NULL,
    vault_version TINYINT UNSIGNED NOT NULL,
    -- vault version(1) | box_seal(VK_priv 32 + MAC 16, U_pub).
    sealed_private_key VARBINARY(81) NOT NULL,
    -- Who granted access - NULL for the installer, which grants to itself
    -- before any user exists to attribute it to.
    granted_by BIGINT UNSIGNED NULL,
    granted_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, vault_version),
    CONSTRAINT fk_vault_grant_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE,
    CONSTRAINT fk_vault_grant_vault FOREIGN KEY (vault_version) REFERENCES vault (version),
    CONSTRAINT fk_vault_grant_granted_by FOREIGN KEY (granted_by) REFERENCES `user` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
