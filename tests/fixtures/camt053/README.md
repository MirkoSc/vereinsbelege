# CAMT.053-Fixtures

Synthetische Kontoauszüge nach ISO 20022 (Issue #91). **Alle Daten sind
erfunden** – Konten, Namen, Gläubiger-IDs, Beträge (CLAUDE.md §1, E-13).

| Datei | Version | Was sie abdeckt |
|---|---|---|
| `sparkasse-053-001-02.xml` | camt.053.001.02 | dieselben zwei Auszüge wie `../mt940/sparkasse.sta` (Formatwechsel MT940 → CAMT ohne neue Buchungen), `PRCD`/`CLBD`, `<Sts>BOOK</Sts>`, `BIC`, Lastschrift mit `EndToEndId`/`MndtId`/Gläubiger-ID, `UltmtDbtr`, `NOTPROVIDED`, Rücklastschrift (`CRDT` + `RvslInd`), Entgelt mit zwei `Ustrd` |
| `vrbank-053-001-08.xml` | camt.053.001.08 | `OPBD`/`CLBD` über den Jahreswechsel, `<Sts><Cd>`, Parteien mit `<Pty>`, `BICFI`, `DtTm`, vorgemerkter Eintrag (`PDNG`), Rücküberweisung (`DBIT` + `RvslInd`), Sammelbuchung mit zwei `TxDtls`, nur strukturierte Referenz, `Ustrd` mit vollen 140 Zeichen, `UltmtCdtr` |
| `tagesauszuege-053-001-04.zip` | camt.053.001.04 | zwei Tagesauszüge als einzelne Dateien (neueste zuerst gespeichert, `.XML` groß geschrieben), negativer Anfangssaldo, dazu eine Textdatei und macOS-Metadaten, die keine Auszüge sind |

Erzeugt werden sie mit

    php tests/fixtures/camt053/erzeuge.php

(braucht ext-zip für das ZIP). Die erwarteten Werte in
`tests/Service/Bank/Camt053ParserTest.php` sind von Hand geschrieben, nicht
aus dem Skript abgeleitet. ZIPs, die die Tests selbst bauen, schreibt
`Camt053ParserTest::zip()` ohne ext-zip.

Liegen anonymisierte Originaldateien der Banken vor, kommen sie als weitere
Dateien hinzu (Konten, Namen, IBANs, Verwendungszwecke ersetzt; Salden
konsistent gehalten), mit eigenen Tests – die synthetischen bleiben.
