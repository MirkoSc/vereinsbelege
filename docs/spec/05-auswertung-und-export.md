# 05 – Auswertungen, ZIP-Export, Archiv-Import

## 1. Auswertungen `/app/auswertung`

Rechnung erfolgt in PHP nach dem Entschlüsseln (Service
`ReportService`, reine Funktionen auf entschlüsselten DTOs → gut testbar).
Für die Datenmenge eines Vereins ausreichend; Ergebnis je Session kurz
zwischengespeichert (verschlüsselt in der Session, nicht in der DB).

Grundlage wählbar: **Belege** (Rechnungsdatum, Brutto) oder **Buchungen**
(Zahlungsfluss, Konto – Default, weil Einnahmen oft ohne Beleg sind).
Zeitraum: Kalenderjahr (= Geschäftsjahr)/Quartal/Monat/frei,
Vorjahresvergleich. Externe Konten sehen nur ihren freigegebenen Zeitraum.

Ansichten:
- **Dashboard**: Einnahmen/Ausgaben/Saldo lfd. Jahr, Kontostände je Konto,
  Posteingang offen, Belege in Prüfung, unbezahlte Rechnungen, Buchungen
  ohne Beleg, offene Erstattungen, überfällige wiederkehrende Belege.
- **Wohin fließt das Geld**: nach Kategorie (Balken + Tabelle, Drilldown
  bis zum Beleg), nach Lieferant (Top 20), nach Kostenstelle/Mannschaft,
  nach Sphäre (nur bei `sphaeren_aktiv`), nach Monat (Verlauf),
  Einnahmen nach Kategorie (inkl. Buchungen ohne Beleg).
- **Wiederkehrende Kosten**: Jahreshochrechnung je Serie, Summe fixer Kosten.
- **Kassenprüfung**: Zeitraum → Vollständigkeitsreport (alle Buchungen mit
  Belegstatus, Salden je Konto am Anfang/Ende, Liste ohne Beleg, Liste
  manuell zugeordneter Fälle) – als druckbare Seite + CSV.
- **Export CSV** jeder Tabellenansicht (UTF-8 mit BOM, Semikolon, deutsches
  Zahlenformat – öffnet in Excel korrekt).

Diagramme: CSS/SVG serverseitig gerendert wie im Vereinskalender, keine
Chart-Bibliothek. Farbe nie das einzige Signal (Werte immer als Text).

## 2. ZIP-Export (`/app/export`, Recht `export.zip`)

- Filter: Zeitraum (Default: Geschäftsjahr), Status (Default: alle
  geprüften), Kategorie, Kostenstelle, Lieferant; Option „Originale
  zusätzlich", Option „nur noch nicht exportierte".
- **Struktur** (Muster als Setting, Default kompatibel zum bisherigen
  manuellen Archiv):

```
Belege_2026/
  index.csv                                  ← Datum;Richtung;Lieferant;Rechnungsnr.;Brutto;Kategorie;Kostenstelle;Status;bezahlt am;Konto;Referenz;Datei
  Bauhaus/
    2026/
      02. Februar/
        Bauhaus 03.02.2026.pdf
      05. Mai/
        Bauhaus 17.05.2026.pdf
        Bauhaus 17.05.2026 (2).pdf           ← gleicher Lieferant + Datum → Suffix (2), (3) …
  Stadtwerke Musterstadt/
    2026/
      01. Januar/
        Stadtwerke Musterstadt 15.01.2026.pdf
  _Ohne Lieferant/2026/…
  _Wiedervorlage/                            ← nur wenn Filter ungeprüfte einschließt
  _Originale/…                               ← gleiche Struktur, nur mit Option
```

Default-Muster: `{lieferant}/{jahr}/{monatsname}/{lieferant} {datum}.pdf`.
Mitgeliefertes Alternativ-Muster (wie das bisherige manuelle Archiv):
`{jahr}/{monatsname}/{lieferant} {datum}.pdf` bzw. mit Kasse-Unterordner
`{jahr}/{monatsname}/{kasse}/…` (`{kasse}` = „Kasse" bei Barbelegen, sonst
leer → Ebene entfällt).

- Platzhalter für das Muster: `{lieferant}`, `{jahr}`, `{monat}` (01),
  `{monatsname}` („01. Januar"), `{kasse}`, `{richtung}` (Ausgaben/Einnahmen), `{datum}` (TT.MM.JJJJ), `{datum_iso}`,
  `{kategorie}`, `{nr}`, `{betrag}`.
- Dateinamen: Umlaute bleiben, für Windows/ZIP verbotene Zeichen
  (`\/:*?"<>|`) → `-`, Länge begrenzt, Kollisionen per Suffix.
- **Umsetzung Muster (M12-1, issue #75):** `App\Service\Export\PfadMuster`
  (Parsen/Prüfen/Auflösen, rein – ohne DB, Session, Tresor; die Werte kommen
  entschlüsselt als `BelegPfadDaten`), `Dateiname` (Bereinigung),
  `PfadVergabe` (Kollisionen), `ExportEinstellungen` (Setting
  `export_pfad_muster`, Admin-Seite `/admin/export`, Recht
  `admin.settings`, Audit `einstellung.export`; Vorschau nur mit erfundenen
  Belegen). Regeln:
  - `/` trennt Ebenen, die letzte ist der Dateiname; ein `.pdf` am Ende ist
    optional – die Endung kommt aus der exportierten Datei (Originale können
    JPEG/PNG sein).
  - Abgelehnt werden: leeres Muster, > 300 Zeichen, `\`, `/` am Anfang/Ende,
    leere Ebenen, Ebenen nur aus `.`/`..`, unbekannte Platzhalter, `{`/`}`
    außerhalb von Platzhaltern, verbotene Zeichen im festen Text.
  - Jeder Platzhalterwert wird **einzeln** bereinigt (verbotene Zeichen und
    Steuerzeichen → `-`, Tab/Zeilenumbruch → Leerzeichen), bevor er
    eingesetzt wird – ein Wert öffnet nie eine eigene Ebene. Danach je Ebene:
    Leerraum zusammengefasst, kein Leerzeichen/Punkt am Ende (Windows; `..`
    wird so leer), höchstens 80 Zeichen, Windows-Gerätenamen (`CON`, `NUL`,
    `COM1` …) bekommen ein `_`. Eine leer gewordene Ordnerebene entfällt
    (nicht nur bei `{kasse}`), ein leerer Dateiname wird zu „Beleg“.
  - Fehlende Werte: `{lieferant}` → „_Ohne Lieferant“ (auch im Dateinamen),
    `{kategorie}` → „_Ohne Kategorie“, `{nr}` → leer. `{betrag}` = Brutto
    „1.234,56“, bei anderer Währung als EUR mit Kürzel („12,00 USD“) –
    Integer-Rechnung, nie `float`.
  - Kollisionen: Suffix ` (2)`, ` (3)` … vor der Endung; Vergleich ohne
    Groß-/Kleinschreibung (Windows/macOS), ein Ordner behält die zuerst
    vergebene Schreibweise; der Name wird gekürzt, nie das Suffix. Die
    Nummerierung folgt der Aufrufreihenfolge – der Export sortiert fest
    (Belegdatum, ID), damit wiederholte Läufe dieselben Namen ergeben.
  - Wurzelordner/ZIP-Name (`ExportEinstellungen::wurzelordner()`) nennen nur
    den Zeitraum („Belege_2026“, sonst „Belege_2026-01-01_bis_2026-03-31“) –
    keine fachlichen Daten in Download-Name, URL oder Header.
- **Kein Klartext auf dem Server**: ZIP wird **gestreamt** erzeugt
  (reine PHP-Zip-Streaming-Bibliothek, z. B. maennchen/zipstream-php –
  prüfen), Dateien werden chunkweise entschlüsselt direkt in den
  Ausgabestrom geschrieben, keine Temp-Datei. Falls der Hosting-Check
  zeigt, dass lange Streams abbrechen: Export in Teilen (je Quartal/Monat)
  anbieten.
- Export wird im Audit-Log protokolliert (Filter, Anzahl, Nutzer).
- **Umsetzung Export (M12-2, issue #76):**
  - **Seite und Rechte:** Seite `/app/export` auf der Anwenderseite, nicht
    unter `/admin`. Grund: Vorstand, Kassenprüfer und Steuerberater haben
    `export.zip`, aber keinen Admin-Bereich.
    - `GET /app/export` zeigt das Filterformular und eine Vorschau (Anzahl
      Belege und Dateien, Größe, ZIP-Name).
    - `POST /app/export/zip` lädt herunter: CSRF, entsperrter Tresor, Audit
      `export.erstellt` (Details: Filter-IDs/Daten, Anzahl Belege und
      Dateien – nie Namen). Der Filter steht als Hidden Fields im Formular.
    - Der Zeitraum-Scope externer Rollen greift auf das Belegdatum in SQL
      (`InvoiceRepository::exportListe()`, sortiert nach Belegdatum, ID).
  - **Filter:** Zeitraum auf das Belegdatum (Default: laufendes
    Kalenderjahr), Status, Kategorie, Kostenstelle, Lieferant (je „ohne“ =
    nicht zugeordnet) und „Originale zusätzlich“. Statusauswahl
    (`ExportStatus`):
    - „geprüft + festgeschrieben“ (Default);
    - „nur festgeschrieben“;
    - „alle außer abgelehnte“. Dabei landen alle nicht geprüften Belege
      unter `_Wiedervorlage/<Muster>`; der Archiv-Import (M12-3) liest den
      Ordner als Wiedervorlage zurück.

    Abgelehnte Belege werden nie exportiert. Belege ohne `invoice`-Zeile
    (noch nicht erfasst) haben weder Datum noch Lieferant und fehlen.
  - **Dateien je Beleg:**
    - Im Hauptbaum liegt die PDF-Arbeitskopie (`document.pdf_blob_id`).
      Ohne Arbeitskopie (gemischte Uploads, mehrere PDFs) stehen dort die
      Originale in Seitenreihenfolge, die Endung kommt aus dem Blob-Typ
      (pdf/jpg/png).
    - Mit der Option liegen zusätzlich alle Originale unter
      `_Originale/<gleicher Pfad>`.
    - Fehlt ein Blob oder ist er unvollständig, steht in `index.csv`
      „(Datei fehlt)“ und die Vorschau warnt.
  - **`index.csv`** liegt im Wurzelordner und ist die erste Datei im ZIP.
    - Format: UTF-8 mit BOM, `;`, CRLF; `App\Service\Export\Csv`, für die
      CSV-Exporte von M11 wiederverwendbar.
    - Brutto wie `{betrag}` („1.234,56“, Fremdwährung mit Kürzel).
    - Textzellen, die mit `=` `+` `-` `@` beginnen, bekommen ein `'`
      vorangestellt (Formel-Schutz – Namen können aus der öffentlichen
      Einreichung stammen).
    - „Datei“ ist relativ zum Wurzelordner, mehrere Dateien sind mit ` | `
      getrennt.
    - **„bezahlt am“, „Konto“, „Referenz“ bleiben leer, `{kasse}` ist immer
      leer**, bis Belege Zahlungen zugeordnet werden (M10-1, #65).
  - **ZIP-Writer:** Eigener Writer `App\Service\Export\ZipStrom` (reines
    PHP auf ext-zlib), keine Bibliothek. maennchen/zipstream-php wurde
    geprüft und verworfen: Das wäre die erste Laufzeit-Abhängigkeit, und
    sie schreibt in einen Ausgabestrom statt in den Generator der
    `StreamResponse`. Der eigene Writer ist klein genug, um ganz geprüft zu
    werden, und schreibt nachweislich nichts auf Platte.
    - Jede Datei: Local Header ohne Größen (Flag-Bit 3), roher
      Deflate-Strom (Stufe 1 für Belege, 6 für `index.csv`), Data
      Descriptor mit CRC-32. Namen in UTF-8 (Bit 11).
    - Deflate statt Store, weil nur ein Deflate-Strom selbst endet; Store
      mit Data Descriptor können streamende Entpacker nicht zuverlässig
      lesen.
    - **Kein ZIP64.** Grenzen je Export: 3 GiB Klartext und 65 000
      Dateien, vorab geprüft. Darüber bittet die Seite, den Zeitraum zu
      verkleinern (z. B. je Quartal). Der Writer wirft `ZipZuGross`,
      statt ein falsches ZIP zu schreiben.
  - **Ablauf des Streams:**
    - Der Generator geht über `StreamResponse`. Vorher gibt
      `Session::schliessen()` die Session-Sperre frei, damit andere Tabs
      nicht warten. Header `X-Accel-Buffering: no`,
      `zlib.output_compression` aus.
    - Vor jeder Datei startet `set_time_limit(30)` das Zeitlimit neu.
    - Wird eine Datei mitten im Strom unlesbar, endet der Strom ohne
      Central Directory: Das ZIP ist erkennbar defekt, nie still
      unvollständig. Das Log enthält nur Klasse und Meldung.
  - **Offen:** Die Option „nur noch nicht exportierte“ braucht eine
    Export-Markierung (Migration) und muss klären, wann ein abgebrochener
    Download als exportiert gilt. Sie ist als eigenes Issue vorgeschlagen.

## 3. Archiv-Import (Bestandsdaten)

Ziel: vorhandenes, bereits sortiertes Archiv (z. B. 400+ PDFs eines
Jahres, benannt `Lieferant TT.MM.JJJJ.pdf`, ggf. in Lieferanten- und
Monatsordnern, „Kasse"-Unterordner) einmalig übernehmen.

- Upload eines ZIPs (Chunk-Upload), Verarbeitung als Schrittkette
  (N Dateien je Schritt).
- Dateiname-Parser mit konfigurierbarem Muster; Default erkennt
  `Lieferant TT.MM.JJJJ( (n))?.pdf` → Lieferant (angelegt/zugeordnet) und
  Datum **vorbelegt**; Ordner `Kasse` → Kennzeichen „bar bezahlt / Konto
  Kasse"; Ordner `Wiedervorlage` → Status Wiedervorlage.
- Danach läuft die normale Pipeline (Text/KI für Beträge, Kategorie), die
  vorbelegten Werte haben Vorrang vor der KI, Abweichungen werden als
  Warnung gezeigt.
- Nutzen: Lieferantenstamm, Kategorien-Regeln und wiederkehrende Serien
  sind nach dem Import sofort gefüllt.

## Pflicht-Tests

Report-Berechnungen (Summen je Kategorie/Lieferant/Monat, Belege vs.
Buchungen, Vorjahresvergleich, Kostenstellen-Scope eines Verantwortlichen);
CSV-Format (BOM, Trennzeichen, Zahlenformat); ZIP-Pfadbildung (Muster-
Platzhalter, verbotene Zeichen, Kollisionssuffix, `_Ohne Lieferant`);
ZIP-Stream ist gültiges ZIP und enthält genau die gefilterten Belege +
index.csv; kein Temp-File mit Klartext (Test prüft `var/tmp` nach Export);
Archiv-Dateinamen-Parser (Varianten, Suffix, ungültige Daten → keine
Vorbelegung), Ordner-Semantik Kasse/Wiedervorlage, Idempotenz bei
wiederholtem Schritt.
