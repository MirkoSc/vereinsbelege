# Hosting-Befunde

Ergebnis von Meilenstein M0: die Annahmen der Specs, geprüft auf dem echten
Webspace mit `tools/hosting-check.php` (06 §5).

## So entsteht die Ausgabe

1. In `tools/hosting-check.php` `HC_TOKEN` auf ein zufälliges Geheimnis setzen.
2. Datei per FTP in ein Testverzeichnis des Webspace laden.
3. `https://<domain>/<testverzeichnis>/hosting-check.php?token=<token>` im
   Browser öffnen, JSON aus dem Textfeld kopieren (oder `&format=json`).
4. Lang laufende Messungen einzeln nachziehen – je Aufruf ein Request:
   `?test=longrun&seconds=30` (dann 60, 90, 120, 180),
   `?test=stream&mb=200&seconds=60`, `?test=smtp&smtp_host=…`.
   Datenbank-Prüfungen brauchen `db_host`, `db_name`, `db_user`, `db_pass` –
   besser per POST als in der URL.
5. Kleinstes Cron-Intervall und DB-Größenlimit im Kundenmenü ablesen.
6. Tabelle unten füllen, JSON darunter ablegen, **Skript vom Webspace
   löschen**.

Lokal zum Vergleich:

```
docker run --rm -v "$PWD":/app -w /app php:8.5-cli php tools/hosting-check.php
```

## Befunde

Gemessen am 21.09.2026 auf der Test-Subdomain bei all-inkl (Shared Hosting,
PHP-FPM). Die Rohausgaben sind bewusst **nicht** eingecheckt: sie enthalten
Kundenkennung, Mail-Benutzernamen und absolute Pfade (E-13).

Legende: ✅ Annahme bestätigt · ⬆️ besser als angenommen · ⚠️ Abweichung mit
Folge-Issue · 📋 nur dokumentiert

| Prüfpunkt | Annahme in den Specs | Befund | Folge-Issue |
|---|---|---|---|
| PHP-Version | 8.5 | ✅ 8.5.9, SAPI `fpm-fcgi`, 64-bit | |
| `memory_limit` | ≥ 256M | ⬆️ 384 MiB | |
| `max_execution_time` | 30 s | ⬆️ 60 s (lokal bleibt 30 s, also strenger) | |
| Request-Abbruch bei langem Warten | > 90 s | ⬆️ kein Abbruch bei 180 s (`set_time_limit(0)` wird angenommen); gemessen wird die Wanduhr-Grenze des Webservers, `sleep`/Netz-I/O zählen ohnehin nicht auf `max_execution_time` | |
| `upload_max_filesize` / `post_max_size` | egal (Chunk-Upload 2 MiB) | ⬆️ je 384 MiB | |
| `max_input_time` | ≥ 30 s | ⬆️ unbegrenzt | |
| `disable_functions` | exec & Co. gesperrt | ⚠️ leer – exec wäre technisch möglich; Verzicht bleibt Selbstbeschränkung | [#102](https://github.com/MirkoSc/vereinsbelege/issues/102) |
| `open_basedir` | nicht gesetzt | ✅ nicht gesetzt | |
| `zend.exception_ignore_args` | On | ⚠️ 0 – zur Laufzeit per `ini_set` setzbar (geprüft) | [#97](https://github.com/MirkoSc/vereinsbelege/issues/97) |
| `set_time_limit()` wirkt | ja | ✅ ja | |
| Standard-Zeitzone | egal | 📋 schon `Europe/Berlin` | |
| OPcache | egal | 📋 vorhanden – `opcache_reset()` nach Release-Umschaltung beachten | |
| ext-sodium + pwhash INTERACTIVE | vorhanden, < 1 s | ✅ libsodium 1.0.18, INTERACTIVE 52–56 ms | |
| pwhash MODERATE | nicht vorausgesetzt | ⬆️ 309–321 ms – MODERATE ist bezahlbar | [#100](https://github.com/MirkoSc/vereinsbelege/issues/100) |
| `PASSWORD_ARGON2ID` | vorhanden | ✅ funktioniert, 166–170 ms | |
| Sealed Box, secretstream, `random_bytes` | funktionieren | ✅ Rundläufe erfolgreich – Tresor-Modell und Blob-Verschlüsselung tragen | |
| ext-gd (JPEG/PNG) | vorhanden | ✅ GD 2.3.0, JPEG/PNG/WebP (ohne AVIF) | |
| ext-imagick | nicht vorausgesetzt | 📋 vorhanden; ob es PDFs rastern kann, ist offen | [#103](https://github.com/MirkoSc/vereinsbelege/issues/103) |
| ext-zip, curl, openssl, mbstring, fileinfo, intl, iconv, pdo_mysql, json | vorhanden | ✅ alle vorhanden (zusätzlich zlib, exif) | |
| MariaDB-Version | ≥ 10.5 | ✅ 10.6.23 | |
| `max_allowed_packet` | ≥ 4 MiB | ⬆️ 64 MiB | |
| Zeichensatz, Standard-Engine | utf8mb4, InnoDB | ✅ utf8mb4, InnoDB | |
| Tabelle anlegen, JSON-Spalte, LONGBLOB | funktionieren | ✅ alle drei – Installer und beide Blob-Backends möglich | |
| `wait_timeout` | (nicht betrachtet) | ⚠️ **120 s** statt 28800 – untätige Verbindung stirbt während eines langen KI-Aufrufs | [#98](https://github.com/MirkoSc/vereinsbelege/issues/98) |
| DB-Größenlimit im Tarif | | 📋 kein hartes Limit, praktisch ~10 GB; Default bleibt Dateisystem (E-06) | |
| Streaming-Ausgabe 60 s | funktioniert | ✅ 199,8 MiB in 61 s, HTTP 200, `DONE` angekommen (~3,3 MiB/s) | |
| SMTP (Host/Port/TLS) | 465/587 | ✅ Hoster-SMTP, Port 465 implizit, `AUTH LOGIN` erfolgreich, Zertifikat gültig, `SIZE` ≈ 146 MiB | |
| Zustellung einer Testmail | funktioniert | 📋 noch offen (`mail_to` war leer) – spätestens in M3 nachziehen | |
| Minimales Cron-Intervall | 5–15 min | ⬆️ **1 min** – Läufe können überlappen, Aufräumen braucht Drosselung | [#99](https://github.com/MirkoSc/vereinsbelege/issues/99) |
| `rename()` von Verzeichnissen | funktioniert | ✅ vorwärts und zurück, dazu `mkdir`, `flock`, `symlink` | |
| Schreibrecht über dem DocumentRoot | vorhanden | ✅ DocumentRoot auf `<bereich>/web/` gelegt, `shared/` daneben beschreibbar, 361 GiB frei | |
| `shared/` öffentlich erreichbar? | nein | ✅ Testdatei über die Hauptdomain nicht abrufbar; Vorsorge per `.htaccess` trotzdem sinnvoll | [#101](https://github.com/MirkoSc/vereinsbelege/issues/101) |
| `.htaccess` wird ausgewertet | vorausgesetzt (Security-Header, CSP) | ✅ Datei vorher HTTP 200, nach der Sperre HTTP 403 | |
| KI-Server vom Webspace erreichbar | ja (TLS + API-Key) | ✅ `api.openai.com` und `api.anthropic.com` antworten mit 401 in 175–450 ms, Zertifikate gültig; `api.github.com` 200 (Updater) | |

## Fazit

Keine einzige Prüfung ist fehlgeschlagen. Alle Annahmen der Specs tragen auf
diesem Hoster, die meisten Abweichungen gehen nach oben. Architekturrelevant ist
allein `wait_timeout = 120` ([#98](https://github.com/MirkoSc/vereinsbelege/issues/98));
sicherheitsrelevant `zend.exception_ignore_args`
([#97](https://github.com/MirkoSc/vereinsbelege/issues/97)).

Das Prüfskript gehört nach der Messung vom Webspace gelöscht – es ist kein
Teil eines Releases. Für eine Wiederholung (neuer Hoster, neuer Tarif, neue
PHP-Version) liegt es im Repo unter `tools/hosting-check.php`; die Aufrufe
stehen oben.
