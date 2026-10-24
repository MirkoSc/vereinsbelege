-- AI call log (M7-8, issue #50, docs/spec/03-erfassung-und-ki.md section 6
-- "Client", docs/spec/02-datenmodell.md "ai_call_log"): one row per call to
-- an AI provider made by the `ai_extract` job, shown grouped by provider on
-- /admin/ki-protokoll.
--
-- Operating data, not business data: it names the profile, model, prompt
-- version, outcome status, token counts and duration - never a prompt, an
-- answer or any value read from a receipt (the raw answer stays encrypted
-- in the `extraction` artifact). So it is plaintext and needs no vault.
--
-- - `provider_id` follows the profile (ON DELETE SET NULL); `provider_name`
--   and `model` are copied, so the history survives a deleted or renamed
--   profile.
-- - `status` is App\Service\Ki\KiAufrufStatus::value.
-- - no foreign key to document/job: the job is cleaned up after 7 days.
--
-- Backwards compatible: a new table the previous release never touches.
CREATE TABLE ai_call_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    called_at DATETIME NOT NULL,
    provider_id BIGINT UNSIGNED NULL,
    provider_name VARCHAR(100) NOT NULL,
    model VARCHAR(100) NOT NULL,
    prompt_version VARCHAR(40) NOT NULL,
    is_repair TINYINT(1) NOT NULL DEFAULT 0,
    is_fallback TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL,
    prompt_tokens INT UNSIGNED NULL,
    answer_tokens INT UNSIGNED NULL,
    total_tokens INT UNSIGNED NULL,
    duration_ms INT UNSIGNED NULL,
    http_status SMALLINT UNSIGNED NULL,
    INDEX ix_ai_call_log_called (called_at),
    INDEX ix_ai_call_log_provider (provider_id, called_at),
    CONSTRAINT fk_ai_call_log_provider FOREIGN KEY (provider_id) REFERENCES ai_provider (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
