# Scanner-Fixtures

Synthetische Referenzbilder für die reinen Rechenkerne der Bildaufbereitung
(`public/js/scanner/`, Issue #31/M5-1). Kein echtes Belegfoto (CLAUDE.md
§1, E-13) – erzeugt deterministisch aus einem festen Zufalls-Seed, damit die
Dateien reproduzierbar und diff-frei sind.

## `beleg-schatten.pgm` / `beleg-schatten-soll.pgm`

Simuliertes fotografiertes Blatt Papier mit starkem, ungleichmäßigem
Schattenverlauf (Helligkeit ~90 links bis ~230 rechts) plus leichtem
Sensorrauschen und mehreren "Textzeilen" aus blockartigen Strichen. Testet
`bradleySchwelle()` (`public/js/scanner/schwelle.js`, siehe
`tests/js/scanner-schwelle.test.js`): eine globale Schwelle (z. B. 128)
stuft im Schatten Papier fälschlich als Tinte ein (~75 % Trefferquote gegen
die Soll-Maske), die adaptive Schwelle über das Integralbild nicht
(≥ 99 %).

Format: PGM P5 (binär, Graustufen), gelesen/geschrieben über
`pgm.js` in diesem Verzeichnis. `beleg-schatten-soll.pgm` ist die erwartete
Ausgabe von `bradleySchwelle()` in derselben Konvention: 255 = Papier,
0 = Tinte.

Neu erzeugen: `node tests/fixtures/scanner/erzeuge-referenz.js` – rein
deterministisch (fester PRNG-Seed), das Ergebnis ist byte-identisch.

Echte Belegfotos als Fixtures (Kantenerkennungs-Trefferquote) folgen mit
M5-2 (docs/spec/03-erfassung-und-ki.md §2, `docs/ROADMAP.md` M5-2).
