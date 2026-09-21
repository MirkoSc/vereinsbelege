# Hosting-Befunde

Wird in M0-2 aus der Ausgabe von `tools/hosting-check.php` gefüllt.

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

| Prüfpunkt | Annahme in den Specs | Befund | Folge-Issue |
|---|---|---|---|
| PHP-Version | 8.5 | | |
| memory_limit | ≥ 256M | | |
| max_execution_time | 30 s | | |
| Request-Abbruch bei langem Warten (curl) | > 90 s | | |
| upload_max_filesize / post_max_size | egal (Chunk-Upload 2 MiB) | | |
| ext-sodium + pwhash INTERACTIVE Laufzeit | vorhanden, < 1 s | | |
| PASSWORD_ARGON2ID | vorhanden | | |
| ext-gd (JPEG/PNG) | vorhanden | | |
| ext-imagick | nicht vorausgesetzt | | |
| ext-zip, curl, openssl, mbstring, fileinfo, intl | vorhanden | | |
| MariaDB/MySQL-Version, max_allowed_packet | ≥ 4 MiB | | |
| DB-Größenlimit im Tarif | | | |
| Streaming-Ausgabe 60 s | funktioniert | | |
| SMTP (Host/Port/TLS) | 465/587 | | |
| Minimales Cron-Intervall | 5–15 min | | |
| rename() von Verzeichnissen | funktioniert | | |
| KI-Server vom Webspace erreichbar | ja (TLS + API-Key) | | |
