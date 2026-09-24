// docs/spec/03-erfassung-und-ki.md section 2, "Pflicht-Tests": Schwelle auf
// Referenzbild (tests/fixtures/scanner/beleg-schatten.pgm).

const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const {
    graustufen,
    graustufenBild,
    integralbild,
    bradleySchwelle,
    schwarzweiss,
} = require('../../public/js/scanner/schwelle.js');
const { lesePgm } = require('../fixtures/scanner/pgm.js');

/** A flat RGBA image filled by `fn(x, y)` -> [r, g, b, a]. */
function bildErzeugen(breite, hoehe, fn) {
    const data = new Uint8ClampedArray(breite * hoehe * 4);
    for (let y = 0; y < hoehe; y++) {
        for (let x = 0; x < breite; x++) {
            const [r, g, b, a] = fn(x, y);
            const i = (y * breite + x) * 4;
            data[i] = r;
            data[i + 1] = g;
            data[i + 2] = b;
            data[i + 3] = a;
        }
    }

    return { width: breite, height: hoehe, data: data };
}

test('graustufen uses the Rec. 601 luma weights', () => {
    const bild = bildErzeugen(1, 1, () => [100, 150, 200, 255]);
    const grau = graustufen(bild);

    assert.equal(grau[0], Math.round(0.299 * 100 + 0.587 * 150 + 0.114 * 200));
});

test('graustufenBild keeps alpha and equalizes the 3 colour channels', () => {
    const bild = bildErzeugen(1, 1, () => [10, 250, 60, 128]);
    const out = graustufenBild(bild);

    assert.equal(out.data[0], out.data[1]);
    assert.equal(out.data[1], out.data[2]);
    assert.equal(out.data[3], 128);
});

test('integralbild matches a brute-force rectangle sum', () => {
    let saat = 7;
    const zufall = () => {
        saat = (saat * 1103515245 + 12345) & 0x7fffffff;
        return saat / 0x7fffffff;
    };

    const w = 13;
    const h = 9;
    const grau = new Uint8ClampedArray(w * h);
    for (let i = 0; i < grau.length; i++) {
        grau[i] = Math.floor(zufall() * 256);
    }

    const integral = integralbild(grau, w, h);
    const breite = w + 1;

    const rechtecksumme = (x0, y0, x1, y1) => integral[(y1 + 1) * breite + (x1 + 1)]
        - integral[y0 * breite + (x1 + 1)]
        - integral[(y1 + 1) * breite + x0]
        + integral[y0 * breite + x0];

    const bruteForce = (x0, y0, x1, y1) => {
        let summe = 0;
        for (let y = y0; y <= y1; y++) {
            for (let x = x0; x <= x1; x++) {
                summe += grau[y * w + x];
            }
        }

        return summe;
    };

    for (const [x0, y0, x1, y1] of [[0, 0, 0, 0], [0, 0, w - 1, h - 1], [3, 2, 8, 6], [12, 8, 12, 8]]) {
        assert.equal(rechtecksumme(x0, y0, x1, y1), bruteForce(x0, y0, x1, y1), `Rechteck ${x0},${y0},${x1},${y1}`);
    }
});

test('a flat area is entirely paper regardless of its grey level', () => {
    const grau = new Uint8ClampedArray(20 * 20).fill(60);
    const schwelle = bradleySchwelle(grau, 20, 20);

    assert.ok(schwelle.every((wert) => wert === 255));
});

test('bradleySchwelle on the shadow-gradient reference matches the expected mask', () => {
    const bild = lesePgm(path.join(__dirname, '..', 'fixtures', 'scanner', 'beleg-schatten.pgm'));
    const soll = lesePgm(path.join(__dirname, '..', 'fixtures', 'scanner', 'beleg-schatten-soll.pgm'));

    const ergebnis = bradleySchwelle(bild.daten, bild.breite, bild.hoehe);

    let treffer = 0;
    for (let i = 0; i < ergebnis.length; i++) {
        if (ergebnis[i] === soll.daten[i]) {
            treffer++;
        }
    }
    const adaptivQuote = treffer / ergebnis.length;
    assert.ok(adaptivQuote >= 0.99, `adaptive Schwelle nur ${(adaptivQuote * 100).toFixed(1)}%`);

    // Eine globale Schwelle scheitert am Schattenverlauf deutlich - belegt,
    // dass die Anpassung tatsächlich etwas bewirkt und der Test nicht durch
    // ein triviales Referenzbild besteht.
    let globalTreffer = 0;
    for (let i = 0; i < bild.daten.length; i++) {
        const globalWert = bild.daten[i] > 128 ? 255 : 0;
        if (globalWert === soll.daten[i]) {
            globalTreffer++;
        }
    }
    const globalQuote = globalTreffer / bild.daten.length;
    assert.ok(globalQuote < adaptivQuote - 0.1, 'globale Schwelle sollte deutlich schlechter sein');
});

test('schwarzweiss returns an RGBA image with 0/255 grey channels and kept alpha', () => {
    const bild = bildErzeugen(3, 3, (x, y) => [x * 40, x * 40, x * 40, 200]);
    const out = schwarzweiss(bild);

    assert.equal(out.width, 3);
    assert.equal(out.height, 3);
    for (let i = 0; i < 9; i++) {
        const wert = out.data[i * 4];
        assert.ok(wert === 0 || wert === 255);
        assert.equal(out.data[i * 4 + 1], wert);
        assert.equal(out.data[i * 4 + 2], wert);
        assert.equal(out.data[i * 4 + 3], 200);
    }
});
