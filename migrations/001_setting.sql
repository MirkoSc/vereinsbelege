-- Non-sensitive key/value settings (docs/spec/02-datenmodell.md, "Betrieb").
-- Plaintext by design: nothing in here is business data - the first entry is
-- the update channel. Anything sensitive belongs in an encrypted column of
-- its own table instead.
--
-- The column is called `name`, not `key`: KEY is a reserved word in
-- MySQL/MariaDB and would have to be backquoted in every statement.
CREATE TABLE setting (
    name VARCHAR(64) NOT NULL PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
