# Vorgehen mit Claude Code

## Einmalig vorbereiten

1. **Entscheidungen** in `docs/ENTSCHEIDUNGEN.md` sind bis auf E-03
   (Scanner, klärt sich in M5) getroffen.
2. **Repo `vereinsbelege` anlegen** (öffentlich, leer, ohne README; Lizenz
   GPLv3 im GitHub-Dialog wählen oder später `LICENSE` ergänzen) und dieses
   Starter-Kit hochladen:
   ```bash
   cd vereinsbelege
   git init -b main
   git add . && git commit -m "Add project plan and architecture reference"
   git remote add origin https://github.com/MirkoSc/vereinsbelege.git
   git push -u origin main
   ```
3. **GitHub CLI** installieren und anmelden (`gh auth login`) – Claude Code
   nutzt `gh` für Issues und PRs.
4. **Vereinskalender daneben klonen**, damit Claude Code Infrastruktur
   übernehmen kann:
   ```
   C:\dev\vereinskalender\
   C:\dev\vereinsbelege\      ← hier Claude Code starten
   ```
5. Branch-Schutz für `main` in GitHub aktivieren (PR + grüne CI Pflicht).
6. Test-Subdomain beim Hoster anlegen (für Pre-Releases, Kanal beta) –
   später je Verein eine Produktiv-Installation (Kanal stable).
7. OpenAI-API-Key für den Verein anlegen (eigenes Projekt, Ausgabenlimit
   setzen) und AV-Vertrag abschließen.

## Session 1 – Issues anlegen

Claude Code im Repo starten, dann (Plan Mode, Shift+Tab):

> Lies CLAUDE.md und docs/ROADMAP.md. Lege mit `gh` für jeden Meilenstein
> M0–M13 einen GitHub-Milestone an und für jede Zeile `Mx-y` ein Issue:
> Titel „Mx-y · <Titel>", Body mit Ziel, Spec-Verweisen (als Pfad +
> Abschnitt), Abnahmekriterien aus der Roadmap und der Definition of Done
> als Checkliste. Labels: `milestone:Mx` und je nach Bereich `security`,
> `ki`, `bank`, `ui`, `infra`. Backlog-Punkte als Issues mit Label
> `backlog` ohne Milestone. Zeig mir vorher die Liste zur Bestätigung.

Danach existieren ~70 Issues; die Roadmap-Datei bleibt die Übersicht.

## Session 2 – Hosting-Check (M0)

> /issue <Nr. von M0-1>

Dann `tools/hosting-check.php` per FTP in ein Testverzeichnis laden, im
Browser aufrufen, JSON kopieren und in einer neuen Session:

> Hier ist die Ausgabe des Hosting-Checks: <JSON>. Trage die Befunde in
> docs/hosting-befunde.md ein, vergleiche mit den Annahmen in docs/spec/
> und lege für jede Abweichung ein Issue an. Passe die Specs nicht selbst
> an, sondern schlage die Änderungen im jeweiligen Issue vor.

Skript danach vom Webspace löschen.

## Ab Session 3 – ein Issue pro Session

```
/issue 12
```

Der Slash-Command (`.claude/commands/issue.md`) sorgt für den festen
Ablauf: Issue lesen → nur verlinkte Specs lesen → Plan → Branch → Code +
Tests → PR. Du reviewst den PR, testest ggf. lokal (`docker compose up`)
und mergst.

Tipps, um Tokens zu sparen:
- Neue Session je Issue (`/clear`), nicht lange Sessions über mehrere Issues.
- Die Specs sind bewusst in Dateien geteilt – im Issue nur die nötigen
  Abschnitte verlinken.
- Bei großen Issues zuerst im Plan Mode nur den Plan erstellen lassen,
  prüfen, dann umsetzen lassen.
- Fehler aus CI/Browser konkret einfügen (Meldung + Schritte), nicht
  „geht nicht".

## Releases

Nach jedem Meilenstein (bzw. wenn etwas testbar ist):

```bash
git tag v0.4.0 && git push origin v0.4.0
```

GitHub Action baut ein Pre-Release → Testinstanz (beta) → im Admin
„Update" → testen → auf GitHub als „latest" markieren → Produktivinstanz
(stable) zieht nach. Identisch zum Vereinskalender.

## Reihenfolge und Parallelität

Das Worker-Modul (M7b) kommt nach der KI-Anbindung; bis dahin läuft alles
über den Browser. Den Pi brauchst du erst ab M7b.

M0 → M1 → M2 → M3 strikt nacheinander (alles baut auf Krypto und Login
auf). Danach können M5 (Scanner) und M9 (Kontoauszüge) unabhängig vom
KI-Strang laufen – praktisch, wenn z. B. der KI-Server noch nicht bereit
ist. Ab v0.4.0 kann der Verein produktiv einreichen lassen, auch wenn
KI und Bankabgleich noch fehlen.

## Testdaten sammeln (parallel, früh anfangen)

- 20–30 **Belegfotos** (schief, schlechtes Licht, Kassenbon, mehrseitig)
  für Scanner-Tests → anonymisieren.
- 30 **Belege mit bekanntem Soll-Ergebnis** (Lieferant, Datum, Brutto,
  Kategorie) als KI-Testset.
- **MT940- und CSV-CAMT-Exporte** von Sparkasse und VR Bank (1–2 Monate) →
  Namen/IBANs/Zwecke anonymisieren, Struktur erhalten.
- Ein Stück **Bestandsarchiv** (z. B. ein Monat) für den Archiv-Import.
