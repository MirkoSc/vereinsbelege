# Vendored JavaScript-Bibliotheken

Kein Build-Schritt auf dem Server und kein CDN: Die CSP erlaubt nur
`script-src 'self'` (CLAUDE.md §4), und ein CDN würde dem Anbieter jeden
Aufruf einer Seite melden, auf der entschlüsselte Belege stehen. Jede
Bibliothek liegt deshalb unverändert hier und wird mit dem Release-ZIP
ausgeliefert.

`tests/View/VendorAssetsTest.php` prüft, dass die Dateien unverändert sind –
die Prüfsummen unten sind der Sollwert.

## htmx

| | |
|---|---|
| Version | 2.0.10 |
| Datei | `htmx.min.js` |
| SHA-256 | `71ea67185bfa8c98c39d31717c6fce5d852370fcdfd129db4543774d3145c0de` |
| Quelle | <https://unpkg.com/htmx.org@2.0.10/dist/htmx.min.js> |
| Projekt | <https://htmx.org/> · <https://github.com/bigskysoftware/htmx> |
| Lizenz | 0BSD (BSD Zero Clause) – GPL-verträglich |

Geprüft vor der Aufnahme (CLAUDE.md §8): reines JavaScript ohne Build-Schritt
und ohne Abhängigkeiten, keine `eval`-Nutzung (die CSP kommt ohne
`unsafe-eval` aus), keine externe URL und keine `sourceMappingURL` in der
Datei, aktiv gepflegt. Die Bytes von unpkg und jsDelivr wurden verglichen und
sind identisch.

Konfiguriert wird htmx über `<meta name="htmx-config">` im Layout, nicht über
ein Skript: `includeIndicatorStyles: false` (E-07, die Indikator-Styles stehen
handgeschrieben in `public/css/app.css`) und `selfRequestsOnly: true`.
