-- Issue #100: the Argon2id profile of the vault wrapping moves from
-- INTERACTIVE to MODERATE (docs/spec/01-sicherheit.md section 2). Cost
-- factors were already stored per user (`kdf_ops`, `kdf_mem`); the
-- algorithm now is too, so that a later change of algorithm cannot lock out
-- existing accounts either.
--
-- `kdf_alg` is libsodium's identifier, which names algorithm and Argon2
-- version together: 2 = SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13 (Argon2id, v1.3).
-- Every row written so far used exactly that, hence the default - which also
-- keeps the previous release, which does not know the column, able to insert
-- rows (CLAUDE.md section 8). Old rows keep their INTERACTIVE cost factors
-- until their owner's next login wraps them again
-- (App\Service\Account\LoginService).
ALTER TABLE user_key
    ADD COLUMN kdf_alg TINYINT UNSIGNED NOT NULL DEFAULT 2 AFTER kdf_mem;
