# MT940-Fixtures

Synthetische Kontoauszüge im Aufbau der beiden Hausbanken (Issue #60/M9-2).
**Alle Daten sind erfunden** – Konten, Namen, Gläubiger-IDs, Beträge
(CLAUDE.md §1, E-13).

| Datei | Nachgebildet | Was sie abdeckt |
|---|---|---|
| `sparkasse.sta` | Sparkasse | `:25:` als BLZ/Kontonummer, zwei Auszüge, `:86:` alle 65 Zeichen umbrochen (auch mitten in Teilfeldern), Lastschrift mit `EREF+`/`MREF+`/`CRED+`/`SVWZ+`, `ABWA+`, Rücklastschrift (`RD`), Entgelt mit mehreren Verwendungszweckzeilen ohne SEPA-Schlüssel |
| `vrbank.sta` | VR Bank | `:25:` als IBAN, Auszug über den Jahreswechsel (Valuta/Buchungsdatum in verschiedenen Jahren), `:86:` je Teilfeld eine Zeile, `ABWE+`, `:61:` mit Bankreferenz und Zusatzzeile, unstrukturiertes `:86:`, Rücküberweisung (`RC`) |

Beide Dateien sind – wie von den Banken geliefert – Windows-1252 mit CRLF.
`.gitattributes` nimmt sie von der Zeilenende-Normalisierung aus; nicht in
einem Editor speichern, der das Encoding ändert.

Erzeugt werden sie mit

    php tests/fixtures/mt940/erzeuge.php

Die erwarteten Werte in `tests/Service/Bank/Mt940ParserTest.php` sind von
Hand geschrieben, nicht aus dem Skript abgeleitet.

Liegen anonymisierte Originaldateien der Banken vor, kommen sie als weitere
Dateien hinzu (Konten, Namen, IBANs, Verwendungszwecke ersetzt; Salden
konsistent gehalten), mit eigenen Tests – die synthetischen bleiben.
