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
**Umbuchungen eigene Konten** (M10-6, 04 §5 „Stand M10-6“, E-38) sind
weder Einnahme noch Ausgabe: Auswertungen auf Buchungsbasis lassen
Buchungen mit `doc_source = umbuchung` aus Einnahmen, Ausgaben und
Kategorien heraus; Kontostände und Salden je Konto enthalten sie.

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

### Stand M11-1: ReportService (issue #71, E-44)

- `App\Service\Report\ReportService` ist die reine Berechnung (ohne DB,
  Session, Tresor; Integer-Cent): `summen`, `gruppiert` (Kategorie,
  Lieferant, Kostenstelle, Sphäre, Monat, Konto; optional nur Einnahmen
  oder Ausgaben), `rangliste` (Top N + Übrige + „ohne“), `drilldown`,
  `vergleich` (Vorjahr, Monate auf das aktuelle Jahr verschoben),
  `kontostaende` und `kontosalden` (Anfang/Ende je Konto). Jede Methode
  begrenzt die Zeilen erneut auf den Zeitraum und den `Zugriffsbereich`.
- Eingabe sind Berichtszeilen (`Posten`), gebildet von `Postenbildung`:
  Belegsicht = geprüfte/festgeschriebene Belege nach Rechnungsdatum
  (Gutschrift negativ); Buchungssicht = Buchungen nach Buchungsdatum,
  aufgeteilt nach ihren Zuordnungen (Teil mit Kategorie/Kostenstelle/
  Lieferant des Belegs, Rest mit Kategorie der Buchung), Umbuchungen
  ausgelassen. `Zeitraum` liefert Jahr/Quartal/Monat/frei und `vorjahr()`.
- `App\Service\Report\Auswertungsdaten` lädt und entschlüsselt in der
  Session (SQL filtert nach Struktur und Scope; unlesbare Zeilen und
  Fremdwährung werden gezählt, nicht geraten) und liest `sphaeren_aktiv`.
  Laufzeit: etwa 0,04 ms je Zeile – keine Schrittkette nötig (E-44).

### Stand M11-2: Dashboard und Session-Cache (issue #72, E-48)

- Das **Dashboard ist die Startseite `/app`** (`App\App\DashboardController`,
  `App\Service\Report\Dashboard`). Jahr per `?jahr=` (2000 … laufendes
  Jahr). Jeder Block hat das Recht und den Scope der Seite, auf die er
  verlinkt: Summen `report.view` (Buchungssicht über den `ReportService`),
  Kontostände `bank.view` ohne Kostenstellen-Scope, Zählwerte das Recht
  ihrer Liste („Offen“, Posteingang, Prüfen, Serien). Zählwerte zählen
  Strukturfelder per SQL (`DocumentRepository::anzahlPosteingangOffen()/
  anzahlImStatus()`, `BankTransactionRepository::anzahlOhneBeleg()`,
  `RecurringSeriesRepository::anzahlUeberfaellig()`, `InvoiceRepository::
  offen()`) und erscheinen ohne Tresor; Beträge nur mit entsperrtem Tresor.
  Die Seite wird nie vom Browser gecacht (`Cache-Control: no-store`).
- **Session-Cache für alle Auswertungsseiten:** `App\Service\Report\
  AuswertungsCache` implementiert dieselbe Schnittstelle
  `Auswertungsquelle` wie `Auswertungsdaten` und liegt davor; der
  Bootstrap baut ihn (`$auswertungsquelleFor`) – #73/#74 nehmen ihn von
  dort. Gecacht wird die Eingabe (`Auswertungsgrundlage`,
  Kontobewegungen), nicht das Ergebnis. Ablage nur als Chiffrat in
  `$_SESSION['auswertung_cache']`, Schlüssel je Session an den
  Tresor-Public-Key versiegelt; je Eintrag Grundlage + Zeitraum +
  Zugriffsbereich als ID. Gültig nur für dasselbe Konto, denselben Tresor,
  höchstens 30 Minuten und solange der **Datenstand** gleich ist:
  `App\Repository\AuswertungsStandRepository` bildet ihn vor dem Laden per
  `CHECKSUM TABLE` über `document`, `invoice`, `allocation`,
  `bank_transaction`, `bank_account`, `vault`. Neue Tabellen, aus denen
  Berichtszeilen entstehen, gehören in diese Liste.

### Stand M11-4: Kassenprüfung und CSV (issue #74, E-52)

- **Seite `/app/auswertung/kassenpruefung`** (`App\App\Kassenpruefung
  Controller`, Recht `report.view`, zusätzlich `bank.view` im Controller;
  Navigationspunkt „Kassenprüfung“). Zeitraum per `App\Service\Report\
  ZeitraumAuswahl` (`?art=jahr|quartal|monat|frei`, Jahre 2000 … nächstes
  Jahr, Ungültiges → Standard); Formular als Baustein
  `app/views/partials/zeitraum-filter.php` für alle Auswertungsseiten.
  Standard: der freigegebene Zeitraum des Kontos, sonst das Vorjahr.
- `App\Service\Report\Kassenpruefung` baut den `KassenpruefungBericht`:
  Salden je Konto über `Auswertungsquelle::kontobewegungen()` +
  `ReportService::kontosalden()` (Session-Cache; Anfang bei Zeitraum-Scope
  = Übertrag, E-44); alle Buchungen des Zeitraums (Scope `bank.view` in
  SQL, Umbuchungen markiert) mit Belegstatus und Zuordnungen; der Beleg
  hinter einer Zuordnung nur im Scope von `report.view` benannt
  (`InvoiceRepository::mitDokumentStatus()`, Zeile für Zeile
  `Zugriffsbereich::erlaubt()`). Listen: „ohne Beleg“ (`fehlt`/
  `teilweise`, mit unbelegtem Rest), „von Hand entschieden“ (Zuordnung
  `manuell` oder „kein Beleg nötig“ mit `doc_source = manuell`).
- **CSV:** `GET …/kassenpruefung/{buchungen|salden|ohne-beleg|manuell}.csv`
  (Recht `export.csv` + `report.view` + `bank.view`, entsperrter Tresor,
  sonst 403). `App\Service\Report\Berichtstabelle` ist der allgemeine
  Baustein „Tabelle → CSV“ für alle Auswertungen: Zelle `string` = Text
  (Formelschutz `Csv::text()`), `int` = Cent („1.234,56“), `null` = leer;
  BOM, Semikolon, CRLF. Nur im Speicher, als eine Antwort gesendet,
  `Cache-Control: no-store`; Dateiname nur Teil + Zeitraum.
- Druck: `public/js/drucken.js` (`[data-drucken]`, ohne JS unsichtbar),
  Druck-CSS blendet Formular/Knöpfe aus (`.nicht-drucken`) und zeigt eine
  Kopfzeile mit Zeitraum und Erstellzeit (`.nur-druck`).
- Keine neue Tabelle: `AuswertungsStandRepository::TABELLEN` bleibt.

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
    - Der Download (seit M12-4 `POST /app/export/lauf/{id}/zip`, siehe
      unten) braucht CSRF und einen entsperrten Tresor. Er schreibt Audit
      `export.erstellt` mit Filter-IDs/Daten, Lauf-ID und der Anzahl Belege
      und Dateien, nie Namen. Der Filter steht als Hidden Fields im
      Formular.
    - Der Zeitraum-Scope externer Rollen greift auf das Belegdatum in SQL
      (`InvoiceRepository::exportListe()`, sortiert nach Belegdatum, ID).
      Die Lieferanten-Auswahl zeigt nur Lieferanten von Belegen im Scope
      (`lieferantenImBereich()`), damit eine eingeschränkte Rolle keine
      fremden Namen sieht.
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
      Ohne nutzbare Arbeitskopie (gemischte Uploads, mehrere PDFs, eine nie
      fertige Arbeitskopie) stehen dort die Originale in Seitenreihenfolge,
      die Endung kommt aus dem Blob-Typ (pdf/jpg/png).
    - Mit der Option liegen zusätzlich alle Originale unter
      `_Originale/<gleicher Pfad>`.
    - Vor dem ersten Byte wird geprüft, ob jede Datei vollständig im
      Speicher liegt (Zeile **und** Chiffrat im Backend). Fehlt eine, steht
      für jede fehlende Hauptdatei „(Datei fehlt)“ in `index.csv`, und die
      Vorschau warnt. Eine einzelne verlorene Datei bricht so nicht das
      ganze ZIP.
  - **`index.csv`** liegt im Wurzelordner und ist die erste Datei im ZIP.
    - Format: UTF-8 mit BOM, `;`, CRLF; `App\Service\Export\Csv`, für die
      CSV-Exporte von M11 wiederverwendbar.
    - Brutto wie `{betrag}` („1.234,56“, Fremdwährung mit Kürzel).
    - Textzellen, die mit `=` `+` `-` `@` beginnen, bekommen ein `'`
      vorangestellt (Formel-Schutz – Namen können aus der öffentlichen
      Einreichung stammen).
    - „Datei“ ist relativ zum Wurzelordner, mehrere Dateien sind mit ` | `
      getrennt. Der Formel-Schutz gilt auch hier: Ein Pfad, der mit `-` oder
      `+` beginnt (Muster mit `{betrag}` vorn), bekommt ebenfalls das `'`.
    - **„bezahlt am“, „Konto“, „Referenz“** kommen seit M10-1 (#65) aus den
      Zuordnungen eines **ausgeglichenen** Belegs (04 §5 „Stand M10-1“):
      Datum der letzten ausgleichenden Buchung, Kontonamen und
      Verwendungszwecke (mehrere mit ` | `). Teilbezahlt = leer. `{kasse}`
      bei Zahlungsweg `bar` oder Ausgleich nur über Kassen.
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
- **Umsetzung „nur noch nicht exportierte“ (M12-4, issue #179):**
  - **Semantik:** Der Server streamt das ZIP und erfährt nicht, ob der
    Download vollständig ankam. Deshalb ist jeder Export ein
    **Export-Lauf** (`export_run` + `export_run_document`, Migration 029,
    nur Klartext-Struktur). Ein Beleg gilt als exportiert, sobald ein
    Lauf mit ihm **bestätigt** ist („Vollständig gespeichert – als
    exportiert markieren“). Ein abgebrochener oder nie bestätigter Download
    markiert nichts. Verworfen wurden:
    - eine Markierung am Ende des Streams, weil ein Abbruch nach dem letzten
      Byte unbemerkt bliebe;
    - ein Lauf nur in der Session, weil er weder nachvollziehbar noch
      rücksetzbar wäre.
  - **Reichweite:** Die Markierung gilt **für den ganzen Verein**, nicht je
    Benutzer. Wer nur zum Ansehen exportiert (Kassenprüfer, Steuerberater),
    bestätigt nicht.
  - **Ablauf:**
    - „Export erstellen“ (`POST /app/export/lauf`) prüft wie bisher
      Tresor, Leere und Größe. Dann legt es den Lauf (`offen`) mit den
      Dokument-IDs der Vorschau an und leitet auf die Laufseite
      `GET /app/export/lauf/{id}`.
    - Dort lädt `POST …/zip` das ZIP herunter, beliebig oft. Jeder Download
      schreibt Audit `export.erstellt` mit Lauf-ID. Ein erneuter Download
      liefert die Belege des Laufs (ohne „nur neue“), auch wenn ein anderer
      Lauf sie inzwischen bestätigt hat. Der aktuelle Scope greift trotzdem.
    - `POST …/bestaetigen` (`offen` → `bestaetigt`) schreibt Audit
      `export.markiert`, `POST …/zuruecksetzen` (`bestaetigt` →
      `zurueckgesetzt`) schreibt `export.zurueckgesetzt`. Beide Einträge
      nennen den Lauf (`entity` `export_run`) und die Anzahl Belege, nie
      Namen.
    - Beide Wechsel sind idempotent: Das bedingte UPDATE nennt den
      Ausgangsstatus, ein zweiter Klick ändert nichts und schreibt kein
      zweites Audit.
    - Ein zurückgesetzter Lauf wird nicht erneut bestätigt; dafür gibt es
      einen neuen Export.
    - Bestätigen und Zurücksetzen brauchen keinen Tresor (nur IDs),
      Erstellen und Herunterladen schon.
  - **Filter:** Die Option `nur_neue` lässt in SQL jedes Dokument weg, das
    ein `bestaetigt`er Lauf enthält (`NOT EXISTS`, zusammen mit Zeitraum-Scope
    und übrigen Filtern, `InvoiceRepository::exportListe()`). Wer einen Lauf
    zurücksetzt, macht dessen Belege wieder „neu“ – außer ein anderer
    bestätigter Lauf enthält sie auch.
  - **Sichtbarkeit:** `/app/export` listet die letzten 10 Läufe. Sichtbar
    sind die eigenen; wer `export.zip` ohne Scope hat, sieht alle. Für
    Laufseite, Download, Bestätigen und Zurücksetzen gilt dieselbe Regel,
    sonst antwortet der Server mit 404. Ein Steuerberater mit Zeitraum-Scope
    sieht so nur seine eigenen Läufe. Alle Routen verlangen `export.zip`.
  - Offene Läufe bleiben als Verlauf stehen. Sie markieren nichts, ein
    Aufräumen ist nicht nötig.
- **Nextcloud-Ablage (issue #95):** Statt das ZIP herunter- und wieder
  hochzuladen, legt die Laufseite einen Export-Lauf direkt in einen Ordner
  der Vereins-Nextcloud (WebDAV). Entscheidungen (vorläufig, autonom
  getroffen – Bestätigung durch Mirko offen, E-22):
  - **Kein ZIP auf dem Server.** Ein serverseitig erzeugtes ZIP bräuchte
    Klartext auf Platte oder einen langen Request; die Ausnahme oben gilt
    nur für den Browser-Download. Stattdessen geht **jede Datei einzeln**
    hoch – mit genau den Pfaden und Namen des ZIP (`ZipExport::
    vorbereiten()`, Muster M12-1, inkl. `_Originale`/`_Wiedervorlage`),
    `index.csv` **zuletzt**. Ziel: `<Ordner>/Belege_2026/…`. Entschlüsselt
    wird chunkweise direkt in den PUT (`CURLOPT_READFUNCTION`), nichts
    landet auf Platte.
  - **Schrittkette in der Nutzer-Session** (CLAUDE.md §1, §4):
    `POST /app/export/lauf/{id}/nextcloud` prüft Ziel und Ordner (PROPFIND)
    und legt `export_run_upload` an (bzw. setzt sie zurück);
    `public/js/nextcloud.js` ruft danach `POST …/nextcloud/schritt` (JSON)
    auf, bis `fertig`. Ein Schritt legt höchstens 20 Dateien ab und beginnt
    nach 8 s keine neue; der Cursor rückt **nach jeder Datei** per
    bedingtem UPDATE (`WHERE next_index = ?`) – zwei Tabs zählen nichts
    doppelt. Ein fehlgeschlagener Schritt (Netz, 401, 507 …) bewegt nichts,
    der nächste versucht dieselbe Datei; eine unlesbare Datei (Blob defekt)
    meldet sich als Fehler, nicht als HTTP 500. Ändert sich der Plan
    unterwegs (ein Beleg gelöscht, abgelehnt, umdatiert, sein PDF fertig –
    erkannt an `plan_hash`, einem HMAC mit dem Blind-Index-Schlüssel des
    Tresors über Pfade und Blob-IDs), beginnt die Kette
    von vorn; schon Abgelegtes zählt dann als vorhanden, nichts wird
    übersprungen oder vorzeitig fertig. Ein geschlossener Tab hält einfach
    an; die Seite bietet „Weiter ablegen“.
  - **Nie überschreiben, schon Vorhandenes überspringen.** Je Datei:
    PROPFIND auf den Zielnamen. Nichts da → fehlende Ordner per MKCOL
    (405 = gibt es schon), PUT mit `OC-Checksum: SHA1:…`, danach Größe per
    PROPFIND prüfen. Dieselbe Datei da (SHA-1 aus `oc:checksums`; ohne
    Prüfsumme – von Hand abgelegt – bei gleicher Größe per GET der Inhalt)
    → übersprungen („vorhanden“).
    Eine **andere** Datei da → nächster freier Name „ (2)“, „ (3)“ …
    (wie die Kollisionen im ZIP, höchstens 50 Varianten), gezählt als
    „umbenannt“. Die `index.csv` nennt dann den vom Muster vergebenen Namen;
    die Laufseite weist darauf hin. Typischer Fall: ein Lauf „nur noch nicht
    exportierte“ in denselben Jahresordner – seine `index.csv` wird so zu
    `index (2).csv`, die erste bleibt.
  - **„Nur noch nicht exportierte“** wirkt über den Lauf: abgelegt werden
    die Belege des Laufs. Die Ablage **markiert nichts**; bestätigt wird wie
    beim Download von Hand („Vollständig gespeichert“). Audit
    `export.nextcloud` (Entity `export_run`) beim Abschluss mit Anzahl
    Belege/Dateien und den drei Zählern, nie Namen.
  - **Rechte:** `export.zip` **ohne Scope** – der Ordner ist das Archiv
    des Vereins; wer nur eingeschränkt exportieren darf (z. B. ein
    Steuerberater mit Zeitraum-Scope), sieht den Abschnitt nicht, die
    Routen antworten 404. Tresor nötig (Ziel und Belege), CSRF auf beiden Routen.
  - **Ziel** (`nextcloud_target`, 02 „Fachdaten“): WebDAV-Adresse des
    Ordners, Benutzer, **App-Passwort** – im **Tresor** (`data_enc`, nur in
    der Session lesbar), nicht mit dem Server-Schlüssel wie die KI-Keys:
    das Passwort öffnet das Vereinsarchiv, und ohne Tresor gibt es ohnehin
    nichts abzulegen. Seite `/admin/export/nextcloud` (Recht
    `admin.settings`, verlinkt von `/admin/export`): Speichern (leeres
    Passwortfeld behält das alte – nur bei unveränderter Adresse, damit es
    nie an einen anderen Server geht; das Passwort geht nie zurück an den
    Browser), „Verbindung testen“ (PROPFIND auf den Ordner), Entfernen.
    Audit `einstellung.nextcloud` nur mit `aktion` (`gespeichert`/
    `entfernt`), ohne Adresse oder Benutzer. Regeln
    (`NextcloudZiel::pruefen()`): nur `https://`, ohne Zugangsdaten, Query,
    Fragment, Leer- und Steuerzeichen, ≤ 500 Zeichen; Benutzer ohne „:“.
  - **HTTP:** `App\Service\Export\Nextcloud\CurlWebDav` über ext-curl
    (keine Prozessaufrufe), Basic Auth, nur HTTPS, **keine Redirects** (die
    Zugangsdaten gingen mit), kurze Timeouts (PUT 25 s, sonst 20 s). Die
    PROPFIND-Antwort liest `DavAntwort` mit DOM ohne Netz und ohne
    Entitäten (`DOCTYPE` wird abgelehnt), höchstens 1 MiB. Fehlermeldungen
    nennen nie einen Pfad.

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
- **Umsetzung (M12-3, issue #77):**
  - **Seite und Rechte:** `/app/archiv-import` auf der Anwenderseite, Recht
    `archive.import` (Admin, Finanzen) auf allen Routen, CSRF auf jedem
    POST, ohne entsperrten Tresor wird nichts gelesen oder geschrieben. Der
    Einstieg ist ein Link im Posteingang. Ablauf: Upload → Vorschau (nichts
    gespeichert) → „Import starten“ → seitengesteuerte Schrittkette wie der
    Kontoauszug-Import (06 §4) → Ergebnis mit Bericht.
  - **Upload:** Chunk-Upload (`public/js/upload.js`) unter eigenem Präfix
    `/app/archiv-import/upload…`: Öffnen, Chunks und Abbruch sind der
    gemeinsame `App\Api\UploadController` mit größerer Grenze
    (`ArchivImport::MAX_ZIP_BYTES` = 2 GiB, `UploadService` mit
    `maxBytes`); nur der Abschluss ist eigen – er liest das
    Inhaltsverzeichnis und legt `archive_import` als `vorschau` an, ein
    Nicht-ZIP wird mit Meldung abgelehnt und sofort gelöscht.
  - **Kein zusammengesetztes ZIP, keine Temp-Datei:** Das ZIP bleibt als
    Upload-Chunks in `shared/var/tmp/upload/<id>/` (die Klartext-Ausnahme
    von CLAUDE.md §2) und wird dort gelesen: eigener Leser
    `App\Service\Archiv\ZipLeser` in reinem PHP auf ext-zlib
    (`UploadService::lesen()` liest über Chunk-Grenzen). `ZipArchive`
    scheidet aus (braucht eine Datei am Stück), ein verschlüsselter
    ZIP-Blob auch (secretstream liest nur von vorn – jeder Schritt müsste
    ab Byte 0 entschlüsseln). Jede Datei wird stückweise entpackt und direkt
    an `BlobService::store()` gegeben. Ist der Import `fertig` oder
    `abgebrochen`, werden die Chunks gelöscht; jeder Schritt
    „berührt“ den Upload, die 24-h-Regel des Crons zählt also ab dem letzten
    Schritt. Fehlen die Chunks, endet der Import als `abgebrochen` mit
    Hinweis; Übernommenes bleibt.
  - **ZIP-Leser:** Methoden 0 und 8, mit oder ohne Data Descriptor
    (Größen und CRC aus dem Inhaltsverzeichnis); Namen mit UTF-8-Flag, sonst
    gültiges UTF-8 (macOS), sonst CP850 (deutsches Windows), Unicode-Path-
    Feld `0x7075` bei passender Prüfsumme; danach NFC (ext-intl, sonst
    eigene Tabelle der gängigen Umlaute/Akzente). Abgelehnt als Ganzes:
    ZIP64, mehrteilige Archive, > 20 000 Einträge. Je Eintrag übersprungen:
    verschlüsselt, anderes Verfahren, Pfad mit `..`/absolut, größer als
    32 MiB. CRC-32 und angekündigte Größe werden beim Entpacken geprüft,
    Inflate in 8-KiB-Scheiben (ZIP-Bomben brechen sofort ab).
  - **Dateinamen-Muster** (`App\Service\Archiv\DateinamenMuster`, je
    Import auf der Vorschau einstellbar, Default `{lieferant} {datum}`):
    Platzhalter `{lieferant}`, `{datum}` (T.M.JJJJ/TT.MM.JJJJ),
    `{datum_iso}`, `{nr}`, `{egal}`; die Endung und ein Suffix ` (2)`/`(3)`
    zählen nicht, ein Leerzeichen im Muster passt auf Leerzeichen und `_`.
    Passt der Name nicht, wird aus ihm nichts vorbelegt. Ein unmögliches
    Datum oder ein Jahr außerhalb 1990 bis nächstes Jahr gibt kein Datum,
    aber einen Hinweis; der Lieferant bleibt. `_Ohne Lieferant` heißt: kein
    Lieferant.
  - **Ordner-Semantik** (`App\Service\Archiv\ArchivPfad`, rein):
    - `Kasse` → bar bezahlt;
    - `Wiedervorlage`/`_Wiedervorlage` → Status `wiedervorlage`, fällig am
      Importtag;
    - `Ausgaben`/`Einnahmen` → Richtung;
    - Jahres- (`2026`, `Belege_2026`) und Monatsordner (`02`, `02. Februar`,
      `Februar`, `Feb`) prüfen nur das Datum aus dem Dateinamen –
      Abweichung = Hinweis, nie ein Datum aus Ordnern allein;
    - ein Ordner mit dem Namen einer aktiven Kategorie der Richtung → diese
      Kategorie (der tiefste gewinnt);
    - `_Originale/` (Kopien aus dem eigenen Export), `__MACOSX/`,
      versteckte Dateien, `Thumbs.db`, `desktop.ini` → übersprungen;
    - alle übrigen Ordner (z. B. Lieferantenordner) werden ignoriert.
  - **Je Datei** (`App\Service\Archiv\ArchivImport`): nur PDF/JPEG/PNG
    (bzw. E-Rechnungs-XML, `MagicBytes`), sonst Eintrag im Bericht.
    Lieferant über den Blind Index des Namens
    (`SupplierService::idsZuNamen()`, Rechtsform/Umlaute/Groß-
    schreibung egal, Rolle passend zur Richtung): genau einer → zugeordnet,
    keiner → neu angelegt mit `created_via = archiv`
    (`anlegenAusArchiv()`, Audit `lieferant.angelegt`), mehrere → nicht
    zugeordnet, Hinweis. Dann `document` (`source = archiv`, Status
    `bereit_zur_auswertung` – das Archiv ist sortiert, kein Posteingang –
    bzw. `wiedervorlage`), das Vorgabe-Artefakt (02, Kind `vorgabe`,
    Format `archiv-v1`) und die drei Session-Jobs `pdf_erzeugen`,
    `detect_duplicate`, `extract_text` wie bei jeder Erfassung. Ein
    zweimal importiertes PDF fällt über die Duplikaterkennung auf.
  - **Idempotenz:** eine Transaktion je Datei, eröffnet mit
    `ArchiveImportRepository::advance()` (`next_index` nur vom gelesenen
    Wert aus, Zeilensperre) – ein wiederholter oder paralleler Schritt
    schreibt nichts doppelt. Der Blob wird vor der Transaktion gespeichert
    und gelöscht, wenn sie verliert. Budget je Schritt: 10 Dateien, 24 MiB
    entpackt oder 15 s.
  - **Vorrang vor der automatischen Erkennung:** Auf der Prüfseite legt
    `ArchivVorgabe::ueberlagern()` die Archivwerte über das, was die
    E-Rechnung (bzw. ab M7-5 die KI, die vor der Überlagerung läuft)
    gelesen hat – solange noch keine `invoice`-Zeile existiert. Weicht ein
    gelesener Wert ab, gewinnt das Archiv, und ein Hinweis nennt beide
    Werte. Dazu: Herkunftspfad, Hinweise aus dem Pfad und für `Kasse` der
    Hinweis „bar bezahlt“ und – seit M10-1 (#65) – die Vorbelegung des
    Zahlungswegs mit „bar bezahlt (ohne Kassenbuchung)“ (04 §5).
  - **Bericht und Audit:** Nicht übernommene Dateien stehen mit Grund
    verschlüsselt in `archive_import.report_enc` (höchstens 2 000), die
    Seite zeigt sie nach dem Import. Audit `archiv.importiert` genau einmal
    am Ende (bzw. beim Abbruch, wenn schon Belege angelegt sind): Status,
    Zähler, erste/letzte Dokument-ID – nie ein Name.
  - **Probelauf:** synthetisch (420 erfundene PDFs, 44,5 MB, beide Layouts
    mit Kasse/Wiedervorlage) im Docker gegen MariaDB: 43 Schritte, gesamt
    3,8 s, Schritt im Median 0,09 s. Der Probelauf mit einem echten
    Jahresarchiv wird nur mit Zahlen (keine Namen) im Issue festgehalten.

## Pflicht-Tests

Report-Berechnungen (Summen je Kategorie/Lieferant/Monat, Belege vs.
Buchungen, Vorjahresvergleich, Kostenstellen-Scope eines Verantwortlichen);
umgesetzt (M11-1): `tests/Service/Report/` (`ReportServiceTest`,
`ZeitraumTest`), `tests/Integration/AuswertungsdatenTest.php`;
Dashboard und Session-Cache (M11-2): `tests/Integration/DashboardFlowTest.php`
(Summen, Kontostände, Zählwerte, Jahreswahl, Kostenstellen- und
Zeitraum-Scope, gesperrter Tresor, keine veraltete Zahl nach einer Änderung
– auch nach dem Löschen einer Zuordnung), `tests/Service/Report/
AuswertungsCacheTest.php` (Treffer, Datenstand/Konto/Tresor/Scope/Alter,
nur Chiffrat in der Session, Manipulation, Grenzen);
CSV-Format (BOM, Trennzeichen, Zahlenformat) – umgesetzt (M11-4):
`tests/Service/Report/BerichtstabelleTest.php` (inkl. Formelschutz,
Dateiname), `tests/Service/Report/ZeitraumAuswahlTest.php`,
`tests/Integration/KassenpruefungFlowTest.php` (Salden Anfang/Ende,
ohne Beleg mit Rest, manuelle Fälle, alle Buchungen mit Status, CSV je
Tabelle, Zeitraum-Scope des Kassenprüfers mit Übertrag und verborgenem
Beleg außerhalb, ohne `bank.view`/`export.csv`, gesperrter Tresor,
ungültiger Zeitraum), `tests/js/drucken.test.js`; ZIP-Pfadbildung (Muster-
Platzhalter, verbotene Zeichen, Kollisionssuffix, `_Ohne Lieferant`);
ZIP-Stream ist gültiges ZIP und enthält genau die gefilterten Belege +
index.csv; kein Temp-File mit Klartext (Test prüft `var/tmp` nach Export);
Archiv-Dateinamen-Parser (Varianten, Suffix, ungültige Daten → keine
Vorbelegung), Ordner-Semantik Kasse/Wiedervorlage, Idempotenz bei
wiederholtem Schritt. Umgesetzt (M12-3): `tests/Service/Archiv/`
(`DateinamenMusterTest`, `ArchivPfadTest`, `ZipLeserTest`,
`ArchivVorgabeTest`), `tests/Integration/ArchivImportFlowTest.php`
(ganzer Ablauf, Idempotenz, kein Klartext in `var/tmp` nach dem Import),
Prüfseite in `PruefungFlowTest`, `tests/js/archivimport.test.js`.
Umgesetzt (M12-2/M12-4): `tests/Integration/ExportZipFlowTest.php` –
gültiges ZIP mit genau den gefilterten Belegen, kein Klartext in `var/tmp`.
Für „nur noch nicht exportierte“ prüft er:
- **Abbruch:** Ein abgebrochener oder unbestätigter Download markiert nichts.
- **Idempotenz:** Doppeltes Bestätigen bzw. Zurücksetzen schreibt je ein Audit.
- **Wiederholung:** Ein erneuter Download eines Laufs liefert dessen Belege.
- **Scope:** Die Option wirkt zusammen mit dem Zeitraum-Scope; fremde Läufe
  ergeben 404 für eingeschränkte Rollen.
- **CSRF** ist auf allen Lauf-Routen Pflicht.
Umgesetzt (issue #95, Nextcloud-Ablage): `tests/Integration/NextcloudAblageFlowTest.php`
gegen einen nachgebauten WebDAV-Ordner (`tests/Support/FakeWebDav.php`):
- **Gleiche Pfade wie das ZIP**, `index.csv` zuletzt, in mehreren kurzen
  Schritten; ein Audit; kein Klartext in `var/tmp`; der Lauf bleibt offen.
- **Idempotenz:** Erneutes Ablegen lädt nichts hoch (Prüfsumme); ein
  fehlgeschlagener Schritt bewegt den Cursor nicht; der Cursor rückt nur
  von dort weiter, wo er gelesen wurde (zwei Tabs).
- **Nie überschreiben:** fremde Datei gleichen Namens bleibt, unsere wird
  „ (2)“; von Hand abgelegte gleiche Datei zählt als vorhanden, gleich
  große andere nicht; ein unterwegs geänderter Plan beginnt von vorn.
- **Ziel im Tresor**, Passwort nie in der Seite, Audit ohne Adresse;
  `http://` und Zugangsdaten in der Adresse werden abgelehnt.
- **Rechte/CSRF/Tresor:** Scope-Rolle 404, ohne Tresor bzw. Token nichts.
Dazu `tests/Service/Export/Nextcloud/` (Adressprüfung, PROPFIND-Antwort inkl.
XXE-Versuch, Namensvarianten) und `tests/js/nextcloud.test.js`. Die echte
Vereins-Nextcloud ist eine manuelle Abnahme (Prüfliste im PR).
