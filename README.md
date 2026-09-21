# Vereinsbelege

Beleg- und Rechnungsverwaltung für einen Sportverein: Rechnungen per Handy
einreichen (auch ohne Anmeldung), automatisch aufbereiten (Zuschnitt,
Schwarzweiß, PDF), per KI auslesen (Lieferant, Beträge, Kategorie,
wiederkehrend?), Kontoauszüge (MT940/CSV) importieren, Belege und Buchungen
abgleichen und auswerten, wohin das Geld fließt. Alle fachlichen Daten liegen
verschlüsselt in Datenbank oder Dateisystem. Optional übernimmt ein
Worker (z. B. Raspberry Pi) OCR und KI-Auslesen rund um die Uhr.

Läuft auf Shared Hosting (PHP 8.5 + MySQL/MariaDB) – ohne SSH, ohne Composer
auf dem Server, ohne `exec()`. Installation und Updates wie beim
[Vereinskalender](https://github.com/MirkoSc/vereinskalender): eine
`setup.php` hochladen, Rest per Browser.

**Status:** Meilenstein M1 – das Projektgerüst steht (Routing, Konfiguration,
Datenbank, Migrationen, Logging), dazu Installer, Self-Updater und
Wartungsmodus. Fachlich kann die Anwendung noch nichts.

> Der Adminbereich (`/admin/update`) hat bis Meilenstein M3 **keine
> Anmeldung**. Eine öffentlich erreichbare Installation gehört bis dahin
> zusätzlich hinter einen Passwortschutz des Hosters.

## Entwicklung

Voraussetzung: Docker. PHP und Composer werden lokal nicht gebraucht.

```bash
# Abhängigkeiten installieren (einmalig bzw. nach Änderungen an composer.json)
docker run --rm -v "$PWD":/app -w /app composer:2 composer install

# Anwendung starten – http://localhost:8080
docker compose up --build

# Tests (nutzen die MariaDB aus docker compose und legen vereinsbelege_test an)
docker compose run --rm app php vendor/bin/phpunit

# Tests der Client-Logik (Node, keine weiteren Abhängigkeiten)
node --test tests/js/*.test.js

# Migrationen anwenden
docker compose exec app php bin/migrate.php

# setup.php neu erzeugen (nach Änderungen an bin/setup.template.php oder
# app/src/Service/Update/ReleaseDownloader.php – die CI prüft das)
docker compose exec app php bin/build_setup.php
```

Die Update-Seite liegt unter <http://localhost:8080/admin/update>. Den
Installer sieht man, indem man `docker/shared/config.php` kurz beiseite
schiebt – ohne Konfiguration läuft die Anwendung im Installationsmodus.

Die Umgebung bildet den Zielhoster nach: `disable_functions` ohne `exec` und
Verwandte, `max_execution_time = 30`, und `wait_timeout = 120` auf der
Datenbank – eine untätige Verbindung stirbt lokal genauso schnell wie dort.

## Dokumente

| Datei | Inhalt |
|---|---|
| [CLAUDE.md](CLAUDE.md) | Verbindliche Architektur-Referenz (Claude Code liest sie in jeder Session) |
| [docs/VORGEHEN.md](docs/VORGEHEN.md) | Wie mit Claude Code gearbeitet wird – Schritt für Schritt |
| [docs/ROADMAP.md](docs/ROADMAP.md) | Meilensteine M0–M13 mit Issues und Abnahmekriterien |
| [docs/ENTSCHEIDUNGEN.md](docs/ENTSCHEIDUNGEN.md) | Getroffene und offene Grundsatzentscheidungen |
| [docs/spec/](docs/spec/) | Detail-Spezifikationen je Bereich (werden bei Bedarf gelesen) |
| [docs/spec/07-worker.md](docs/spec/07-worker.md) | Optionales Worker-Modul (Raspberry Pi): OCR, PDF/A, KI rund um die Uhr |
| [docs/hosting-befunde.md](docs/hosting-befunde.md) | Ergebnis des Hosting-Checks (M0) |
| [tools/hosting-check.php](tools/hosting-check.php) | Eigenständiges Prüfskript für den Webspace (M0), ohne Abhängigkeiten |

## Lizenz

GPLv3 (erlaubt die Übernahme von Infrastruktur-Code aus dem Vereinskalender).
