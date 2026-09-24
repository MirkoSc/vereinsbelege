// Generates the synthetic scanner-threshold fixtures deterministically (no
// real receipt data ships in this public repo, CLAUDE.md section 1 E-13):
// a photographed sheet of paper with a strong, uneven shadow gradient plus
// light sensor noise and several blocky "text lines" - the case a fixed,
// global threshold gets wrong but public/js/scanner/schwelle.js's
// bradleySchwelle() should not (see tests/js/scanner-schwelle.test.js).
//
// Run directly (`node tests/fixtures/scanner/erzeuge-referenz.js`) to
// rewrite beleg-schatten.pgm / beleg-schatten-soll.pgm; re-running produces
// byte-identical files, since everything here is seeded, not random.

const path = require('node:path');
const { schreibePgm } = require('./pgm.js');

const BREITE = 200;
const HOEHE = 280;
const SAAT = 20260921; // beliebig, nur fix - hält die Fixture stabil

/** mulberry32: ein kleiner, deterministischer PRNG ohne Abhängigkeiten. */
function pseudorng(saat) {
    let zustand = saat >>> 0;

    return function () {
        zustand = (zustand + 0x6d2b79f5) >>> 0;
        let t = zustand;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);

        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

/** Beleuchtung an Spalte x: ~90 (Schatten links) bis ~230 (hell rechts). */
function lichtAn(x) {
    return 90 + (x / (BREITE - 1)) * 140;
}

/**
 * Baut Graustufenbild + Soll-Maske. Die Maske folgt der Konvention von
 * bradleySchwelle(): 255 = Papier, 0 = Tinte - direkt vergleichbar mit
 * deren Ausgabe.
 */
function erzeugeReferenz() {
    const zufall = pseudorng(SAAT);
    const grau = new Uint8ClampedArray(BREITE * HOEHE);
    const maskeSoll = new Uint8ClampedArray(BREITE * HOEHE);

    const zeilenHoehe = 10;
    const zeilenAbstand = 24;
    const buchstabeBreite = 6;
    const anzahlSpalten = Math.ceil(BREITE / buchstabeBreite);

    // Ob eine "Buchstaben-Zelle" (Zeile, Spalte) Tinte trägt, einmal fest
    // ausgewürfelt - ein Münzwurf pro Pixel wäre nur Rauschen, keine Form,
    // die eine lokale Schwelle überhaupt erkennen könnte.
    const tintenZellen = [];
    for (let y = zeilenHoehe / 2; y < HOEHE; y += zeilenAbstand) {
        const zeile = [];
        for (let s = 0; s < anzahlSpalten; s++) {
            zeile.push(zufall() < 0.45);
        }
        tintenZellen.push(zeile);
    }

    for (let y = 0; y < HOEHE; y++) {
        const zeilenIndex = Math.floor((y - 4) / zeilenAbstand);
        const positionInZeile = y - 4 - zeilenIndex * zeilenAbstand;
        const inZeile = zeilenIndex >= 0 && zeilenIndex < tintenZellen.length
            && positionInZeile >= 1 && positionInZeile < zeilenHoehe - 1;

        for (let x = 0; x < BREITE; x++) {
            const idx = y * BREITE + x;
            const hintergrund = lichtAn(x);
            const rauschen = (zufall() - 0.5) * 8;

            const spalte = Math.floor(x / buchstabeBreite);
            const randInZelle = x % buchstabeBreite;
            const istTinte = inZeile && randInZelle >= 1 && randInZelle < buchstabeBreite - 1
                && tintenZellen[zeilenIndex][spalte];

            grau[idx] = istTinte
                ? Math.max(8, Math.round(hintergrund - 130 + rauschen))
                : Math.min(255, Math.round(hintergrund + rauschen));
            maskeSoll[idx] = istTinte ? 0 : 255;
        }
    }

    return { breite: BREITE, hoehe: HOEHE, grau, maskeSoll };
}

if (require.main === module) {
    const { breite, hoehe, grau, maskeSoll } = erzeugeReferenz();
    schreibePgm(path.join(__dirname, 'beleg-schatten.pgm'), breite, hoehe, grau);
    schreibePgm(path.join(__dirname, 'beleg-schatten-soll.pgm'), breite, hoehe, maskeSoll);
    console.log('Fixtures erzeugt: beleg-schatten.pgm, beleg-schatten-soll.pgm');
}

module.exports = { erzeugeReferenz, BREITE, HOEHE };
