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

**Status:** Planungsphase. Noch kein Code.

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
