-- Roles and rights (docs/spec/01-sicherheit.md section 4, docs/spec/
-- 02-datenmodell.md "Benutzer und Sicherheit", issue #19/M3-6).
--
-- Plaintext on purpose: none of these tables carries club data, and the
-- guard (App\Http\LoginGuard) reads them on every request before any vault
-- is involved.

-- A named set of rights. `permissions` maps a right (App\Domain\Permission
-- value) to its reach, `alle` or `kostenstelle` (App\Domain\PermissionScope).
-- `system_key` marks the six roles every installation ships with
-- (App\Domain\SystemRole): they can be neither renamed nor deleted, and
-- `admin` always holds every right whatever this column says - nobody can
-- lock the club out of its own administration.
-- `is_external`: accounts holding such a role need an end date, always need
-- 2FA and may only read (App\Service\Account\AccessAssignment).
CREATE TABLE role (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    system_key VARCHAR(32) NULL,
    name VARCHAR(100) NOT NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    is_external TINYINT(1) NOT NULL DEFAULT 0,
    permissions JSON NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_role_system_key (system_key),
    UNIQUE KEY uq_role_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- n:m - an account's rights are the union of its roles
-- (App\Domain\Berechtigungen). A role still assigned cannot be deleted
-- (RESTRICT): taking rights away silently from somebody is the one thing
-- a delete must not do.
CREATE TABLE user_role (
    user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT fk_user_role_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_role_role FOREIGN KEY (role_id) REFERENCES role (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cost centers (docs/spec/02-datenmodell.md "Stammdaten"). Created here
-- already because the cost-center scope below needs something to point at;
-- the admin page to maintain them is M4-1.
CREATE TABLE cost_center (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    sort INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_cost_center_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cost-center scope ("Vereinsverantwortlicher"): rights a role grants with
-- scope `kostenstelle` reach only these.
CREATE TABLE user_cost_center (
    user_id BIGINT UNSIGNED NOT NULL,
    cost_center_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, cost_center_id),
    CONSTRAINT fk_user_cost_center_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_cost_center_cc FOREIGN KEY (cost_center_id) REFERENCES cost_center (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Period scope of external accounts (e.g. only financial year 2026). No row
-- = no restriction. Both bounds inclusive.
CREATE TABLE user_scope (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    period_from DATE NULL,
    period_to DATE NULL,
    CONSTRAINT fk_user_scope_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The six shipped roles, rights as in the matrix of docs/spec/
-- 01-sicherheit.md section 4. tests/Domain/SystemRoleTest.php checks this
-- seed against App\Domain\SystemRole::standardRechte().
INSERT INTO role (system_key, name, is_system, is_external, permissions, created_at) VALUES
('admin', 'Admin', 1, 0, '{"inbox.view":"alle","document.edit":"alle","document.submit_internal":"alle","supplier.manage":"alle","bank.import":"alle","bank.book":"alle","bank.view":"alle","matching.edit":"alle","report.view":"alle","export.zip":"alle","export.csv":"alle","archive.import":"alle","audit.view":"alle","admin.users":"alle","admin.vault_grant":"alle","admin.settings":"alle","admin.system":"alle"}', NOW()),
('vorstand', 'Vorstand', 1, 0, '{"inbox.view":"alle","document.submit_internal":"alle","bank.view":"alle","report.view":"alle","export.zip":"alle","export.csv":"alle","audit.view":"alle"}', NOW()),
('finanzen', 'Finanzen', 1, 0, '{"inbox.view":"alle","document.edit":"alle","document.submit_internal":"alle","supplier.manage":"alle","bank.import":"alle","bank.book":"alle","bank.view":"alle","matching.edit":"alle","report.view":"alle","export.zip":"alle","export.csv":"alle","archive.import":"alle"}', NOW()),
('kassenpruefer', 'Kassenprüfer', 1, 1, '{"inbox.view":"alle","bank.view":"alle","report.view":"alle","export.zip":"alle","export.csv":"alle","audit.view":"alle"}', NOW()),
('steuerberater', 'Steuerberater', 1, 1, '{"inbox.view":"alle","bank.view":"alle","report.view":"alle","export.zip":"alle","export.csv":"alle"}', NOW()),
('vereinsverantwortlicher', 'Vereinsverantwortlicher', 1, 0, '{"inbox.view":"kostenstelle","document.submit_internal":"alle","report.view":"kostenstelle"}', NOW());

-- Every account that exists before this migration is the installer's first
-- admin (migrations/006_user.sql): it keeps the rights it had, all of them.
INSERT INTO user_role (user_id, role_id)
SELECT u.id, r.id FROM `user` u JOIN role r ON r.system_key = 'admin';
