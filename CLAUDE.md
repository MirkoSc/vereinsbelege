# CLAUDE.md – Vereinsbelege

Beleg-/Rechnungsverwaltung für einen Sportverein. Dieses Dokument ist die
**verbindliche Architektur-Referenz**. Bei Widerspruch zwischen Code und
Dokument gilt das Dokument – oder es wird im selben PR bewusst geändert.
Änderungswünsche laufen als GitHub Issues, nicht in dieser Datei.

Diese Datei ist bewusst kurz gehalten (sie wird in JEDER Session geladen).
Details stehen in `docs/spec/*.md` – **lies nur die Spec-Dateien, die das
aktuelle Issue verlinkt**, nicht alle.

| Spec | Inhalt |
|---|---|
| `docs/spec/01-sicherheit.md` | Anmeldung, 2FA, Rollen/Rechte, Verschlüsselung (Tresor-Modell), Bedrohungsmodell |
| `docs/spec/02-datenmodell.md` | Tabellen, Verschlüsselungs-Klassifizierung, Statusmodelle |
| `docs/spec/03-erfassung-und-ki.md` | Öffentliche Einreichung, Scanner, PDF, Texterkennung, KI-Anbindung, Lieferanten, Kategorien, wiederkehrende Rechnungen |
| `docs/spec/04-bank-und-abgleich.md` | MT940/CSV-Import, Konten, Kasse, Abgleich Beleg ↔ Buchung |
| `docs/spec/05-auswertung-und-export.md` | Auswertungen, ZIP-Export, Archiv-Import |
| `docs/spec/06-betrieb.md` | Installer, Updater, Backup, Mail, Job-Verarbeitung, Hosting-Befunde |
| `docs/spec/07-worker.md` | Optionaler Verarbeitungs-Worker (Raspberry Pi/VPS): OCR, PDF/A, KI rund um die Uhr |

## 1. Harte Umgebungs-Constraints (niemals verletzen)

- Shared Hosting (all-inkl): **kein SSH, kein Git, kein Composer, kein
  `exec()`/`shell_exec()`/`proc_open()`** auf dem Server. Keine
  Kommandozeilen-Tools (kein Tesseract, kein Ghostscript, kein ImageMagick-CLI).
- Deployment ausschließlich über Release-ZIPs (GitHub Releases) +
  `setup.php`/Self-Updater – **übernommen aus `MirkoSc/vereinskalender`**.
- **PHP 8.5**, fehler- und deprecation-frei; Enums, readonly, Promotion,
  `match` aktiv nutzen. MySQL/MariaDB über PDO, Prepared Statements,
  `ERRMODE_EXCEPTION`.
- **Kein einzelner Request darf lange laufen.** Lange Vorgänge (KI-Auslesen,
  Import, Export, Umschlüsseln, Update) sind idempotente Schrittketten aus
  kurzen Requests mit persistentem Status (Job-Tabelle bzw. Statusdatei).
- Kein Build-Step auf dem Server; `vendor/` und gebaute Assets liegen im
  Release-ZIP. Nur reine PHP-Bibliotheken ohne native Abhängigkeiten.
- Rechenintensive Bildarbeit (Kantenerkennung, Entzerrung, Schwarzweiß,
  PDF-Rasterung) läuft **im Browser**; der Server nutzt nur GD als Fallback.
- Server-gerendertes PHP + htmx + Vanilla JS + handgeschriebenes CSS, kein
  SPA-Framework, kein CSS-Build (E-07).
- **Eine Installation = ein Verein** (E-12). Kein Mandanten-Konzept.
- Öffentliches Repo: **niemals Secrets, Zugangsdaten oder echte Belegdaten
  committen** – Testdaten nur anonymisiert (E-13).

## 2. Verzeichnislayout

Identisch zum Vereinskalender:

Die (Sub-)Domain zeigt im Kundenmenü des Hosters auf `/web/`; alles daneben
liegt damit außerhalb des öffentlich erreichbaren Bereichs. Ist das nicht
einstellbar, muss `shared/` per `.htaccess` gesperrt werden – dass das
greift, prüft `tools/hosting-check.php`.

```
/web/            DocumentRoot: index.php-Shim + .htaccess (Security-Header, CSP)
/current/        aktives Release (per rename() umgeschaltet)
/releases/vX.Y.Z/
/shared/         überlebt Updates:
   config.php        DB-Zugang, Cron-Token, Server-Schlüssel (Abschnitt 4)
   maintenance.flag  gesetzt, solange der Updater umschaltet (Shim prüft sie)
   update_state.json Stand der Update-Schrittkette
   release_checksums.txt  Prüfsummen des installierten Releases
   var/blobs/        verschlüsselte Dateien (Storage-Backend „Dateisystem")
   var/backups/      Backups (enthalten nur Chiffrat für fachliche Daten)
   var/log/app.log
   var/tmp/          nur Chiffrat oder Upload-Chunks, wird aufgeräumt
```

Repo spiegelt ein Release: `app/src/` (Namespace `App\`, Unterordner
`Http`, `Domain`, `Repository`, `Service/<Bereich>`, `Admin`, `App`,
`Api`, `PublicPages`), `app/views/`, `public/` (CSS, JS, vendored JS-Libs),
`bin/`, `migrations/`, `tests/`, `setup.php`. Dazu `tools/` für
eigenständige, abhängigkeitsfreie Hilfsskripte, die **nicht** Teil eines
Releases sind (derzeit `tools/hosting-check.php`, siehe 06 §5).

## 3. Bereiche der Anwendung

- **Öffentlich** (`/einreichen`): Beleg einreichen ohne Anmeldung und ohne
  Code – Kamera, Bild- oder PDF-Upload, Erstattungsziel, Freitext. Schreibt
  nur in den Posteingang, kann nichts lesen. Spamschutz unsichtbar (E-14).
- **Anwenderseite** (`/app/...`): Posteingang, Belege, Lieferanten, Konten,
  Abgleich, Auswertungen – je nach Rolle.
- **Adminseite** (`/admin/...`): Benutzer, Rollen, Tresor-Freigaben,
  KI-Anbieter, Mail, Speicher-Backend, Kategorien, Einstellungen, Backup,
  Update, Audit-Log.

Jede Seite wählt ihren Bereich über `App\View\Area`; daran hängen
Navigation, Inhaltsbreite und ob die Seite eine Session haben darf. Es gibt
genau **ein** Layout (`app/views/layout.php` + `app/views/partials/`) und ein
handgeschriebenes Designsystem in `public/css/app.css` (Farbtokens hell/
dunkel, Formularfelder, Meldungen, Tabellen). Musterseite aller Bausteine:
`/admin/designsystem` – neue Seiten kopieren von dort, statt eigene Klassen
zu erfinden.

## 4. Sicherheits-Invarianten (Details: 01-sicherheit.md)

- **Zwei Schlüsselebenen:**
  1. *Server-Schlüssel* (in `shared/config.php`, nie in der DB, nie im Backup
     der DB): nur für Betriebsdaten, die ohne angemeldeten Nutzer gebraucht
     werden (Mail-Adressen der Benutzer, Mail-Queue, API-Keys der
     KI-Anbieter).
  2. *Tresor* (asymmetrisch, libsodium): **alle fachlichen Daten** (Belege,
     Bilder, OCR-Texte, Beträge, Lieferanten, IBANs, Buchungen,
     Einreicher-Daten). Schreiben geht ohne Geheimnis (Sealed Box an den
     Tresor-Public-Key) – deshalb kann die öffentliche Einreichung
     verschlüsseln. **Lesen geht nur in einer angemeldeten Session**, in der
     der Tresor-Private-Key entsperrt ist.
- Der Tresor-Private-Key liegt **nie** im Klartext auf Platte oder in der
  DB: gewrappt je Benutzer (Argon2id aus dem Passwort) und als
  Wiederherstellungsschlüssel beim Verein (Papier). In der PHP-Session nur
  verschlüsselt mit einem Schlüssel, der ausschließlich im Cookie liegt.
- Folge: **Alles, was entschlüsseln muss (KI-Auslesen, Abgleich,
  Auswertung, Export), läuft in einer Nutzer-Session** (browsergetriebene
  Schrittkette), nie im Cron. Der Cron macht nur Mail-Versand und Aufräumen.
- Keine fachlichen Klartextdaten in Logs, Mails, Fehlermeldungen,
  Dateinamen oder URLs. Blob-Dateinamen sind zufällige IDs.
- Passwörter nie loggen (Error-Handler loggt nur Klasse, Message, Route,
  Ort – wie im Vereinskalender). `zend.exception_ignore_args=On`.
- CSRF-Token auf allen Schreibrouten; Session-Cookie `httponly`,
  `samesite=Lax`, `secure` aus dem Request-Schema abgeleitet.
- CSP wie im Vereinskalender: `script-src 'self'` **ohne** `unsafe-inline`
  (kein Inline-JS, keine `on*`-Attribute, htmx ohne `hx-on`), keine
  externen Hosts. Alle JS-Bibliotheken (htmx, pdf.js, ggf. OpenCV.js) werden
  vendored ausgeliefert – unter `public/js/vendor/`, mit Version, Lizenz und
  Prüfsumme in dessen `README.md`. htmx wird über
  `<meta name="htmx-config">` konfiguriert, nicht per Skript.
- Rechte werden **serverseitig pro Aktion** geprüft (`Permission`-Enum),
  nie nur in der UI ausgeblendet.

## 5. Datenhaltung

- Fachliche Tabellen tragen je Zeile einen versiegelten Datenschlüssel
  (`dek_sealed`) und verschlüsselte Spalten (`*_enc`, AEAD mit AAD =
  Tabelle|ID|Spalte). Strukturfelder (IDs, FKs, Status, Datumsfelder,
  Kategorie-ID) bleiben Klartext, damit SQL filtern/sortieren kann.
  **Beträge sind verschlüsselt** – Summen/Auswertungen rechnet PHP nach dem
  Entschlüsseln (Datenmengen eines Vereins: wenige Tausend Datensätze/Jahr).
- Suche/Duplikaterkennung auf verschlüsselten Werten über **Blind Indexes**
  (HMAC, Schlüssel aus dem Tresor abgeleitet → nur in der Session
  berechenbar).
- Dateien (Originalbilder, erzeugte PDFs, Kontoauszugs-Dateien) sind
  **Blobs**, verschlüsselt per libsodium secretstream. Storage-Backend
  wählbar: `db` (Chunks in `blob_chunk`) oder `fs` (`shared/var/blobs/`).
  Umstellung per Schrittkette in beide Richtungen.
- Geldbeträge immer als Integer-Cent, Währung separat. Nie `float`.
- **Kein Event Sourcing** (bewusst anders als der Vereinskalender, siehe
  E-05). Stattdessen: append-only **Audit-Log** mit Hash-Kette
  (manipulationserkennend) + Festschreibung geprüfter Belege.
- **Original bleibt immer erhalten**: zu jedem Beleg wird das unveränderte
  Original (Foto/PDF) neben der aufbereiteten Fassung gespeichert.
- Zeitzone durchgängig `Europe/Berlin` (im Bootstrap gesetzt).
  Geschäftsjahr = Kalenderjahr (E-16).
- Steuerliche Sphären sind per Setting ausgeblendet (E-15); Code prüft
  `sphaeren_aktiv`, bevor er Sphären anzeigt, abfragt oder auswertet.

## 6. KI-Anbindung (Details: 03-erfassung-und-ki.md)

- Genau **eine** Schnittstelle: OpenAI-kompatibles
  `POST {base_url}/chat/completions`. Anbieter-Profile mit Fähigkeits-Flags
  (`vision`, `json_schema`, `max_images`). Reihenfolge (E-08): **OpenAI =
  Standard**, dann Anthropic (OpenAI-kompatibler Endpunkt), dann
  llama.cpp-Server.
- KI-Ergebnisse sind **Vorschläge**. Jede Zuordnung ist nachvollziehbar
  (Prompt-Version, Modell, Rohantwort verschlüsselt gespeichert) und wird vor
  der Festschreibung von einem Menschen bestätigt.
- Antworten werden gegen ein JSON-Schema validiert; bei Fehlern ein
  Reparatur-Versuch, dann Status „KI fehlgeschlagen" – nie raten.
- Tests laufen **ohne** echte KI (`FakeLlmClient` mit aufgezeichneten
  Antworten unter `tests/fixtures/llm/`).

## 6a. Optionaler Worker (Details: 07-worker.md)

- Alles läuft **ohne Worker** auf dem Webhoster. Der Worker ist ein im
  Admin aktivierbares Zusatzmodul (Docker, z. B. Raspberry Pi 4), das per
  **ausgehendem Polling** Jobs abholt: OCR/PDF-A, PDF-Rasterung,
  KI-Auslesen ohne Zeitlimit.
- Jeder Verarbeitungsschritt hat einen **Executor** (`session`, `browser`,
  `worker`). Fachlogik liegt in `app/src/Service/Processing/` **ohne**
  Abhängigkeit zu Http/Session/Repository, damit sie in Web-App und Worker
  identisch läuft. Wer neue Verarbeitungslogik schreibt, hält sich daran.
- Der Worker bekommt nie den Tresor-Schlüssel, nur je Dokument versiegelte
  DEKs (`worker_grant`), die nach der Verarbeitung gelöscht werden.
  Ergebnisse verschlüsselt er an den Tresor-Public-Key.
- Fällt der Worker aus, greift nach einer Frist automatisch der
  Browser-/Session-Weg.

## 7. Übernahme aus dem Vereinskalender

Folgende Teile werden aus `MirkoSc/vereinskalender` übernommen und nur
umbenannt/angepasst, nicht neu erfunden: `Http/*` (Kernel, Router, Request,
Response, Session), `Config/*`, `Database/ConnectionFactory`,
`Service/Migration`, `Service/Update`, `Service/Backup` (erweitert um Blobs),
`Service/MaintenanceMode`, `Service/RateLimiter`, `Support/FileLogger`,
`Installer/*`, `bin/setup.template.php`, `bin/build_setup.php`,
`bin/migrate.php`, Docker-Umgebung, CI- und Release-Workflow, Shim-Logik
(`ReleaseSwitcher::SHIM` inkl. `ShimContentTest`), CSP-Compliance-Test.
Nicht übernommen: Event-Store, Kalender-Domäne, Push, PWA-Offline, ICS.

## 8. Entwicklungs-Konventionen

- Lokal: `docker compose up` (PHP 8.5 + MariaDB, produktionsnah:
  `disable_functions=exec,…`, `max_execution_time=30`). Befehle wie im
  Vereinskalender-README.
- Tests: PHPUnit (`failOnDeprecation`, `failOnWarning`), JS-Logik mit
  `node --test tests/js`. Pflicht-Tests je Bereich stehen in der jeweiligen
  Spec unter „Pflicht-Tests".
- SQL nur in Repositories, nur Prepared Statements, kein ORM/Query-Builder.
- Jede Schema-Änderung = neue nummerierte Migration `NNN_name.sql`; alte
  nie ändern; Migrationen eine Version rückwärtskompatibel.
- UI-Texte Deutsch; Code, Kommentare, Commits Englisch.
- Fremdbibliotheken vor Aufnahme prüfen: reines PHP, PHP-8.5-kompatibel,
  aktiv gepflegt, Lizenz GPL-kompatibel. Ergebnis im PR notieren.
- Architektur- oder Datenmodell-Änderungen aktualisieren die betroffene
  Spec (und ggf. diese Datei) **im selben PR**.

## 9. Arbeitsweise mit Claude Code

- **Ein Issue pro Session.** Branch `issue-<nr>-<kurz>`, PR mit
  `Closes #<nr>`. Nie direkt auf `main`.
- Zu Beginn: Issue lesen (`gh issue view <nr>`), nur die dort verlinkten
  Spec-Abschnitte lesen, kurzen Plan nennen. Bei fachlicher Unklarheit
  **fragen statt annehmen**.
- Vor dem PR: Tests grün, `docker compose up` läuft, betroffene Seiten im
  Browser geprüft (bzw. Prüfliste im PR, wenn nicht automatisierbar).
- Keine „Nebenbei-Refactorings" außerhalb des Issues – dafür neues Issue.
