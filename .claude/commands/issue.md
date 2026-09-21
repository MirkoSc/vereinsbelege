---
description: Ein GitHub-Issue nach dem Projekt-Workflow umsetzen
argument-hint: <issue-nummer>
---
Setze GitHub-Issue #$ARGUMENTS um. Halte dich strikt an diesen Ablauf:

1. `gh issue view $ARGUMENTS` lesen. Lies danach NUR die im Issue
   verlinkten Abschnitte aus `docs/spec/` (CLAUDE.md kennst du bereits).
   Andere Spec-Dateien nur, wenn zwingend nötig – dann sag, warum.
2. Nenne einen kurzen Plan (betroffene Dateien, Migrationen, Tests).
   Wenn fachlich etwas unklar oder widersprüchlich ist: frage, bevor du
   Code schreibst.
3. `git switch main && git pull && git switch -c issue-$ARGUMENTS-<kurzname>`
4. Implementiere inkl. der Pflicht-Tests aus der Spec. Keine Änderungen
   außerhalb des Issue-Umfangs (Ideen dafür als neues Issue vorschlagen).
5. Prüfe: `docker compose run --rm app php vendor/bin/phpunit` und – falls
   JS betroffen – `node --test tests/js`. Beides muss grün sein, ohne
   Deprecations.
6. Falls Architektur/Datenmodell betroffen: Spec bzw. CLAUDE.md im selben
   Branch aktualisieren.
7. Commit(s) auf Englisch, `git push -u origin HEAD`,
   `gh pr create --fill` mit Body: Zusammenfassung, `Closes #$ARGUMENTS`,
   Prüfliste für manuelle Tests (Mobil 360 px + Desktop), geprüfte
   Fremdbibliotheken mit Begründung.
8. Gib mir am Ende eine kurze Liste: was gemacht, was manuell zu testen ist,
   offene Punkte.
