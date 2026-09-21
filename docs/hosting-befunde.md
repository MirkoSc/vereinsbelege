# Hosting-Befunde

Wird in M0-2 aus der Ausgabe von `tools/hosting-check.php` gefüllt.

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
