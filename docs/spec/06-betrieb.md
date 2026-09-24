# 06 – Betrieb: Installer, Update, Backup, Mail, Jobs

## 1. Installer / Updater / Release

Übernahme aus `MirkoSc/vereinskalender` (dortige CLAUDE.md Abschnitte 2, 9,
10 gelten sinngemäß): `setup.php` (Nextcloud-Stil) → Release laden, SHA-256
prüfen, entpacken, Shim + `shared/` anlegen → `/install`.

Im DocumentRoot legt `setup.php` drei Dateien ab, die der Updater
mitpflegt: den Shim `index.php`, die `.htaccess` (Security-Header, CSP) und
eine `.user.ini` mit `zend.exception_ignore_args = On`. Die `.user.ini` ist
das Netz für Fehler, die auftreten, bevor der Bootstrap läuft – ohne sie
stünden Aufrufargumente (Passwörter, Tresor-Schlüssel, Belegdaten) im
Stacktrace. `.user.ini` und nicht `.htaccess`, weil der Zielhost PHP als
`fpm-fcgi` ausführt; dort beantwortet Apache `php_value` mit einem 500.
Alle drei Dateien liegen im Repo unter `docker/web/`; das Release-ZIP führt
diesen Ordner mit, `setup.php` kopiert sie von dort in den DocumentRoot und
der Updater hält sie aktuell. Damit laufen Entwicklungsumgebung und frische
Installation nicht auseinander. Beim Aktualisieren gilt: der Shim wird immer
auf den Stand des Releases gebracht (er muss zur laufenden Version passen),
`.htaccess` und `.user.ini` nur, solange sie noch Byte für Byte der Vorlage
des **vorherigen** Releases entsprechen – eine von Hand angepasste
`.htaccess` (IP-Sperre, Passwortschutz) bleibt stehen und wird nur gemeldet.
Der Shim liegt zusätzlich als Konstante `ReleaseSwitcher::SHIM` vor: beim
Umschalten ist das Release-Verzeichnis kurz nicht lesbar, die Selbstheilung
braucht den Inhalt also ohne Datei. `ShimContentTest` hält beide gleich.

Erweiterungen im `/install`-Flow:
1. DB-Zugangsdaten + Verbindungstest (wie gehabt)
2. **Server-Schlüssel** erzeugen → `config.php`
3. Erster Admin: E-Mail, Name, Passwort
4. **Tresor erzeugen** + Wiederherstellungsschlüssel anzeigen/drucken +
   Bestätigung durch Eingabe der letzten Gruppe
5. Mail-Einstellungen (SMTP) + Testmail – überspringbar
6. „Frische Installation" oder „Backup einspielen"

Stand M3-2 (issue #15): Schritt 1, 3, 4 und 6 sind umgesetzt, dazu alle
Migrationen ab 0 und der Release-Kanal. 5 kommt mit M3-1 (Mail) – Schritt 2,
den Server-Schlüssel, erzeugt der fertige Flow schon eine Anfrage vor Schritt
3, weil `ServerCrypto` das Sperren der E-Mail-Adresse des ersten Admins
braucht, bevor `config.php` geschrieben werden darf (siehe unten). Den Kanal
wählt der Admin schon in
`setup.php` – dort gibt es noch keine Datenbank, also legt `setup.php` die
Antwort als `shared/setup_kanal.txt` ab; der Installer übernimmt sie als
Einstellung `update_kanal` und löscht die Datei. Ohne diese Übergabe würde
eine aus einer Vorabversion installierte Testinstanz danach stillschweigend
auf `stable` nach Updates suchen. Am Ende löscht der Installer `setup.php`;
klappt das nicht (Dateieigentümer FTP), sagt die Abschlussseite Bescheid.

**Frische Installation als eigene Schrittkette** (`App\Installer\InstallController`,
`App\Installer\FirstAdminSetup`, M3-2): `VK_priv` darf nach 01 §2 nie im
Klartext auf Platte liegen – auch nicht in der PHP-Session-Datei. Deshalb
schreibt schon das Absenden des Formulars Tresor, ersten Admin, `user_key`
und `vault_grant` in die Datenbank; `config.php` und damit der Abschluss
kommen erst, wenn die letzte Gruppe des angezeigten Wiederherstellungs-
schlüssels bestätigt ist. Drei Requests:
1. `POST /install` (`modus=frisch`): Migrationen, Kanal-Setting, `Vault::create()`,
   `UserKeyPair::create()`, `UserKey::wrap()`, `VaultGrant::seal()`,
   `password_hash()` (`PASSWORD_ARGON2ID`, Fallback `PASSWORD_BCRYPT`, 01 §3).
   Nur der Hash der letzten Gruppe (nicht der Schlüssel selbst) und der noch
   unverschlüsselte Server-Schlüssel landen in der Session
   (`$_SESSION['install_admin']`, wie schon `install_restore` bei der
   Wiederherstellung). Die Seite zeigt den Schlüssel **einmalig**.
2. `POST /install/schluessel`: stimmt die eingetippte Gruppe (verglichen als
   Hash, über `RecoveryKey::normalizeGroup()`), schreibt `ConfigWriter::write()`
   den Server-Schlüssel nach `config.php` – das schließt den Installer. Sonst
   422, der Schlüssel wird nicht erneut angezeigt.
3. `POST /install/neu` („Neu beginnen“): für den Fall, dass der Browser vor
   der Bestätigung geschlossen wurde – entfernt Admin und Tresor wieder
   (`FirstAdminSetup::remove()`, über die Fremdschlüssel aus
   `migrations/006_user.sql` kaskadierend), danach ist das Formular erneut
   nutzbar. Ein erneutes Absenden von `POST /install`, während ein Schritt
   offen ist, legt keinen zweiten Tresor an, sondern zeigt die
   Bestätigungsseite erneut.

Ein bereits vorhandener Tresor (`vault`-Tabelle nicht leer, ohne aktiven
Sitzungsstand – etwa eine wiederverwendete Datenbank) lässt die frische
Installation mit einer Fehlermeldung abbrechen, statt einen zweiten Tresor zu
versiegeln, den niemand freigeben könnte.

Die Passwortregel (min. 12 Zeichen, Abgleich gegen eine mitgelieferte Liste
häufiger Passwörter, 01 §3) steckt in `App\Service\Account\PasswordPolicy`
und wird von Anmeldung/Reset (M3-3, M3-5) wiederverwendet; die Liste liegt
als `app/data/haeufige-passwoerter.txt` im Release (CLAUDE.md §2), eine
eigene Zusammenstellung statt einer separat lizenzierten Fremdliste
(CLAUDE.md §8).

Update-Schrittkette, Kanäle stable/beta, Pre-Releases, Rollback,
Wartungsmodus inkl. Banner: unverändert übernommen. Die Schritte sind
`check` → `download` → `extract` → `backup` → `switch` → `migrate` →
`finish`; jeder ist ein eigener kurzer Request, jeder ist wiederholbar, der
Stand liegt in `shared/update_state.json`. `backup` liegt bewusst direkt vor
`switch`: Download und Entpacken sind dann geglückt, und das Backup ist eine
Momentaufnahme unmittelbar vor dem einzigen Schritt, der die Installation
verändert. Er läuft noch mit dem Code des alten Releases – das Update von
einem Release ohne `backup`-Schritt legt deshalb noch kein Backup an, die
Schrittliste kommt ja vom laufenden Stand. Schlägt er fehl, bricht die Kette
vor dem Umschalten ab.
Der Updater speichert die `checksums.txt` des Releases als
`shared/release_checksums.txt` für den Code-Integritätscheck (01 §8).

Während der Wartungsmodus gesetzt ist, lässt der Shim außer `/admin` auch
`/css/` und `/js/` durch: sonst käme genau die Seite, die die Wartung beendet
(Update fortsetzen, Rollback, Flag freigeben), ohne Stylesheet und ohne das
Skript der Schrittkette an.

**Pflicht-Tests:** `ShimContentTest` (Konstante ≡ `docker/web/index.php`,
Shim ist gültiges PHP, beide Sperrbedingungen und die Ausnahmen für
`/css/` und `/js/` vorhanden, `setup.php` kopiert die drei Docroot-Dateien
statt eigene Kopien zu führen); `ReleaseSwitcherTest` (Umschalten,
Idempotenz, Reparatur nach Absturz zwischen den `rename()`-Aufrufen,
Rollback, Aufräumen, Shim-Selbstheilung und -Rücknahme, Docroot-Dateien inkl.
„von Hand geändert bleibt stehen"); `ReleaseDownloaderTest` (Kanäle, Entwürfe
überspringen, fehlende Assets, Prüfsummen erkennen und ablehnen, Dateiname
des Assets, curl-Zweig); `MaintenanceModeTest`; `ConfigWriterTest`
(Server-Schlüssel, je Installation eigene Geheimnisse); `UpdateChainTest`
(ganze Kette gegen ein lokales Release-ZIP, manipuliertes ZIP, falsche
`VERSION`, fehlgeschlagener Selbsttest stellt den alten Shim zurück);
`InstallFlowTest` (Schema, `config.php` erst nach Bestätigung, Kanal-Übernahme,
CSRF, abgelehnte Zugangsdaten, sieben Gruppen zu acht Zeichen, falsche letzte
Gruppe verrät den Schlüssel nicht erneut, Admin-Zeile per Server-Schlüssel
verschlüsselt und über den Blind Index auffindbar, `vault_grant` öffnet mit
dem Passwort denselben Tresor wie `vault.public_key`, der
Wiederherstellungsschlüssel ebenso und landet in keiner Spalte, erneutes
Absenden legt keinen zweiten Admin an, „Neu beginnen“ räumt auf, bereits
vorhandener Tresor wird abgelehnt, ungültige E-Mail/zu kurzes/bekanntes/nicht
übereinstimmendes Passwort); `PasswordPolicyTest` (Mindestlänge in Zeichen
nicht Bytes, Liste vorhanden); `tests/js/update.test.js` (Reihenfolge der
Schritte, Wiederholung ab dem fehlgeschlagenen Schritt); `tests/js/install.test.js`
(welches Formularfeld je nach `modus` sichtbar ist).

## 2. Backup & Restore

- Backup-ZIP = DB-Dump (reines PHP, übernommen) + bei Storage `fs` der
  Inhalt von `var/blobs/` + `manifest.json`. **Fachliche Daten sind darin
  nur als Chiffrat enthalten** → Backup darf heruntergeladen/extern
  abgelegt werden. `config.php` (Server-Schlüssel) kommt nur mit, wenn der
  Admin das ausdrücklich wählt (Hinweis: dann sind Betriebsdaten lesbar).
- Restore als Schrittkette (Blobs in Teilen), Rotation 10.
- Restore im Installer; danach Anmeldung mit bisherigem Konto (Grants
  liegen in der DB) oder per Wiederherstellungsschlüssel.

**Stand M2-6** (mit Blobs): `manifest.json` enthält `app_version`,
`schema_version`, `erstellt_am`, `config_enthalten`, `blob_dateien` und
`blob_bytes` – nur Zahlen und Versionen, nichts Fachliches.

- *Blobs beider Backends:* Backend `db` liegt ohnehin im Dump
  (`file_blob_chunk`); Backend `fs` kommt als `blobs/<2 Zeichen>/<Zufalls-ID>`
  ins ZIP, im selben Layout wie `shared/var/blobs/`. Gepackt wird, **was im
  Verzeichnis liegt**, nicht was `speicher_backend` gerade sagt: nach einem
  Backend-Wechsel (02 „Backend umstellen") ist der Mischzustand normal.
  `.part`-Dateien (abgebrochene Schreibvorgänge) bleiben draußen, die
  Blob-Einträge werden ungepackt abgelegt (Chiffrat komprimiert nicht).
- *Binärspalten im Dump:* `dek_sealed`, `header`, `cipher_sha256` und
  `file_blob_chunk.data` schreibt `MysqlDumper` als Hex-Literale (`X'…'`).
  Der Dump trägt `SET NAMES utf8mb4`, Chiffrat ist kein gültiges UTF-8 –
  Hex bedeutet in jedem Zeichensatz dieselben Bytes. Die Zeilen werden
  ungepuffert gelesen und ein INSERT zusätzlich nach Byte-Budget
  abgeschlossen, damit weder der Speicher noch `max_allowed_packet` an der
  Chunk-Tabelle scheitert.
- *Erstellen:* `BackupService::create(mitConfig, mitBlobs)`; ohne Angabe
  **ohne** `config.php` und **mit** Blobs. Von Hand:
  `php bin/backup.php [--mit-config] [--ohne-blobs]`. Eine **Admin-Seite mit
  Download gibt es noch nicht**: bis zur Anmeldung (M3-3) wäre sie ein
  offener Download des kompletten Dumps. Sie kommt mit M3, zusammen mit der
  Wahl „config.php mitsichern“.
- *Update-Kette:* sichert nie mit `config.php` und **nie mit Blobs**. Der
  `backup`-Schritt ist ein einzelner kurzer Request, ein Update fasst
  `shared/var/blobs/` gar nicht an, und kaputtgehen kann dabei das Schema –
  genau das holt der Dump zurück. Ein ZIP über Gigabytes an Belegdateien
  passt auf dem Zielhost in keinen Request.
- *Platzbedarf:* mit Blobs ist ein Backup so groß wie der Belegbestand;
  Rotation 10 heißt dann zehnmal so viel. Die Admin-Seite in M3 weist darauf
  hin.
- *Einspielen:* Installer-Schritt 6, „Backup einspielen“ mit hochgeladenem
  ZIP, in zwei Phasen (ein Request je Block, Stand in der Session,
  `public/js/install.js`): erst der Dump in Blöcken zu 200 Anweisungen, dann
  die Blob-Dateien in Portionen (200 Dateien bzw. 8 MiB je Request).
  Migrationen und `config.php` kommen **zuletzt**, damit ein Abbruch in der
  Blob-Phase den Installer offen lässt statt eine halb gefüllte Installation
  für fertig zu erklären. Trägt das ZIP Blobs, liegt es bis zum Ende als
  `shared/var/install_restore_<id>.zip` (0600) – der Upload überlebt den
  Request nicht. DB-Zugangsdaten kommen aus dem Formular, der Kanal
  ebenfalls; der `cron_token` wird neu erzeugt.
- *Blob-Namen aus dem ZIP:* nichts wird mit `extractTo()` entpackt.
  Geschrieben wird nur, was `blobs/<2 Hex>/<32 Hex>` ist und dessen
  Unterverzeichnis zum Namen passt; alles andere (`..`, absoluter Pfad,
  fremder Name) wird gezählt und verworfen. Das ZIP ist ein Upload.
- *Server-Schlüssel:* Enthält das ZIP eine `config.php`, wird **nur** deren
  `server_key` übernommen – gelesen per regulärem Ausdruck, nie per
  `include`, denn das ZIP ist ein Upload und dürfte sonst Code ausführen.
  Fehlt die Datei oder ist der Schlüssel ungültig, erzeugt der Installer einen
  neuen und die Seite weist darauf hin, dass damit verschlüsselte
  Betriebsdaten nicht mehr lesbar sind.
- Fehlermeldungen des Einspielens nennen nur SQLSTATE und Fehlercode, nie den
  Text der Datenbank: der zitiert Teile der fehlgeschlagenen Anweisung, also
  Zeilendaten des Backups.
- Bricht ein Block mitten in der Ausführung ab (Absturz), ist die Zieldatenbank
  halb gefüllt. Abhilfe: Restore von vorn – jede Tabelle beginnt mit
  `DROP TABLE IF EXISTS`.

**Pflicht-Tests:** `BackupRestoreRoundtripTest` (Backup → alle Tabellen
löschen → einspielen → byte-gleich; Sonderzeichen, NULL, mehr Zeilen als ein
INSERT fasst; Manifest; `config.php` nur auf Wunsch; Rotation; kein
Traversal über den Dateinamen; keine Zwischendatei übrig; **je Backend
`db`/`fs`**: Blobs samt Binärspalten byte-gleich zurück, Prüfsumme passt,
Klartext über den Tresor identisch, im ZIP steht nichts Lesbares, `.part`
bleibt draußen, Blobs auf Wunsch weglassbar);
`RestoreServiceTest` (ZIP ohne `dump.sql`, Server-Schlüssel wird gelesen,
**eine hochgeladene `config.php` wird nie ausgeführt**, ungültiger Schlüssel;
Blobs landen unter ihrem eigenen Namen, **nur** `blobs/<2 Hex>/<32 Hex>` wird
geschrieben, Portionierung nach Byte-Budget, ZIP ohne Blobs braucht keine
Blob-Phase);
`UpdateChainTest` (`backup` direkt vor `switch`, ohne `config.php` und ohne
Blobs, bei Fehler kein Umschalten); `InstallFlowTest` (Restore mit und ohne
Schlüssel im Backup, Restore **mit Blob-Dateien**, ZIP ohne Dump, Pfad ohne
echten Upload, CSRF, Schritt ohne aktive Wiederherstellung);
`tests/js/install.test.js` (Fortschritt, Statuszeile beider Phasen).

## 3. Mail

- Versand per **SMTP** – Ports 465/587, TLS (implizit oder STARTTLS).
  Zugangsdaten im Admin (`/admin/mail`), Passwort mit dem Server-Schlüssel
  verschlüsselt (`ServerCrypto`, Ablage als `setting.mail_smtp_passwort_enc`,
  Base64 von `encrypt()` – nie Klartext in der Tabelle). Fallback PHP
  `mail()` wählbar über `mail_transport` (`smtp`/`php_mail`).
- Alle Mails über `mail_queue`: sofortiger Versuch im Request
  (`App\Service\Mail\Mailer::sendeTestmail()`/`sendeFaellige()`), bei Fehler
  Retry im Cron (exponentiell: 60 s, 5 min, 15 min, 1 h, 3 h – bis zu 5
  Versuche insgesamt, danach Status `fehler`). Sicherheitsrelevante Mails
  (2FA-Code, Reset, ab M3-3) laufen über denselben sofortigen Versuch, Fehler
  wird dem Nutzer angezeigt. Umgesetzt mit M3-4 (issue #17):
  `Mailer::sendeMfaCode()` (der sechsstellige Code, Vorlage
  `app/views/mail/mfa-code.php`) und `Mailer::sendeSicherheitshinweis()`
  (ein fester Satz aus einer Liste im Aufrufer, Vorlage
  `app/views/mail/sicherheitshinweis.php`, für „neues Gerät gemerkt" und
  „2FA geändert", seit M3-5 auch „Passwort geändert/zurückgesetzt" –
  Tresor-Freigabe folgt mit M3-7). Seit M3-5 (issue #18) außerdem
  `Mailer::sendePasswortReset()` (Vorlage `app/views/mail/passwort-reset.php`,
  nur Link und Gültigkeit). Bis zum
  Aufräumen (`MailCleanupTask`) steht ein versendeter Code damit
  server-schlüssel-verschlüsselt in `mail_queue`, wie jede andere Mail auch.
- Mail-Vorlagen (Deutsch) als eigene Views unter `app/views/mail/`, gerendert
  von `App\Service\Mail\MailTemplates` – bewusst **nicht** über `App\View\View`,
  das immer das HTML-Layout (Navigation, CSS) davorsetzt; eine Mail ist weder
  HTML noch Teil eines Bereichs. **Keine fachlichen Inhalte** (keine Beträge,
  Lieferanten, IBANs) in Mails – nur Hinweise mit Link.
- Links in Mails brauchen eine absolute Adresse: Setting `oeffentliche_url`
  auf `/admin/mail` (nur `https://host[:port]`, `http://` nur für
  `localhost`). Ohne das Setting wird der Host der Anfrage nur übernommen,
  wenn er zur Domain der Absenderadresse gehört – sonst geht die Mail nicht
  raus (Host-Header-Injection, 01 §3, `App\Service\Mail\PublicUrl`).
- Absender, Reply-To, Vereinsname als Settings (`mail_absender`,
  `mail_antwort_an`, `mail_vereinsname`). SPF/DKIM beim Hoster einrichten
  (Doku in `docs/betrieb.md`, Folge-Issue zu #14).
- Admin: Testmail senden, Queue einsehen (Empfänger maskiert, Betreff wird
  gar nicht angezeigt).

  **Stand M3-1** (issue #14): kein SMTP-Paket als Abhängigkeit – ein
  Roh-Socket-Client (`App\Service\Mail\SmtpTransport` +
  `StreamSmtpConnection`) übernimmt fachlich den bereits gegen den Zielhost
  erprobten Ablauf aus `tools/hosting-check.php` (`hc_run_smtp()`, siehe §5:
  Port 465 implizit, `AUTH LOGIN`, gültiges Zertifikat). Grund gegen eine
  Bibliothek wie PHPMailer: keine zusätzliche Laufzeit-Abhängigkeit (CLAUDE.md
  §8), der Protokollumfang, den diese Anwendung braucht (EHLO, optional
  STARTTLS, optional AUTH LOGIN, MAIL FROM/RCPT TO/DATA), ist klein und bereits
  vorhanden. Der Mail-Rumpf geht immer als Base64
  (`Content-Transfer-Encoding: base64`) – das umgeht Zeilenlängen-Grenzen und
  Dot-Stuffing vollständig, ohne dass eine eigene Kodierung nötig wäre.
  `App\Service\Mail\SmtpConnection` ist die Testnaht: Tests laufen gegen eine
  Fake-Verbindung im Speicher, nie gegen einen echten Socket.
  `App\Service\Mail\Mailer` kennt keine Http/Session-Abhängigkeit (CLAUDE.md
  §6a) und läuft unverändert im Cron-Task (`App\Service\Cron\MailQueueTask`,
  läuft in `jedesMal`) und in `App\Admin\MailController`. Aufräumen
  (`App\Service\Cron\MailCleanupTask`, in `aufraeumen`) löscht `gesendet`-
  Zeilen nach 7 Tagen; `fehler` bleibt für einen Menschen stehen.

  **Pflicht-Tests:** `MailQueueTest` (Zeile enthält keinen Klartext; Claim
  setzt `laeuft` und `attempts+1`; zweiter Claim während der Vormerksperre
  bekommt nichts; abgelaufene Sperre wird von `claimDue` übernommen; nach dem
  fünften Fehlversuch `fehler` und kein weiterer Claim; `last_error`
  speichert nur die Klasse; Aufräumen trifft nur alte `gesendet`-Zeilen);
  `MailerTest` (Sofortversuch bei `sendeTestmail`, Backoff nach Fehlschlag,
  Cron-Antwort und Log ohne Empfänger/Betreff); `SmtpTransportTest`
  (Befehlsreihenfolge inkl. STARTTLS und AUTH LOGIN gegen die Fake-Verbindung,
  sauberer Abbruch bei abgelehntem STARTTLS, keine Zugangsdaten in der
  Fehlermeldung); `MailMessageTest` (RFC-2047-Kodierung, CRLF durchgängig,
  Header-Injection in Empfänger/Betreff wird abgelehnt); `MailSettingsTest`
  (Passwort-Rundreise, `setting`-Zeile enthält kein Klartext, `__debugInfo()`
  maskiert).

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
- **Cron** (per Hoster-Kontrollpanel, Token wie im Vereinskalender): nur
  Mail-Queue, Aufräumen (Upload-Chunks, abgelaufene Tokens/Codes,
  Rate-Limit-Einträge, IP-Hashes), Erinnerungs-Mails („5 Belege warten auf
  Prüfung" – ohne Details). Der Hoster erlaubt einen Aufruf **jede Minute**
  (M0-Befund); der Endpunkt ist darauf ausgelegt.

  **Stand M1-5:** `GET /cron?token=…` (`Api\CronController`). Ohne Session,
  ohne Anmeldung – einziges Credential ist `cron_token` aus
  `shared/config.php`, verglichen per `hash_equals`; fehlendes, leeres, falsches
  oder als Array übergebenes Token gibt 403, **ohne** dass der Runner (und damit
  die DB-Verbindung) gebaut wird. Die Route trägt keine `Permission`, weil es
  keinen Nutzer gibt; sie entschlüsselt nie.

  - *Sperre gegen Überlappung:* eine Zeile `cron_lock_until` in `setting`,
    atomar in **einer** Anweisung genommen (`CronLockRepository::acquire`).
    Ist sie gehalten, endet der Aufruf sofort mit Status `laeuft_bereits` –
    kein Fehler. Die Sperre verfällt nach `CronRunner::LOCK_TTL_SECONDS` (300 s),
    ein abgestürzter Lauf blockiert also nicht dauerhaft. Freigegeben wird nur
    die **eigene** Sperre (Vergleich mit dem Ablaufzeitpunkt), damit ein Lauf,
    dessen Sperre abgelaufen war, nicht die eines Nachfolgers löst.
  - *Aufgaben:* Schnittstelle `Service\Cron\CronTask` (`name()`, `run($now)`).
    `jedesMal` läuft bei jedem Aufruf (ab M3-1 `mail_versenden`, §3),
    `aufraeumen`
    nur, wenn seit `cron_letztes_aufraeumen` mindestens
    `cron_aufraeum_intervall_s` (Setting, Standard 3600, mindestens 60)
    vergangen sind. Der Zeitstempel wird **vor** den Aufräum-Tasks gesetzt:
    ein Task, der den Lauf abbricht, wird nicht jede Minute neu versucht.
    Ein fehlschlagender Task stoppt die anderen nicht; die Antwort nennt nur
    den Klassennamen, das Log (`FileLogger`) Klasse und Meldung.
  - *Erster Task:* `JobCleanupTask` löscht `fertig`/`uebersprungen`-Jobs, die
    älter als 7 Tage sind; `fehler` bleibt stehen.
  - *Wartungsmodus:* der Shim lässt `/cron` nicht durch (nur `/admin`, `/css/`,
    `/js/`); der Aufruf bekommt 503 und der nächste Minutenlauf holt nach.
  - *Antwort:* JSON `status` (`ok`/`fehler`/`laeuft_bereits`), `aufgaben`,
    `aufgeraeumt`, `dauer_ms` – nur Namen und Zähler, nie fachliche Daten.
  - *Job-Tabelle:* `migrations/002_job.sql`; `JobRepository::claim()` vergibt
    einen Job an genau einen Aufrufer (bedingtes `UPDATE`, Gewinner per
    Zeilenzahl, bewusst kein `FOR UPDATE`, damit keine Transaktion über einen
    Jobschritt offen bleibt – #98). Übernommen wird ein `offen`er oder ein
    `laeuft`-Job mit abgelaufenem `locked_until`; `heartbeat()` verlängert nur
    für den Halter. Abgearbeitet werden Jobs nicht vom Cron, sondern ab M4
    vom Browser-Worker (`POST /api/jobs/step`) bzw. vom Worker-Modul.

  **Pflicht-Tests:** `JobRepositoryTest` (Claim setzt Sperre und `attempts`;
  zweiter Claim während der Sperre bekommt nichts; abgelaufene Sperre wird
  übernommen; Filter nach Executor/Typ; `heartbeat` nur für den Halter;
  `fehler` speichert nur die Klasse; Aufräumen trifft nur alte, erledigte
  Jobs); `CronRunTest` (zweiter Lauf bei gehaltener Sperre arbeitet nicht,
  abgelaufene Sperre wird übernommen, Sperre nach Absturz frei, fremde Sperre
  bleibt bei `release`, Aufräumen einmal je Intervall und konfigurierbar,
  fehlschlagender Task ohne Meldung in der Antwort); `CronTokenTest`
  (Token-Prüfung ohne Datenbank).

  **Stand M4-7 (issue #29):** der Browser-Worker treibt `executor=session`-
  Jobs an, solange eine angemeldete Person eine Seite offen hat.

  - *Route:* `POST /api/jobs/step` (`App\Api\JobController`), Zugriff
    `Zugriff::angemeldet()` – welche Job-**Typen** eine Session anfassen darf,
    prüft nicht die Route, sondern `App\Service\Job\JobRunner` je Typ über
    `App\Service\Job\JobHandler::recht()`. Ein neuer Job-Typ braucht also
    keine Routen-Änderung, nur einen Eintrag in der Handler-Liste
    (`app/src/bootstrap.php`, `$jobHandlerFor`). `pdf_erzeugen`
    (`App\Service\Document\PdfErzeugung`) verlangt `document.edit`. Ohne
    entsperrten Tresor (CLAUDE.md Abschnitt 4) antwortet die Route
    `{status: "gesperrt", offen: N}`, ohne einen Job zu beanspruchen.
  - *Ein Aufruf = ein Schritt:* `JobRunner::schritt()` beansprucht (`claim()`,
    60 Sekunden Sperre) höchstens einen Job aus den erlaubten Typen, ruft
    dessen `JobHandler::schritt()` genau einmal auf und schreibt das Ergebnis
    über `JobRepository::schrittErledigt()` zurück – das setzt `attempts`
    auf 0 zurück, weil der Schritt Fortschritt gebracht hat, und gibt die
    Sperre frei, damit der nächste Aufruf (derselbe Tab oder ein anderer)
    weitermachen kann.
  - *Gift-Job-Schutz:* wird ein Job **`JobRunner::MAX_VERSUCHE` (3) mal**
    beansprucht, ohne dass je ein Schritt durchläuft (der Request stürzt ab
    oder wird vom Webserver gekappt – Abschnitt 1), markiert `JobRunner` ihn
    `fehler` mit `App\Service\Job\JobAbgebrochen`, ohne den Handler
    aufzurufen. Eine Exception aus dem Handler markiert den Job sofort
    `fehler` – nur die Klasse, nie die Meldung (wie beim Cron).
  - *Kopfzeile:* „N Belege in Verarbeitung" (`partials/kopf.php`,
    `public/js/jobs.js`), gespeist von `App\Service\Job\JobRunner::offen()` –
    `null` für einen Zugang ohne Recht für irgendeinen Job-Typ, dann zeigt
    die Kopfzeile nichts und der Worker startet gar nicht erst. `LoginGuard`
    setzt den Wert wie `ausstehendeFreigaben` bei jedem Request neu.
  - *Hintergrund-Tab:* `public/js/jobs.js` pausiert nicht vollständig,
    sondern verlangsamt (Page Visibility API):

    | Antwort                | sichtbar | Hintergrund |
    |---|---|---|
    | ein Schritt lief       | 250 ms   | 30 s        |
    | nichts zu tun, offen>0 | 10 s     | 60 s        |
    | nichts zu tun, offen=0 | 30 s     | 120 s       |
    | gesperrt, 401, 403     | Stopp    | Stopp       |
    | Netz-/5xx-Fehler       | 30 s     | 120 s       |

    Wird der Tab wieder sichtbar, verwirft das Skript den laufenden Timer
    und fragt sofort erneut. `gesperrt`/401/403 verlangen ein Neuladen der
    Seite, kein weiteres Fragen aus derselben Schleife.

  **Pflicht-Tests (M4-7):** `JobRepositoryTest` (Claim mit Typ-Liste, leere
  Liste beansprucht nichts, `schrittErledigt` nur für den Halter der Sperre
  und setzt `attempts` zurück, `fail` mit Halter-Prüfung, `zaehleOffen`);
  `JobStepFlowTest` (echte Route/Guard/Controller/DB: ein Aufruf = ein
  Schritt, mehrstufiger Job bis `fertig`, ohne das nötige Recht wird nichts
  beansprucht, ohne entsperrten Tresor `gesperrt` und der Job bleibt
  unangetastet, CSRF und Anmeldung, fremd gesperrte Jobs bleiben unberührt,
  Exception → `fehler` mit nur dem Klassennamen, `MAX_VERSUCHE` überschritten
  → `fehler` ohne Handler-Aufruf); `tests/js/jobs.test.js` (`kopfText`,
  `wartezeit` für jede Zeile der Tabelle oben).

  **Stand M4-8 (issue #30):** der erste **Browser-Job** (Abschnitt 4 oben:
  „Worker holt Aufgabe, rendert, lädt Seitenbilder hoch") – `render_pages`
  (03-erfassung-und-ki.md §3/§5, `App\Service\Document\PdfRasterung`).

  - *Warum eigene Routen:* `POST /api/jobs/step` ruft ausschließlich
    `App\Service\Job\JobHandler::schritt()` auf, serverseitig, in einem
    Request. Rendern passiert im Browser mit pdf.js – dafür braucht es die
    rohen PDF-Bytes (Download), Zeit für mehrere Seiten und viele kleine
    Uploads, keinen einzelnen Request-Roundtrip. `App\Service\Job\JobTyp`
    trennt deshalb, was jeder Jobtyp über sich erklärt (`typ()`, `recht()`)
    von dem, was nur ein Session-Jobtyp zusätzlich kann (`schritt()`,
    `JobHandler extends JobTyp`) – `App\Service\Job\JobRunner::offen()`
    zählt beide Sorten für die Kopfzeile zusammen, `schritt()` treibt nur
    die Session-Sorte.
  - *Sperre über viele Requests:* anders als der Session-Job (eine Sperre
    je Aufruf, `schrittErledigt()` gibt sie frei) hält `render_pages` seine
    Sperre über die ganze Aufgabe hinweg – `App\Repository\JobRepository::
    fortschritt()` schreibt `state` und verlängert `locked_until`, ohne
    `locked_by` anzurühren. Ein erneutes `naechste()` mitten im Job fände
    also nichts Wiederaufnehmbares (die Sperre ist ja noch gültig); die
    Antwort auf eine gespeicherte Seite nennt deshalb selbst, wo es
    weitergeht (`App\Service\Document\SeiteErgebnis::ok()`,
    `naechsteQuelle`/`naechsteSeite`), auch beim Wechsel auf die nächste
    PDF-Quelle desselben Jobs.
  - *Idempotenz ohne Zustandstabelle für Uploads:* `job.state` trägt neben
    der Fortschrittsposition (`quellen`, `quelle`, `seite`, `seq`) das
    zuletzt gespeicherte Tupel (`letzte`) – ein wiederholter Upload genau
    dieser Seite (Netzwerkfehler auf dem Rückweg) antwortet `ok` bzw.
    `fertig`, ohne ein zweites Artefakt zu schreiben; die Unique-Zeile in
    `document_artifact` (02-datenmodell.md, Migration 015) ist der
    Rückfallschutz, nicht der Regelfall.
  - *Gift-Job-Schutz:* wie beim Session-Job, aber nur an einer Stelle
    scharf – `App\Service\Document\PdfRasterung::naechste()` prüft
    `attempts > MAX_VERSUCHE` direkt nach dem `claim()`, **bevor** es
    `job.state` überhaupt anlegt oder liest. Ein Fortschritt
    (`fortschritt()`) setzt `attempts` zurück, ein bloßes erneutes Claimen
    ohne gespeicherte Seite nicht – sonst würde `naechste()`s eigener
    `fortschritt()`-Aufruf beim allerersten Claim den Zähler sofort wieder
    auf 0 setzen und ein Browser, der nie über den PDF-Download
    hinauskommt, liefe endlos weiter.
  - *Nur im Posteingang:* `public/js/rasterung.js` läuft, anders als
    `public/js/jobs.js`, nicht auf jeder angemeldeten Seite, sondern nur auf
    `/app/posteingang` und der Detailseite (`App\App\InboxController::
    rasterungDaten()`) – dort, wo die Spec „während ein angemeldeter Nutzer
    den Posteingang geöffnet hat" verlangt (03-erfassung-und-ki.md §3).
    pdf.js wird erst beim ersten tatsächlich beanspruchten Job per
    `import()` nachgeladen, nicht auf Verdacht.

  **Pflicht-Tests (M4-8):** `DocumentArtifactRepositoryTest` (Insert/Lesen
  in Seitenreihenfolge, Unique-Zeile verhindert doppelte Einträge,
  `fremdeBlobIds` nur für andere Jobs, `ON DELETE CASCADE` auf `blob_id`);
  `JobRepositoryTest`, ergänzt (`gibtEs`, `fortschritt` nur für den Halter
  und setzt `attempts` zurück, `fortschritt` nach abgelaufener und
  übernommener Sperre wirkungslos, `release` mit Halter-Prüfung);
  `PdfErzeugungTest`, ergänzt (Einzel-PDF und gemischter Beleg legen genau
  einen `render_pages`-Job an, reine Bildbelege keinen, ein wiederholtes
  `pruefen()` keinen zweiten); `RasterungFlowTest` (echte Route/Guard/
  Controller/DB: vollständiger Lauf über zwei Seiten einer Quelle und über
  zwei Quellen, wiederholter Upload der zuletzt gespeicherten Seite – auch
  nach Jobabschluss – ohne doppeltes Artefakt, Lücke in der Seitenzählung
  → `unerwartet`, falscher Lock → `verloren`, ein zweiter Tab beansprucht
  keinen bereits gesperrten Job, ohne Recht/CSRF/Tresor wird nichts
  beansprucht, falscher Dateityp → 415, zu große Datei → 413, Abbruch
  `defekt`/`passwort` scheitert mit nur dem Klassennamen, Abbruch `browser`
  gibt den Job frei, ein späterer Rendering-Lauf räumt die Seiten eines
  früheren auf); `tests/js/rasterung.test.js` (`massstab`, `wartezeitRaster`
  für jede Zeile, Qualitätsstufen, `verarbeiteJob` treibt eine oder mehrere
  Quellen mit derselben Sperre durch, bricht bei einer zu großen Seite oder
  einem defekten/passwortgeschützten PDF ab).
- Langlaufende KI-Aufrufe: curl-Timeout aus dem Anbieter-Profil. Auf
  Linux zählt Warten auf Netzwerk-I/O nicht in `max_execution_time`, aber
  der Webserver kann Requests trotzdem kappen – **Grenze im Hosting-Check
  messen** und Timeout darunter setzen.
- **Die DB-Verbindung überlebt einen langen externen Aufruf nicht.** Der
  Zielhost setzt `wait_timeout = 120` (M0-Befund), eine untätige Verbindung
  stirbt also mitten im KI-Aufruf. Regel: Ein Schritt, der auf etwas
  Externes wartet, gibt die Verbindung vorher mit
  `ConnectionFactory::release()` frei und holt sie danach neu; er hält
  keine Verbindung über den Aufruf hinweg offen. Als Netz prüft
  `ConnectionFactory` eine länger untätige Verbindung mit `SELECT 1` und
  baut sie neu auf, **bevor** die eigentliche Abfrage läuft – gescheiterte
  Anweisungen werden nie wiederholt, weil ein Schreibvorgang mit unbekanntem
  Ausgang sonst doppelt liefe. Ist eine Transaktion offen, wird nicht neu
  verbunden, sondern mit `ConnectionLostException` abgebrochen: der Server
  hat die Transaktion bereits zurückgerollt.

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
- **Werden `.htaccess`-Direktiven ausgewertet?** Das Skript legt eine Datei
  neben sich ab, ruft sie über die eigene Adresse ab (erwartet 200), sperrt
  sie per `.htaccess` und ruft erneut ab (erwartet 403). Davon hängen die
  Security-Header und die CSP ab – und, falls der DocumentRoot nicht auf
  `/web/` gelegt werden kann, auch der Schutz von `shared/`.
  Abschaltbar mit `selftest=0`
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

**Pflicht-Tests:** `tests/HostingCheckTest.php` – Token-Vergleich (leere
Erwartung greift nie), `hc_parse_bytes`/`hc_format_bytes`, Bewertung von
Mindestwerten, Zusammenfassung und schlechtester Status, Redaktion von
Geheimnissen, Abdeckung der oben gelisteten Prüfpunkte, HTML-Ausgabe ohne
Skript und mit maskierten Sonderzeichen, restloses Aufräumen der
Dateisystem-Proben, Abbruch ohne SMTP-Host bzw. ohne Langlauf-URL. Die
Datenbank-Prüfungen laufen gegen einen echten Server, wenn
`HC_TEST_DB_HOST`/`_NAME`/`_USER`/`_PASS` gesetzt sind; die Docker-Umgebung
und die CI setzen sie. Das Skript selbst bleibt abhängigkeitsfrei – es wird
per FTP in ein leeres Verzeichnis geladen –, seine Tests laufen seit M1-1
unter PHPUnit mit.

Ergebnis wird in `docs/hosting-befunde.md` eingetragen; Abweichungen von
den Annahmen dieser Specs werden als Issues angelegt.
