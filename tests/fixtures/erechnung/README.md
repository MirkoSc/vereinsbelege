# E-Rechnungs-Fixtures (issue #46/M7-4)

Alle Dateien sind **synthetisch und anonymisiert** (E-13): Namen, Anschriften,
Rechnungs-, Kunden- und Steuernummern sind erfunden, die IBANs sind
veröffentlichte Test-IBANs, die E-Mail-Adressen liegen unter `example.*`.

| Datei | Inhalt |
|---|---|
| `xrechnung-cii.xml` | XRechnung 3.0, CII-Syntax: zwei Steuersätze (19 % / 7 %), Leistungszeitraum, Fälligkeit, IBAN mit Leerzeichen, USt-ID und Steuernummer |
| `xrechnung-ubl.xml` | XRechnung 3.0, UBL-Syntax (`ubl:`-Präfix): ein Steuersatz, Lieferdatum statt Zeitraum, E-Mail im Kontakt und als Endpunkt |
| `gutschrift-ubl.xml` | UBL `CreditNote` im Standard-Namensraum, vollständig vorausbezahlt, zweites `TaxTotal` ohne Aufschlüsselung |
| `zugferd-1.xml` | Kopf einer ZUGFeRD-1.0-Rechnung – bewusst nicht unterstützt |
| `zugferd-en16931.pdf` | ZUGFeRD 2.x / Factur-X (Profil EN 16931): eine Seite sichtbarer Text + `factur-x.xml` als eingebettete Datei (`/Names /EmbeddedFiles` und `/AF`) |

`zugferd-en16931.pdf` ist mit `App\Tests\Support\ERechnungPdfBaukasten` aus
`xrechnung-cii.xml` erzeugt (Profil `urn:cen.eu:en16931:2017`, Rechnungsnummer
`RE-2026-0043`); es ist kein PDF/A-3 und dient nur dem Test des Lesers und dem
manuellen Upload-Test.
