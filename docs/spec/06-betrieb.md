# 06 – Betrieb: Installer, Update, Backup, Mail, Jobs

## 1. Installer / Updater / Release

Übernahme aus `MirkoSc/vereinskalender` (dortige CLAUDE.md Abschnitte 2, 9,
10 gelten sinngemäß): `setup.php` (Nextcloud-Stil) → Release laden, SHA-256
prüfen, entpacken, Shim + `shared/` anlegen → `/install`.

Erweiterungen im `/install`-Flow:
1. DB-Zugangsdaten + Verbindungstest (wie gehabt)
2. **Server-Schlüssel** erzeugen → `config.php`
3. Erster Admin: E-Mail, Name, Passwort
4. **Tresor erzeugen** + Wiederherstellungsschlüssel anzeigen/drucken +
   Bestätigung durch Eingabe der letzten Gruppe
5. Mail-Einstellungen (SMTP) + Testmail – überspringbar
6. „Frische Installation" oder „Backup einspielen"

Update-Schrittkette, Kanäle stable/beta, Pre-Releases, Rollback,
Wartungsmodus inkl. Banner: unverändert übernommen. Der Updater speichert
die `checksums.txt` des Releases für den Code-Integritätscheck.

## 2. Backup & Restore

- Backup-ZIP = DB-Dump (reines PHP, übernommen) + bei Storage `fs` der
  Inhalt von `var/blobs/` + `manifest.json`. **Fachliche Daten sind darin
  nur als Chiffrat enthalten** → Backup darf heruntergeladen/extern
  abgelegt werden. `config.php` (Server-Schlüssel) kommt nur mit, wenn der
  Admin das ausdrücklich wählt (Hinweis: dann sind Betriebsdaten lesbar).
- Große Backups als Schrittkette (Blobs in Teilen), Rotation 10.
- Restore im Installer; danach Anmeldung mit bisherigem Konto (Grants
  liegen in der DB) oder per Wiederherstellungsschlüssel.

## 3. Mail

- Versand per **SMTP** (reine PHP-Bibliothek, z. B. PHPMailer – Ports 465/
  587, TLS), Zugangsdaten im Admin, Passwort mit Server-Schlüssel
  verschlüsselt. Fallback PHP `mail()` wählbar.
- Alle Mails über `mail_queue`: sofortiger Versuch im Request, bei Fehler
  Retry im Cron (exponentiell, max. 5). Sicherheitsrelevante Mails (2FA-
  Code, Reset) sofort, Fehler wird dem Nutzer angezeigt.
- Mail-Vorlagen (Deutsch) als Views; **keine fachlichen Inhalte** (keine
  Beträge, Lieferanten, IBANs) in Mails – nur Hinweise mit Link.
- Absender, Reply-To, Vereinsname als Settings. SPF/DKIM beim Hoster
  einrichten (Doku in `docs/betrieb.md`).
- Admin: Testmail senden, Queue einsehen (Empfänger maskiert).

## 4. Jobs und Session-Worker

Weil Entschlüsseln nur in einer Nutzer-Session möglich ist (01, Abschnitt 2):

- **Server-Jobs mit Tresor-Bedarf** (KI-Auslesen, Lieferant auflösen,
  Abgleich, Import-Schritte, Export) werden vom **Browser eines angemeldeten
  Nutzers** angestoßen: Solange ein Nutzer mit passenden Rechten die App
  offen hat, ruft ein kleiner JS-Worker `POST /api/jobs/step` auf (je Aufruf
  **ein** Jobschritt, Zeitbudget ~20 s), zeigt Fortschritt in der Kopfzeile
  („3 Belege in Verarbeitung") und pausiert, wenn der Tab im Hintergrund ist
  (Page Visibility API, dann langsameres Intervall).
- Sperre per `locked_until` verhindert Doppelverarbeitung bei mehreren
  offenen Tabs/Nutzern.
- **Browser-Jobs** (PDF rendern mit pdf.js): Worker holt Aufgabe, rendert,
  lädt Seitenbilder hoch.
- **Worker-Jobs** (optionales Modul, 07-worker.md): werden nur angelegt,
  wenn das Modul aktiv und der Worker online ist; sonst bzw. nach der
  Fallback-Frist dieselben Jobs mit Executor `session`/`browser`.
  Session-Worker und Worker nutzen dieselbe Sperrlogik (`locked_by`,
  `locked_until`).
- **Cron** (alle 5–15 min per Hoster-Kontrollpanel, Token wie im
  Vereinskalender): nur Mail-Queue, Aufräumen (Upload-Chunks, abgelaufene
  Tokens/Codes, Rate-Limit-Einträge, IP-Hashes), Erinnerungs-Mails
  („5 Belege warten auf Prüfung" – ohne Details).
- Langlaufende KI-Aufrufe: curl-Timeout aus dem Anbieter-Profil. Auf
  Linux zählt Warten auf Netzwerk-I/O nicht in `max_execution_time`, aber
  der Webserver kann Requests trotzdem kappen – **Grenze im Hosting-Check
  messen** und Timeout darunter setzen.

## 5. Hosting-Check (Meilenstein M0)

Eigenständiges Skript `tools/hosting-check.php`, einmalig per FTP in ein
Testverzeichnis geladen, mit Token geschützt, danach gelöscht. Prüft und
zeigt als Tabelle (und als JSON zum Kopieren):

- PHP-Version, `memory_limit`, `max_execution_time`, `upload_max_filesize`,
  `post_max_size`, `max_input_time`, `disable_functions`
- Erweiterungen: sodium (+ `sodium_crypto_pwhash` Laufzeit mit
  INTERACTIVE/MODERATE), gd (JPEG/PNG/WebP-Support), imagick (vorhanden?),
  zip, curl, openssl, mbstring, intl, fileinfo, pdo_mysql, iconv
- `password_hash` mit `PASSWORD_ARGON2ID` verfügbar?
- MySQL/MariaDB-Version, `max_allowed_packet`, Zeichensatz,
  Standard-Speicher-Engine, Tabelle anlegen (Installer), JSON-Spalte mit
  `JSON_EXTRACT`, `LONGBLOB` schreiben und lesen
- Ausgehende HTTPS-Verbindung zu `api.openai.com`, `api.anthropic.com` und
  `api.github.com` (nur Erreichbarkeit, kein Key nötig) und optional zum
  eigenen llama.cpp-Proxy (`llm_url=…`)
- `rename()` von Verzeichnissen (inkl. Rückweg), `flock()`, Schreibrechte
  oberhalb des DocumentRoot; Prüfdateien werden restlos aufgeräumt
- Krypto-Rundläufe, die das Tresor-Modell belegen: Sealed Box und
  secretstream
- Minimales Cron-Intervall: im Kontrollpanel nachsehen und notieren

**Aufruf.** `HC_TOKEN` im Skript setzen oder `VEREINSBELEGE_HC_TOKEN` in der
Umgebung; ohne Token antwortet das Skript mit 503, bei falschem Token mit 403
(Vergleich per `hash_equals`). Ausgabe: HTML-Tabelle (mobil als Karten, ohne
JavaScript) bzw. `format=json`; auf der Kommandozeile Text bzw.
`--format=json`. Parameter gehen auch per POST, damit Zugangsdaten nicht in
der URL stehen. Der Exit-Code ist 1, sobald ein Prüfpunkt `fail` ist.

**Lang laufende Messungen sind eigene Aufrufe**, sonst sprengt der Check
selbst die Grenzen, die er messen soll:

| Aufruf | Messung |
|---|---|
| `?test=longrun&seconds=30\|60\|90\|120\|180` | Wartet serverseitig; ab wann bricht der Request ab? |
| `?test=longrun&mode=remote&seconds=N&url=…` | Wartet auf einen eigenen Verzögerungs-Endpunkt (kein Fremddienst voreingestellt) |
| `?test=stream&mb=200&seconds=60` | Kommt eine langsame große Antwort an? |
| `?test=smtp&smtp_host=…&smtp_port=465&smtp_secure=implicit\|starttls\|none` | Verbindung, optional Anmeldung, optional Testmail (nur mit `mail_from` **und** `mail_to`) |

Geheimnisse (Token, Passwörter, Schlüssel) werden in jeder Ausgabe durch
`***` ersetzt; das SMTP-Protokoll zeigt statt der Anmeldedaten Platzhalter.

**Pflicht-Tests:** `tests/hosting-check-test.php` – Token-Vergleich (leere
Erwartung greift nie), `hc_parse_bytes`/`hc_format_bytes`, Bewertung von
Mindestwerten, Zusammenfassung und schlechtester Status, Redaktion von
Geheimnissen, Abdeckung der oben gelisteten Prüfpunkte, HTML-Ausgabe ohne
Skript und mit maskierten Sonderzeichen, restloses Aufräumen der
Dateisystem-Proben, Abbruch ohne SMTP-Host bzw. ohne Langlauf-URL. Die
Datenbank-Prüfungen laufen gegen einen echten Server, wenn
`HC_TEST_DB_HOST`/`_NAME`/`_USER`/`_PASS` gesetzt sind.

Ergebnis wird in `docs/hosting-befunde.md` eingetragen; Abweichungen von
den Annahmen dieser Specs werden als Issues angelegt.
