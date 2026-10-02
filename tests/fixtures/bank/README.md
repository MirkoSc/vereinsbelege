# CSV-Fixtures (Kontoauszug-Export)

Synthetische CSV-Exporte im Aufbau der beiden Hausbanken und einer
unbekannten Bank (Issue #61/M9-3). **Alle Daten sind erfunden** – Konten,
Namen, Gläubiger-IDs, Beträge (CLAUDE.md §1, E-13); die IBANs sind die
bekannten Dokumentationsbeispiele.

| Datei | Nachgebildet | Was sie abdeckt |
|---|---|---|
| `sparkasse-csv-camt-v2.csv` | Sparkasse „CSV-CAMT V2“ | Windows-1252, CRLF, `;`, alle Zellen in Anführungszeichen, `TT.MM.JJ`, Dezimalkomma, Tausenderpunkt, Trennzeichen und Zeilenumbruch innerhalb einer Zelle, Umlaute, SEPA-Spalten (EREF/MREF/Gläubiger-ID), vorgemerkter Umsatz (`Info` = „Umsatz vorgemerkt“), Valuta im Vorjahr |
| `sparkasse-csv-camt-v8.csv` | Sparkasse „CSV-CAMT V8“ (**angenommen**) | wie V2, aber zusätzliche Spalte am Ende und vierstellige Jahre |
| `vrbank.csv` | VR Bank (Atruvia) | UTF-8 mit BOM, `;`, `TT.MM.JJJJ`, eigenes Konto in eigenen Spalten, „Saldo nach Buchung“ |
| `unbekannt.csv` | keine Bank | `,`, UTF-8, Vorspann vor der Kopfzeile, ISO-Datum, Dezimalpunkt mit Tausenderkomma, getrennte Soll/Haben-Spalten, zwei Verwendungszweck-Spalten, eine Zeile ohne Betrag |

Die Kopfzeilen von Sparkasse V2 und VR Bank entsprechen den öffentlich
dokumentierten Exporten. Wie sich V8 von V2 unterscheidet, ist nicht
öffentlich beschrieben – die V8-Datei ist eine Annahme (zusätzliche Spalte,
vierstellige Jahre). Das mitgelieferte Profil ordnet Spalten über ihren
Namen zu, Reihenfolge und Zusatzspalten spielen also keine Rolle.

Erzeugt werden die Dateien mit

    php tests/fixtures/bank/erzeuge.php

Die erwarteten Werte in `tests/Service/Bank/CsvParserTest.php` sind von
Hand geschrieben, nicht aus dem Skript abgeleitet. Nicht in einem Editor
speichern, der Zeichensatz oder Zeilenenden ändert.

Liegen anonymisierte Originaldateien der Banken vor, kommen sie als weitere
Dateien hinzu (Konten, Namen, IBANs, Verwendungszwecke ersetzt), mit
eigenen Tests – die synthetischen bleiben.
