-- The vault's public key (docs/spec/01-sicherheit.md section 2,
-- docs/spec/02-datenmodell.md "Benutzer und Rechte").
--
-- VK_pub is public by design, so it is the one key that may sit in the
-- database in the clear: sealing a data key to it needs no secret, which is
-- exactly what lets the public submission encrypt a receipt it can never read
-- back. VK_priv is never stored here - it only exists wrapped per user
-- (`user_key`, `vault_grant`) and on the printed recovery key.
--
-- The table arrives with M2-4, because the chunk upload is the first writer
-- that has to seal a data key. M3-2 (first admin in the installer) writes the
-- row; until then the upload endpoint answers 503 instead of guessing a key.
CREATE TABLE vault (
    -- The generation byte that App\Service\Crypto\Vault prefixes to every
    -- sealed data key, so a later key rotation can tell generations apart.
    -- One byte there, one byte here (1..255).
    version TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    -- crypto_box public key, 32 bytes.
    public_key VARBINARY(32) NOT NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
