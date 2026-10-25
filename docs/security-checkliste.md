# Security-Checkliste

Wiederholbare Prüfung der Anwendung vor dem Produktivbetrieb und danach bei
jeder größeren Änderung (Issue #78, M13-1). Angelehnt an **OWASP ASVS 4.0
Level 2** für Anmeldung (V2), Sitzung (V3), Zugriffskontrolle (V4),
Ein-/Ausgabe (V5), gespeicherte Kryptografie (V6), Fehler und Logs (V7),
Dateien (V12), API (V13) und Konfiguration (V14). Grundlage sind
`docs/spec/01-sicherheit.md` §1, §3, §4, §5 und `CLAUDE.md` §4.

## So wird geprüft

- **Am Code, nicht an der Spec.** Jede Zeile wird am aktuellen Stand von
  `main` nachvollzogen: Route → Controller → Service → Repository. Die
  Fundstelle nennt Datei und Klasse/Methode (Zeilennummern veralten, daher
  bewusst meist ohne).
- **Ergebnis je Zeile:** ✓ erfüllt · ✗ nicht erfüllt (Befund, siehe unten)
  · n. a. nicht anwendbar · ⚠ Restrisiko bewusst akzeptiert (Spec/E-Nummer).
- **Neue Funktionen:** eine neue Route verlangt einen Eintrag in
  `tests/Http/routen-zugriff.txt` (der Test schlägt sonst fehl) – das ist
  der Anlass, die passenden Abschnitte dieser Liste erneut durchzugehen.
- **Teilbereiche parallel:** die Abschnitte A–J sind unabhängig und lassen
  sich getrennt (auch von mehreren Personen/Agents) prüfen.
- Gefundenes: kleine, sichere Fixes direkt mit Test; größere als Issue mit
  Schweregrad (kritisch/hoch/mittel/niedrig), Szenario und Fix-Vorschlag.

**Letzter Durchlauf:** 2026-10-25, Stand `main` 4bdd693 + PR zu #78.
Spalte „Ergebnis" = Stand **nach** den Fixes dieses PRs; „(#78)" markiert
eine in diesem PR behobene Zeile.

## Automatisierte Prüfungen (laufen in CI)

| Test | Prüft |
|---|---|
| `tests/Http/RoutePermissionMatrixTest` | jede Route × anonym × sechs Systemrollen gegen ihre Deklaration; Stichproben gegen die Rechte-Tabelle; Allowlist öffentlicher Routen; `/admin` nur mit `admin.*`; **jede** Deklaration gegen `routen-zugriff.txt` (#78) |
| `tests/Http/CspComplianceTest` | CSP ohne `unsafe-inline`/`unsafe-eval`, kein externer Host, kein Inline-JS/`on*`/`hx-on` in Views, Security-Header vollständig (#78: XFO, Permissions-Policy, COOP) |
| `tests/Http/SessionOptionsTest` | `cache_limiter=nocache`, kein `use_strict_mode` (#78) |
| `tests/Http/KernelErrorLogTest`, `ExceptionArgsTest` | Fehlerseite ohne Trace, Log ohne Argumente |
| `tests/Support/NoShellExecutionTest` | kein `exec` & Co. außerhalb von `worker/src/Prozess.php` |
| `tests/Service/Account/PasswordHasherTest` | Dummy-Hash so teuer wie ein echter (#78) |
| Flow-Tests unter `tests/Integration/*FlowTest` | CSRF, Scopes und Rechte je Bereich |

## A – Anmeldung (ASVS V2)

| ID | Prüfung | Ergebnis | Fundstelle |
|---|---|---|---|
| A-01 | Passwort-Hash Argon2id (Fallback bcrypt), Rehash beim Login | ✓ | `Service/Account/PasswordHasher`, `LoginService::attempt()` |
| A-02 | Passwortregel ≥ 12 Zeichen + Liste häufiger Passwörter, keine Zusammensetzungsregeln | ✓ | `PasswordPolicy`, `app/data/haeufige-passwoerter.txt` |
| A-03 | Wortgleiche Meldung für unbekannt/falsch/gesperrt/abgelaufen | ✓ | `LoginService`, `App/AuthController` |
| A-04 | Keine User-Enumeration über die Laufzeit (Schein-`password_verify`) | ✓ (#78) | `PasswordHasher::DUMMY_*` – vorher je Request neu gehasht = doppelte Laufzeit |
| A-05 | Rate-Limit je IP (20) und Konto (10), 15 min, nur Fehlversuche | ✓ / ✗ parallel | `RateLimiter`; Prüfen und Zählen nicht atomar → Befund N-09 |
| A-06 | Status und Ablaufdatum bei Login **und** jedem Request | ✓ | `User::mayLogIn()`, `LoginGuard` |
| A-07 | Kein Open Redirect über `weiter` | ✓ | `AuthController` (nur relative Pfade) |
| A-08 | Tresor erst nach dem zweiten Faktor (PendingLogin, `__Host-2fa`) | ✓ | `PendingLogin`, `Http/Cookie` |
| A-09 | TOTP RFC 6238, ±1 Schritt, `hash_equals` | ✓ | `Totp::matchingStep()` |
| A-10 | TOTP-Code nur einmal je Zeitschritt (ASVS 2.8.4), auch der Code der Einrichtung | ✓ (#78) | `MfaService::verifyTotp()`, `MfaEnrollment::confirmTotp()`, `MfaTotpRepository::claimStep()`, Migration 045 |
| A-11 | E-Mail-Code: 6 Ziffern, nur HMAC, 10 min, 5 Versuche, atomar verbraucht | ✓ (#78) | `MfaService::verifyEmailCode()`, `MfaEmailCodeRepository::consume()` |
| A-12 | Backup-Codes: 10, gehasht, einmalig, atomar verbraucht | ✓ (#78) | `MfaBackupCodeRepository::markUsed()` (`AND used_at IS NULL`, `rowCount`) |
| A-13 | 2FA-Rate-Limit (20 je IP, 8 je Konto) | ✓ | `MfaService::isBlocked()` |
| A-14 | „Gerät merken": 32 Byte, nur Hash, Ablauf, widerrufbar | ✓ | `MfaService`, `TrustedDeviceRepository`, `/app/sicherheit` |
| A-15 | Erzwungene 2FA-Einrichtung für alle Seiten unter `/app`, `/admin` | ✗ niedrig | `LoginGuard`: JSON-Routen ausgenommen → N-10 |
| A-16 | Zweiten Faktor ändern / Backup-Codes neu nur nach erneuter Anmeldung (ASVS 3.7.1) | ✗ niedrig | `App/SecurityController` → N-08 |
| A-17 | Reset-Token 32 Byte, nur HMAC, 30 min, einmalig, ersetzt alte | ✓ | `PasswordReset`, `AuthTokenRepository` |
| A-18 | Reset beendet alle Sitzungen (Epoche) | ✓ | `PasswordReset::complete()` |
| A-19 | Reset/Einladung: gleiche Antwort für alle Fälle, Rate-Limit 10/IP, 3/Adresse | ✓ | `PasswordController`, `Invitation` |
| A-20 | Seite mit Token in der URL sendet keinen Referer | ✓ (#78) | `<meta name="referrer">` in `layout.php`; `.htaccess` setzt `Referrer-Policy` nur noch per `setifempty` (vorher überschrieb `Header always set` unter FastCGI den PHP-Header) |
| A-21 | Link-Basis in Mails gegen Host-Header-Injection | ✓ | `Service/Mail/PublicUrl` |
| A-22 | Passwortwechsel: altes Passwort, Rate-Limit, Umschlüsseln, andere Sitzungen enden | ✓ | `PasswordChange`, `Session::adoptEpoch()` |

## B – Sitzung (ASVS V3)

| ID | Prüfung | Ergebnis | Fundstelle |
|---|---|---|---|
| B-01 | Session-Cookie `httponly`, `samesite=Lax`, `secure` aus dem Schema | ✓ | `Http/Session::start()` |
| B-02 | Kein Cache für Sitzungsseiten; `use_strict_mode` bewusst aus (Regenerations-Race mit Pollern) | ✓ (#78) | `Session::OPTIONEN` |
| B-03 | Tresor-, 2FA-, Geräte-Cookie mit `__Host-`-Präfix | ✓ | `Http/Cookie` |
| B-04 | Session-Cookie mit `__Host-`-Namen | ✗ Härtung | heißt `PHPSESSID` → N-14 |
| B-05 | Neue Session-ID bei Login, Rechte-/Passwortwechsel; CSRF-Token verworfen | ✓ | `Session::login()`, `adoptEpoch()` |
| B-06 | Idle 30 min / absolut 12 h, Settings mit sicherem Rückfall | ✓ | `SessionTimeouts`, `Session::isExpired()` |
| B-07 | Idle-Timeout greift auch bei offenem Tab (Hintergrund-Polling verlängert nicht) | ✓ (#78) | `LoginGuard::HINTERGRUND_HEADER`, `public/js/jobs.js`, `rasterung.js` – Folge: eine lange Job-Kette ohne Klick endet nach 30 min, weiter nach erneuter Anmeldung |
| B-08 | Sperren/Ablauf/Reset beenden laufende Sitzungen sofort | ✓ | `LoginGuard` (Epoche) |
| B-09 | Logout nur per POST + CSRF, löscht Sitzung und Tresor-Cookie | ✓ | `AuthController::logout()` |
| B-10 | CSRF-Token auf allen schreibenden Session-Routen | ✓ | je Controller `checkCsrf()`; Ausnahmen gewollt: `/einreichen*` (Formular-Token), `/api/worker/*` (HMAC). Ein zentraler Test über alle POST-Routen fehlt → N-15 |
| B-11 | Nur HTTPS (Umleitung HTTP → HTTPS) | ✓ (#247) | Shim `ReleaseSwitcher::SHIM`: GET/HEAD über HTTP → 301 auf HTTPS; ausgenommen `/cron`, POST, `localhost`; Proxy-Erkennung nur zum Abschalten der Umleitung, Notschalter `shared/https_umleitung_aus.flag` (N-11); HSTS vorhanden |
| B-12 | Tresor-Schlüssel nur geteilt (Session-Chiffrat + Cookie-Schlüssel) | ✓ | `SessionVault` |

## C – Zugriffskontrolle (ASVS V4)

| ID | Prüfung | Ergebnis | Fundstelle |
|---|---|---|---|
| C-01 | Jede Route deklariert `Zugriff`, Guard aus derselben Deklaration | ✓ | `app/src/routes.php`, `LoginGuard::pruefe()` |
| C-02 | Alle Deklarationen gegen 01 §4 geprüft und festgeschrieben | ✓ (#78) | `tests/Http/routen-zugriff.txt` (292 Routen inkl. Budget #92) |
| C-03 | `/admin/*` nur mit `admin.*` | ✓ | `RoutePermissionMatrixTest::testEveryAdminRouteNeedsAnAdminRight` |
| C-04 | Keine zustandsändernden GETs | ✓ | GET-Downloads schreiben nur Audit-Zeilen |
| C-05 | Externe Rollen ausschließlich lesend | ✓ (#78) | `ExportController::darfMarkieren()` – vorher konnten Kassenprüfer/Steuerberater Export-Läufe vereinsweit markieren/zurücksetzen. Geprüft über `istExtern()`, nicht über ein eigenes Recht → N-21 |
| C-06 | Posteingang/Prüfung/Festschreibung: Scope je ID in SQL, fremde ID = 404 | ✓ | `DocumentRepository::inbox()/inboxItem()` |
| C-07 | Blob gehört zum Dokument (`/datei/{blob}`) | ✓ | `Inbox/Posteingang`, `Invoice/Pruefung` |
| C-08 | Buchungen, Abgleich, Erstattung, Zahllauf, GiroCode im Scope | ✓ | `Bank/Buchungen`, `Abgleich`, `Erstattungen`, `PaymentRunRepository` |
| C-09 | Auswertungen: Scope in SQL + Nachfilter | ✓ | `Report/Auswertungsdaten`, `InvoiceRepository` |
| C-10 | Auswertungs-Session-Cache an Benutzer + Zugriffsbereich gebunden, Rechteänderung wirkt | ✓ | `Report/AuswertungsCache` (Scope in der Eintrags-ID und im AEAD) |
| C-11 | Volltext-Session-Index an Benutzer + Scope, Treffer neu gefiltert | ✓ | `Suche/Volltextsuche` |
| C-12 | ZIP-, DATEV-, Kassenprüfungs-, Budget-Export im Scope | ✓ | `InvoiceRepository`, `DatevExport`, `Report/Kassenpruefung`, `BudgetController::csv()` |
| C-13 | Budget lesen: Kostenstellen-Scope für Soll und Ist | ✓ | `BudgetRepository::forYear()`, `Report/Budgets::vergleich()` |
| C-14 | Budget schreiben/löschen nur im eigenen Scope | ✓ (#247) | `Report/Budgets::speichern()/loeschen()` mit Kostenstellen-Scope von `bank.book` ∩ `report.view`; Löschen per SQL (`BudgetRepository::delete()`), fremde ID = 404 (N-07); `BudgetFlowTest` |
| C-15 | Zeitraum-Scope gilt für alle Rechte, auch `audit.view` | ✗ niedrig | `AuditController` ohne Zugriffsbereich → N-06 |
| C-16 | Kein Mass Assignment (Kostenstelle, Status) | ✓ | `Posteingang`, `InterneErfassung` |
| C-17 | `/api/jobs/step`: Recht je Job-Typ | ✓ | `Job/JobRunner` |

## D – Öffentliche Einreichung und Uploads (ASVS V12/V13)

| ID | Prüfung | Ergebnis | Fundstelle |
|---|---|---|---|
| D-01 | Formular-Token: HMAC, `hash_equals`, 24 h, eigener abgeleiteter Schlüssel | ✓ | `Submission/FormToken` |
| D-02 | Ein Token = höchstens eine Einreichung | ✓ | `SubmissionService` (UNIQUE `form_hash`) |
| D-03 | Proof-of-Work an das Token gebunden, Fehlen = Ablehnung | ✓ | `Submission/ProofOfWork`, `Spamschutz` |
| D-04 | Honeypot + Mindest-Ausfülldauer, Treffer zählt gegen IP-Limit | ✓ | `Spamschutz::pruefeAbsenden()` |
| D-05 | IP nur aus `REMOTE_ADDR`, kein `X-Forwarded-For` | ✓ | `Http/Request` |
| D-06 | Rate-Limit je IP und global, Submit und Upload getrennt | ✓ | `Spamschutz` |
| D-07 | Schutz vor Platten-/DB-Erschöpfung (Byte-Budget, IPv6 /64) | ✗ mittel | → N-04 |
| D-08 | Pause gilt für alle Endpunkte | ✓ (#78) | `EinreichungUploadController::finish()` prüft `Spamschutz::pruefePause()` |
| D-09 | Größe je Chunk und gesamt serverseitig, Chunk-Index begrenzt | ✓ | `Upload/UploadService` |
| D-10 | Upload-ID 128 Bit, Regex vor Pfadnutzung, kein Path Traversal | ✓ | `UploadService` |
| D-11 | Dateiname nie auf Platte, nur verschlüsselt in BlobMeta | ✓ | `EinreichungUploadController::dateiname()` |
| D-12 | Typ allein per Magic Bytes, Auslieferung mit Allowlist-MIME + `nosniff` | ✓ / ⚠ | `Upload/MagicBytes`; TOCTOU zwischen Prüfung und Speichern → N-13 |
| D-13 | E-Rechnung-XML: kein DOCTYPE in **jeder** Kodierung, `LIBXML_NONET`, kein `NOENT` | ✓ (#78) | `ERechnungLeser::doctypeVorWurzel()` – vorher UTF-16/UTF-7 an der Bytesuche vorbei (Weg: XML-Anhang einer öffentlichen PDF) |
| D-14 | GD: Pixel-Obergrenze vor dem Dekodieren | ✓ | `PngZuJpeg`, `SchwarzweissFallback`, `PdfRasterung` |
| D-15 | Klartext nur als Upload-Chunks in `var/tmp`, nach `finish` bzw. 24 h gelöscht | ✓ | `UploadStore`, `Cron/UploadCleanupTask` |
| D-16 | Bestätigungsmail: nur Referenz, Empfänger validiert, kein CRLF | ✓ | `Mailer`, `SubmissionService` |

## E – Ausgabe, Kodierung, Parser (ASVS V5)

| ID | Prüfung | Ergebnis | Fundstelle |
|---|---|---|---|
| E-01 | `e()` = `htmlspecialchars(ENT_QUOTES, UTF-8)`, alle `<?=` mit Daten laufen hindurch | ✓ | `View/helpers.php`; Tokenizer-Durchlauf aller Echo-Tags in `app/views` |
| E-02 | `href`/`action` aus Server-Pfaden, kein `hx-*`/`data-*`/JSON mit Fremddaten unescaped | ✓ | Views; `htmx-config` mit `selfRequestsOnly` |
| E-03 | Kein DOM-XSS im eigenen JS | ✓ | nur `innerHTML = ''` |
| E-04 | Rechtstexte: alles vor dem Markdown escaped, Links nur http(s)/mailto/tel/eigene Pfade | ✓ | `View/EinfachesMarkdown` |
| E-05 | CSV-Formelschutz für Textzellen (`= + - @ TAB CR`) | ✓ | `Export/Csv::text()` – Kassenprüfung, `index.csv`, Budget |
| E-06 | DATEV: Belegfeld 1 ohne Formel | ✓ (#247) | `DatevBuchungsstapel::belegfeld()` entfernt führendes `+`/`-` nach dem Kürzen; `=`/`@` sind ohnehin nicht erlaubt (N-12) |
| E-07 | `Content-Disposition`-Dateinamen ohne Fremddaten | ✓ | alle Download-Controller |
| E-08 | Mail: CR/LF abgewiesen, RFC 2047, Anzeigename korrekt gequotet | ✓ (#78) | `Mail/MailMessage::displayName()` |
| E-09 | ZIP-Export/Nextcloud: kein Zip-Slip über Daten in Pfaden | ✓ | `Export/Dateiname`, `PfadMuster`, `NextcloudZiel` |
| E-10 | Archiv-Import: Zip-Slip, Zip-Bombe, Eintragszahl | ✓ | `Archiv/ZipLeser` |
| E-11 | Kein `unserialize` fremder Daten, kein `eval`/`$$`/`/e`/`include $var` | ✓ | einziges `unserialize`: `Backup/ZipAnhang` (eigene Datei, `allowed_classes`) |
| E-12 | Dynamische Regex nur mit `preg_quote`/Whitelist (MT940, CSV) | ✓ | `Bank/Mt940Parser`, `CsvDezimaltrenner` |

## F – Kryptografie (ASVS V6)

| ID | Prüfung | Ergebnis | Fundstelle |
|---|---|---|---|
| F-01 | XChaCha20-Poly1305, Nonce je Verschlüsselung zufällig | ✓ | `Crypto/FieldCipher` |
| F-02 | AAD = tabelle\|id\|spalte an allen Aufrufstellen (kein Tausch zwischen Zeilen/Spalten) | ✓ | `Crypto/FieldContext` |
| F-03 | Versionsbyte an jedem Format, unbekannt = Fehler | ✓ | `FieldCipher`, `ServerCrypto`, `UserKey`, `SessionVault`, `BlobStreamReader` |
| F-04 | Sealed Box an VK_pub mit Generation | ✓ | `Tresor/Vault` |
| F-05 | Blobs per secretstream, `TAG_FINAL` Pflicht, Abschneiden erkannt | ✓ | `Storage/BlobStreamReader` |
| F-06 | Blind Index: HMAC mit Zweck-Trennung, Schlüssel abgeleitet | ✓ | `Crypto/BlindIndex`, `Vault` |
| F-07 | Argon2id MODERATE, Parameter je Benutzer, Hash und KEK-Salt getrennt | ✓ | `Crypto/KdfParameters`, `UserKey` |
| F-08 | `random_bytes` für alles Sicherheitsrelevante, `hash_equals` für Vergleiche | ✓ | Volltext-Suche; `mt_rand` nur für Backoff-Jitter im Worker |
| F-09 | Wiederherstellungsschlüssel mit Prüfsumme, Abgleich gegen `vault.public_key` | ✓ | `Crypto/RecoveryKey` |
| F-10 | Echtheit von `vault.public_key` gegen etwas außerhalb der DB geprüft | ✗ mittel | `VaultRepository::current()` → N-01 |
| F-11 | Rotation: Recht, CSRF, idempotent, Abbruch nur vor Bestätigung, Abschluss unter Sperre | ✓ | `Tresor/Schluesselrotation`, `VaultRotationController` |
| F-12 | Alte DEKs/Backups nach Rotation mit altem Schlüssel lesbar | ⚠ | 01 §2 „Rest-Exposure", E-32 |

## G – Worker und IMAP

| ID | Prüfung | Ergebnis | Fundstelle |
|---|---|---|---|
| G-01 | HMAC über Methode, Pfad, Zeit, Nonce, SHA-256(Body); Query abgelehnt | ✓ | `Worker/WorkerProtokoll`, `WorkerAuth` |
| G-02 | ±5 min, Nonce per PK atomar, erst nach gültiger Signatur gespeichert | ✓ | `WorkerAuth`, `WorkerNonceRepository` |
| G-03 | Kopplungscode 40 Bit, einmalig, 15 min, Rate-Limit je IP und gesamt | ✓ | `WorkerKopplung` |
| G-04 | Entkoppeln: Secret, Grants, Uploads weg, Jobs frei | ✓ | `WorkerRepository`, `WorkerFreigaben` |
| G-05 | Blob nur mit Grant + gehaltenem Job desselben Dokuments | ✓ | `WorkerGrantRepository` |
| G-06 | Artefakte nur für gehaltenen Job, Kinds/Größen begrenzt | ✓ | `WorkerArtefakte` |
| G-07 | `proc_open` nur mit Argumentliste, feste Dateinamen | ✓ | `worker/src/Prozess.php`, `Schritt/*` |
| G-08 | IMAP nur TLS ≥ 1.2 mit Zertifikatsprüfung, STARTTLS erzwungen, keine Befehlsinjektion | ✓ | `worker/src/Mail/SocketStrom`, `ImapClient` |
| G-09 | Mails nie gelöscht, keine Mailinhalte im Log | ✓ | `MailEingang`, `worker/src/Log` |
| G-10 | MIME-Speicherspitze < 3× Mailgröße | ✗ niedrig | `MimeNachricht` (kodierte `message/rfc822` je Ebene kopiert) → N-16 |
| G-11 | Serverseitige Mengengrenze des Mail-Eingangs | ✗ niedrig | `WorkerMailController` → N-17 |
| G-12 | Worker-HTTP nur https, keine Redirects, TLS-Prüfung an | ✓ | `worker/src/Http/CurlWorkerHttp` |

## H – Betrieb: Backup, Update, Integrität, Konfiguration (ASVS V10/V14)

| ID | Prüfung | Ergebnis | Fundstelle |
|---|---|---|---|
| H-01 | Backups außerhalb der DocumentRoot, `shared/.htaccess` sperrt | ✓ | `bin/setup.template.php` |
| H-02 | Backup-Dateien und ihre Arbeitsdateien nur für den Besitzer lesbar (0600) | ✓ (#78) | `BackupService::nurFuerBesitzer()`, `BackupErstellung::oeffnen()` |
| H-03 | Download: `admin.system`, Namensmuster; mit `config.php` nur POST + CSRF + Passwort | ✓ | `Admin/BackupController` |
| H-04 | Restore nur im Installer, kein Zip-Slip, `config.php` nie per `include` | ✓ | `Installer/RestoreService` |
| H-05 | Backup-Echtheit (MAC/Signatur) | ✗ | Weg zu N-01 |
| H-06 | Update: feste Quelle, HTTPS, Prüfsumme, kein Downgrade, Zip-Slip | ✓ | `Update/ReleaseDownloader`, `UpdateService` |
| H-07 | Update: Echtheit (Signatur) statt nur Integrität | ✗ mittel | → N-02 |
| H-08 | Download-URLs/Redirect-Protokolle auf github.com/https beschränkt | ✗ Härtung | → N-18 |
| H-09 | Wartungs-Flag, `rename()`-Umschaltung, Shim = Konstante | ✓ | `ReleaseSwitcher` |
| H-10 | `setup.php` sperrt sich nach der Installation | ✓ | `bin/setup.template.php` |
| H-11 | `/install` gegen Übernahme durch Fremde geschützt | ✓ (#247) | Installations-Token `shared/install_token.txt` (`Installer/InstallToken`), von `setup.php` in der Weiterleitung übergeben oder per FTP abzulesen; jeder Schritt verlangt die Freigabe (N-05); `InstallFlowTest` |
| H-12 | Integritätscheck: alle Dateien von `current/`, Zusatzdateien erkannt | ✓ | `Integritaet/CodeIntegritaet` |
| H-13 | Integritätscheck deckt `web/` (Shim, `.htaccess`, `.user.ini`, Fremddateien) und `config.php` ab | ✗ mittel | → N-03 |
| H-14 | Prüfsummenliste nicht vom selben FTP-Angreifer änderbar | ⚠ | 01 §8 räumt es ein → N-19 |
| H-15 | `config.php`: Schlüssel per `random_bytes`, 0600 | ✓ | `Installer/ConfigWriter` |
| H-16 | Deny-`.htaccess` für `shared/`, `releases/`, `current/` | ✓ | `.htaccess`, `Update/DenyHtaccess`, Systemcheck |
| H-17 | Keine Secrets im Repo | ✓ (#78) | Token in `tools/hosting-check.php` entfernt (bleibt in der Git-Historie öffentlich – eine hochgeladene Kopie mit diesem Token muss vom Server); übrige Werte sind gekennzeichnete Dev-/Test-Fixtures |

## I – CSP und Security-Header

| ID | Prüfung | Ergebnis | Fundstelle |
|---|---|---|---|
| I-01 | CSP `script-src 'self'` ohne `unsafe-inline`/`unsafe-eval`, kein externer Host | ✓ | `docker/web/.htaccess`, `CspComplianceTest` |
| I-02 | `object-src 'none'`, `frame-ancestors 'none'`, `base-uri`, `form-action` | ✓ | dto. |
| I-03 | `nosniff`, `Referrer-Policy`, HSTS | ✓ | dto. |
| I-04 | `X-Frame-Options`, `Permissions-Policy` (nur Kamera, eigener Origin), COOP | ✓ (#78) | dto. |
| I-05 | Header erreichen bestehende Installationen | ✓ | `ReleaseSwitcher::refreshDocrootFiles()` (nur unveränderte Datei wird ersetzt) |
| I-06 | Header am echten Host geprüft (`curl -I`) | offen | manuell nach dem nächsten Update, siehe „Für Mirko" im PR |
| I-07 | Downloads/Seiten mit Klartext `no-store` | ✓ | Download-Controller; Sitzungsseiten über `cache_limiter` (B-02) |

## J – Fehlerseiten und Logs (ASVS V7)

| ID | Prüfung | Ergebnis | Fundstelle |
|---|---|---|---|
| J-01 | 500 ohne Trace/Pfad (außer Debug), 404 ohne Details | ✓ | `Http/Kernel::errorResponse()`, `app/views/error.php` |
| J-02 | `zend.exception_ignore_args` an (Bootstrap + `.user.ini`), `display_errors=Off` | ✓ | `app/src/bootstrap.php`, `docker/web/.user.ini` |
| J-03 | Fehler-Log: Klasse, Message, Route, Ort – nie der volle Exception-String | ✓ | `Kernel`; Upload-/Mail-/Archiv-Controller loggen nur Klasse + Pfad |
| J-04 | Keine fachlichen Klartextdaten, Passwörter, Mail-Adressen in Logs | ✓ | `FileLogger`-Aufrufe; SMTP-Fehler nur mit Code; Worker-Log nur Bezeichner |
| J-05 | Keine fachlichen Daten in URLs/Dateinamen | ✓ | Blob-IDs zufällig, Download-Namen aus Referenz/Zeitraum |

## Befunde des Durchlaufs 2026-10-25, die noch offen sind

Diese Funde sind in #78 **nicht** behoben und stehen mit Szenario und
Fix-Vorschlag im PR-Body. N-01 bis N-04 sind „mittel", alles Weitere ist
„niedrig", Härtung oder Hinweis. Kein Befund ist „kritisch" oder „hoch".

| Nr. | Schwere | Kurz |
|---|---|---|
| N-01 | mittel | `vault.public_key` unbeglaubigt: DB-Schreiber oder manipuliertes Backup lenkt künftige Einreichungen auf eigenen Schlüssel |
| N-02 | mittel | Updater prüft nur Integrität, keine Signatur der Releases |
| N-03 | mittel | Integritätscheck übersieht `web/` und `config.php` |
| N-04 | mittel | Platte/DB über öffentliche Uploads füllbar |
| N-05 | niedrig | `/install` ohne Token, solange `config.php` fehlt – ✓ behoben (#247) |
| N-06 | niedrig | Audit-Log ohne Zeitraum-Scope |
| N-07 | niedrig | Budget schreiben/löschen ohne Scope – ✓ behoben (#247) |
| N-08 | niedrig | 2FA ändern ohne erneute Anmeldung; abgebrochene Neueinrichtung entwertet TOTP |
| N-09 | niedrig | Rate-Limiter: Prüfen und Zählen nicht atomar |
| N-10 | niedrig | 2FA-Einrichtungspflicht nicht für JSON-Routen |
| N-11 | niedrig | keine HTTP→HTTPS-Umleitung – ✓ behoben (#247) |
| N-12 | niedrig | DATEV-Belegfeld mit führendem `+`/`-` – ✓ behoben (#247) |
| N-13 | niedrig | TOCTOU Magic Bytes ↔ Speichern beim Upload |
| N-14 | Härtung | Session-Cookie `PHPSESSID` statt `__Host-` |
| N-15 | Härtung | kein zentraler CSRF-Test über alle POST-Routen |
| N-16 | niedrig | Worker: MIME-Speicherspitze bei verschachtelten weitergeleiteten Mails |
| N-17 | niedrig | Worker-Mail-Eingang ohne serverseitige Mengengrenze |
| N-18 | Härtung | Update-Download-URLs nicht auf github.com beschränkt |
| N-19 | niedrig | Prüfsummenliste in `shared/` schreibbar (Spec räumt es ein) |
| N-20 | Hinweis | Posteingang-Scope über `created_at`, Auswertung über Rechnungsdatum (spec-konform, Entscheidung offen) |
| N-21 | Hinweis | Export-Markierung an „extern" statt an einem eigenen Recht (`export.mark`); eine eigene interne Nur-Lese-Rolle mit `export.zip` darf weiterhin markieren |
