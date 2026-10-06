-- Text layer of digital PDFs (issue #45/M7-3, docs/spec/03-erfassung-und-ki.md
-- sections 3 and 5): the `extract_text` session job
-- (App\Service\Document\Texterkennung) stores the text of every PDF original
-- as a `document_artifact` of kind `text`, inline in `data_enc`.
--
-- VARBINARY(4096) was sized for the small payloads `page_image` never even
-- uses - the text of a multi-page invoice does not fit. MEDIUMBLOB holds up to
-- 16 MiB; the reader keeps at most 512 KiB of text per PDF
-- (App\Service\Processing\Pdf\PdfTextlayer::MAX_TEXT), plus JSON and cipher
-- overhead. Widening keeps every existing value, and the previous release
-- reads and writes the column unchanged.
ALTER TABLE document_artifact MODIFY data_enc MEDIUMBLOB NULL;

-- Every document received before this release that may still go to the AI
-- (`ai_extract`, M7-5) gets its text layer read by the next signed-in
-- session, like every new one does (App\Service\Submission\SubmissionService,
-- InterneErfassung). Checked, locked and rejected documents are past that
-- point. Values as in App\Repository\JobRepository::enqueue(): type, executor
-- `session`, status `offen`, empty state. The previous release has no handler
-- for the type and never claims it.
INSERT INTO job (typ, ref_type, ref_id, executor, status, step, state, created_at, updated_at)
SELECT 'extract_text', 'document', id, 'session', 'offen', '', '[]', NOW(), NOW()
FROM document
WHERE ocr_status = 'keine'
  AND status IN ('eingegangen', 'bereit_zur_auswertung', 'wiedervorlage', 'ki_fehler');

UPDATE document SET ocr_status = 'ausstehend'
WHERE ocr_status = 'keine'
  AND status IN ('eingegangen', 'bereit_zur_auswertung', 'wiedervorlage', 'ki_fehler');
