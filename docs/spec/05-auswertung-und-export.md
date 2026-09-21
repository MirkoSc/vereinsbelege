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

## 2. ZIP-Export (Admin / `export.zip`)

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
- **Kein Klartext auf dem Server**: ZIP wird **gestreamt** erzeugt
  (reine PHP-Zip-Streaming-Bibliothek, z. B. maennchen/zipstream-php –
  prüfen), Dateien werden chunkweise entschlüsselt direkt in den
  Ausgabestrom geschrieben, keine Temp-Datei. Falls der Hosting-Check
  zeigt, dass lange Streams abbrechen: Export in Teilen (je Quartal/Monat)
  anbieten.
- Export wird im Audit-Log protokolliert (Filter, Anzahl, Nutzer).

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
