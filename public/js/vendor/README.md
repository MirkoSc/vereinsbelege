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

## pdf.js

Rastert PDF-Seiten im Browser zu Bildern (issue #30/M4-8,
docs/spec/03-erfassung-und-ki.md §3) – der Server hat keine PDF-CLI
(CLAUDE.md §1). Vier Dateien, alle unverändert aus dem npm-Paket
`pdfjs-dist`, unter `pdfjs/`:

| | |
|---|---|
| Version | 6.3.289 |
| Dateien | `pdfjs/pdf.min.mjs`, `pdfjs/pdf.worker.min.mjs`, `pdfjs/wasm/jbig2_nowasm_fallback.js`, `pdfjs/wasm/openjpeg_nowasm_fallback.js` |
| SHA-256 `pdf.min.mjs` | `f80490490320511e5df18c580b9edd6b5db8058dceebaf6f161992e0a964b9e2` |
| SHA-256 `pdf.worker.min.mjs` | `8ab0e5e30031b4a06ecfddd5ae9562f0227f830ee7ec9ed1a968b134243d2386` |
| SHA-256 `wasm/jbig2_nowasm_fallback.js` | `04c795a6657a4553a64b781ea3e85256203d913c3b71b72b85fa3ce00622f458` |
| SHA-256 `wasm/openjpeg_nowasm_fallback.js` | `0f998419819da4491d8302222aa9e2ff2494685641aa2a6c21c3760c29f3e319` |
| Quelle | `https://registry.npmjs.org/pdfjs-dist/-/pdfjs-dist-6.3.289.tgz`, npm-Shasum `9e46d89489782a479f58d674ae5ddde8481aaa17` (npm-Registry-Metadaten, geprüft gegen die heruntergeladene Datei) |
| Projekt | <https://mozilla.github.io/pdf.js/> · <https://github.com/mozilla/pdf.js> |
| Lizenz | Apache-2.0 – GPL-verträglich |

Geprüft vor der Aufnahme (CLAUDE.md §8): reines JavaScript (ES-Module, kein
Build-Schritt nötig), aktiv gepflegt (Mozilla), keine externe URL und keine
`sourceMappingURL` in den vier Dateien, kein `eval(` in den vier Dateien -
unpkg und jsDelivr sind aus dieser Umgebung heraus nicht erreichbar
(Netzwerk-Policy), deshalb wurde stattdessen die npm-Registry direkt
verwendet und der heruntergeladene Tarball gegen deren eigenen Shasum
geprüft, statt zwei CDN-Spiegel zu vergleichen.

Nur ein Teil des npm-Pakets wird ausgeliefert:

- **Nicht dabei:** die `.wasm`-Dateien selbst (JBIG2/OpenJPEG/QCMS) - die CSP
  (`docker/web/.htaccess`) hat kein `wasm-unsafe-eval`, WebAssembly wäre also
  ohnehin blockiert. `public/js/rasterung.js` setzt deshalb `useWasm: false`;
  pdf.js fällt für diese beiden Codecs auf die vendorierten reinen
  JS-Dekoder (`wasm/*_nowasm_fallback.js`) zurück - relevant für gescannte
  PDFs, die häufig JBIG2 (Fax-Kompression) oder gelegentlich JPEG2000
  verwenden.
- **Nicht dabei:** `pdf.sandbox.*.mjs` (PDF-JavaScript-Aktionen) und
  `wasm/quickjs-eval.*` (dessen Laufzeit) - `public/js/rasterung.js` setzt
  `enableScripting: false`, ein Beleg-PDF hat keinen Grund, Code auszuführen.
- **Nicht dabei:** `standard_fonts/*` (Foxit/Liberation-Ersatzschriften für
  PDFs ohne eingebettete Schrift) und die CJK-`cmaps` - ohne sie rendert
  pdf.js mit einer eingebauten Ersatzschrift statt der optisch passenden;
  funktional rendert die Seite trotzdem. Folgeaufgabe, falls das in der
  Praxis stört.

`GlobalWorkerOptions.workerSrc` zeigt auf `pdfjs/pdf.worker.min.mjs?v=<Version>`
(Modul-Worker aus `'self'`, CSP `worker-src 'self' blob:'`); `wasmUrl` in
`getDocument()` zeigt auf `pdfjs/wasm/` für die beiden Fallback-Dateien oben.
Beides über `data-*`-Attribute vom Server befüllt (App\App\
InboxController::rasterungDaten()), nicht über ein Inline-Skript
(CLAUDE.md §4).
