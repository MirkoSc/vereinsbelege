-- AI extraction (issue #47/M7-5, docs/spec/03-erfassung-und-ki.md sections 5
-- and 6): the `ai_extract` session job (App\Service\Document\KiAuslesung)
-- reads every accepted document with prompt `extract-v1` and moves it to
-- `ausgewertet` or `ki_fehler`. New documents get the job when they are
-- accepted in the inbox (App\Service\Inbox\Posteingang) or imported from the
-- archive; no schema change - the attempts are `document_artifact` rows of
-- the kind `extraction` that migrations/015 already provides for.
--
-- Every document accepted before this release still waits for the AI
-- (`bereit_zur_auswertung`, the status the job starts from) and gets the job
-- too. Without a usable AI profile the job skips itself on its first step and
-- the receipt stays to be captured by hand, as before. Values as in
-- App\Repository\JobRepository::enqueue(): type, executor `session`, status
-- `offen`, empty state. The previous release has no handler for the type and
-- never claims it.
INSERT INTO job (typ, ref_type, ref_id, executor, status, step, state, created_at, updated_at)
SELECT 'ai_extract', 'document', id, 'session', 'offen', '', '[]', NOW(), NOW()
FROM document
WHERE status = 'bereit_zur_auswertung';
