-- Supplier resolution (issue #48/M7-6, docs/spec/03-erfassung-und-ki.md
-- sections 5 and 7): the `resolve_supplier` session job (App\Service\
-- Document\LieferantenAufloesung) resolves the supplier the AI read against
-- the master data and creates it (`created_via = ki`, `needs_review`) when
-- nothing matches. New documents get the job when `ai_extract` moves them to
-- `ausgewertet`; no schema change - the result is a `document_artifact` of
-- the kind `lieferant` (VARCHAR(16), migrations/015), and `supplier` has had
-- `created_via`/`needs_review` since migrations/018.
--
-- Every document the AI read before this release (`ausgewertet`, the status
-- the job starts from) gets the job too. Values as in App\Repository\
-- JobRepository::enqueue(): type, executor `session`, status `offen`, empty
-- state. The previous release has no handler for the type and never claims
-- it.
INSERT INTO job (typ, ref_type, ref_id, executor, status, step, state, created_at, updated_at)
SELECT 'resolve_supplier', 'document', id, 'session', 'offen', '', '[]', NOW(), NOW()
FROM document
WHERE status = 'ausgewertet';
