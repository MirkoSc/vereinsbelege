-- AI provider profiles (M7-1, issue #41, docs/spec/03-erfassung-und-ki.md
-- section 6 "Client", docs/spec/02-datenmodell.md "ai_provider", E-08).
--
-- One interface for all of them: OpenAI-compatible
-- `POST {base_url}/chat/completions`. A profile says where to send it, which
-- model to ask and what that model can do.
--
-- - `api_key_enc` ServerCrypto::encrypt() of the API key (server key, never
--   the vault: the key is operating data, docs/spec/01-sicherheit.md
--   section 2). NULL = no key; a profile without a key is not used, so a
--   fresh installation runs without AI until the admin enters one.
-- - `caps` App\Service\Ki\KiFaehigkeiten: `vision`, `json_schema`,
--   `max_images`, `max_tokens`.
-- - `is_default` exactly one row carries 1 (App\Repository\
--   AiProviderRepository::setDefault()); the default cannot be deleted.
--   No unique index on it: MariaDB cannot index "only the 1s" without a
--   generated column, and the repository switches it in one statement.
CREATE TABLE ai_provider (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    base_url VARCHAR(255) NOT NULL,
    api_key_enc VARBINARY(1024) NULL,
    model VARCHAR(100) NOT NULL,
    caps JSON NOT NULL,
    timeout_s INT UNSIGNED NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_ai_provider_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The shipped template, exactly App\Service\Ki\KiVorlage::OpenAi
-- (tests/Integration/KiAnbieterFlowTest.php compares both). Default profile
-- after the installation, without a key (03 section 6).
INSERT INTO ai_provider (name, base_url, api_key_enc, model, caps, timeout_s, active, is_default, created_at, updated_at) VALUES
('OpenAI', 'https://api.openai.com/v1', NULL, 'gpt-6-luna',
 '{"vision":true,"json_schema":true,"max_images":4,"max_tokens":4000}',
 60, 1, 1, NOW(), NOW());
