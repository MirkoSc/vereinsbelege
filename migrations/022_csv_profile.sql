-- CSV import profiles (M9-3, issue #61, docs/spec/04-bank-und-abgleich.md
-- section 3, docs/spec/02-datenmodell.md "csv_profile").
--
-- How to read one bank's CSV export: separator, character set, date and
-- number notation and which column carries which field. No club data -
-- column names and formats only - so plaintext, readable without the vault.
--
-- - `builtin` 1 = shipped with the application (seeded below); such a row
--   is read-only in the UI and never deleted.
-- - `header_signature` SHA-256 (hex) of the normalised header the profile
--   was made from (App\Service\Bank\Csv\CsvProfil::signatur()); an import
--   whose header has exactly this signature takes this profile first.
--   NULL when unknown.
-- - `mapping` {field => [column names]} - fields are the values of
--   App\Service\Bank\Csv\CsvFeld; several names are aliases (header
--   variants), for text fields they are joined.
-- - `delimiter` App\Service\Bank\Csv\CsvTrennzeichen (`semikolon`, `komma`,
--   `tab`, `pipe`), `encoding` CsvZeichensatz (`auto`, `UTF-8`,
--   `Windows-1252`), `date_format` CsvDatumsformat (`d.m.Y`, `Y-m-d`,
--   `d/m/Y`), `decimal_sep` CsvDezimaltrenner (`,` or `.`; the other one is
--   the thousands separator).
CREATE TABLE csv_profile (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    builtin TINYINT(1) NOT NULL DEFAULT 0,
    header_signature CHAR(64) NULL,
    mapping JSON NOT NULL,
    delimiter VARCHAR(16) NOT NULL,
    encoding VARCHAR(16) NOT NULL,
    date_format VARCHAR(16) NOT NULL,
    decimal_sep CHAR(1) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_csv_profile_name (name),
    KEY idx_csv_profile_signature (header_signature)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The shipped profiles, exactly App\Service\Bank\Csv\CsvStandardprofile
-- (tests/Integration/CsvProfileFlowTest.php compares both). Signatures are
-- those of the Sparkasse "CSV-CAMT V2" and the VR Bank header.
INSERT INTO csv_profile (name, builtin, header_signature, mapping, delimiter, encoding, date_format, decimal_sep, created_at, updated_at) VALUES
('Sparkasse CSV-CAMT', 1, '8734ed97908af9e7d970f3c54a3e2db876fdf64ba1dc16ffe944e9d29d680848',
 '{"buchungstag":["Buchungstag"],"valuta":["Valutadatum"],"betrag":["Betrag"],"waehrung":["Waehrung"],"name":["Beguenstigter/Zahlungspflichtiger"],"iban":["Kontonummer/IBAN"],"bic":["BIC (SWIFT-Code)"],"verwendungszweck":["Verwendungszweck"],"eref":["Kundenreferenz (End-to-End)"],"mref":["Mandatsreferenz"],"glaeubiger_id":["Glaeubiger ID","Glaeubiger-ID"],"buchungstext":["Buchungstext"],"hinweis":["Info"]}',
 'semikolon', 'auto', 'd.m.Y', ',', NOW(), NOW()),
('VR Bank CSV-CAMT', 1, '923952df53a70b2639418539dfff0cb340b6d7d799513e97061f4fe5ad43085a',
 '{"buchungstag":["Buchungstag"],"valuta":["Valutadatum"],"betrag":["Betrag"],"waehrung":["Waehrung"],"name":["Name Zahlungsbeteiligter"],"iban":["IBAN Zahlungsbeteiligter"],"bic":["BIC (SWIFT-Code) Zahlungsbeteiligter"],"verwendungszweck":["Verwendungszweck"],"mref":["Mandatsreferenz"],"glaeubiger_id":["Glaeubiger ID","Glaeubiger-ID"],"buchungstext":["Buchungstext"],"saldo":["Saldo nach Buchung"]}',
 'semikolon', 'auto', 'd.m.Y', ',', NOW(), NOW());
