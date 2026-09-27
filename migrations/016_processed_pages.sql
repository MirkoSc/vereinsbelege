-- The scanner (issue #34/M5-4, docs/spec/03-erfassung-und-ki.md section 2):
-- next to every original page the browser may upload a processed version
-- (cropped, dewarped, black and white). Decision E-10 keeps the original, so
-- the processed version is a second blob, never a replacement.
--
-- `processed_blob_ids` is a JSON list parallel to `original_blob_ids`: entry
-- i is the processed blob of page i, or null when the page came without one
-- (a PDF page, or a browser that could not process it - then `pdf_erzeugen`
-- applies the GD fallback). The whole column is NULL when no page has one.
-- No foreign key, like `original_blob_ids`. Nullable, so the previous release
-- keeps working against this schema (CLAUDE.md section 8).
ALTER TABLE document
    ADD COLUMN processed_blob_ids JSON NULL AFTER original_blob_ids;
