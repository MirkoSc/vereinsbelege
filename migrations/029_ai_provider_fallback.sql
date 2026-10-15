-- Fallback AI profile (M7-2b, issue #180, docs/spec/03-erfassung-und-ki.md
-- section 6 "Client", docs/spec/02-datenmodell.md "ai_provider").
--
-- If the default profile does not answer (connection, timeout, HTTP 429/5xx),
-- the AI client tries ONE optional fallback profile once
-- (App\Service\Ki\KiAbfrage::fallbackFragen()).
--
-- - `is_fallback` at most one row carries 1 (App\Repository\
--   AiProviderRepository::setFallback() switches it in one statement; no
--   unique index, for the same reason as `is_default` in 024). No row at all
--   means "no fallback". Deleting the fallback profile removes the choice
--   with the row.
-- - The fallback is never the default: the check keeps the two flags apart
--   even if a statement outside the repository tries otherwise. That the
--   fallback is active and has a key is a rule of App\Service\Ki\
--   KiAnbieterService (a key is encrypted data the database cannot judge).
--
-- Backwards compatible: the column defaults to 0, the previous release
-- neither reads nor writes it.
ALTER TABLE ai_provider
    ADD COLUMN is_fallback TINYINT(1) NOT NULL DEFAULT 0 AFTER is_default,
    ADD CONSTRAINT ck_ai_provider_fallback_not_default CHECK (NOT (is_default = 1 AND is_fallback = 1));
