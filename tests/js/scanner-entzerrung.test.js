// docs/spec/03-erfassung-und-ki.md section 2, "Pflicht-Tests": Entzerrung
// (Zielgröße/A4-Snap, achsparalleler Ausschnitt als Sonderfall, ein
// perspektivisch verzerrtes Muster wird zurückentzerrt).

const test = require('node:test');
const assert = require('node:assert/strict');
const { homographie, abbilden } = require('../../public/js/scanner/homographie.js');
const { zielgroesse, entzerren, A4_VERHAELTNIS } = require('../../public/js/scanner/entzerrung.js');

/** A flat RGBA image filled by `fn(x, y)` -> 0..255 (one grey value per pixel). */
function bildErzeugen(breite, hoehe, fn) {
    const data = new Uint8ClampedArray(breite * hoehe * 4);
    for (let y = 0; y < hoehe; y++) {
        for (let x = 0; x < breite; x++) {
            const wert = fn(x, y);
            const i = (y * breite + x) * 4;
            data[i] = wert;
            data[i + 1] = wert;
            data[i + 2] = wert;
            data[i + 3] = 255;
        }
    }

    return { width: breite, height: hoehe, data: data };
}

test('a near-A4 quad snaps to the exact A4 ratio, portrait and landscape', () => {
    // Toleranz großzügig genug für die Pixel-Rundung von zielgroesse() (bei
    // wenigen hundert Pixel Kantenlänge verschiebt schon 1 gerundetes Pixel
    // das Verhältnis um knapp 0,5 %).
    const hoch = zielgroesse([{ x: 0, y: 0 }, { x: 210, y: 2 }, { x: 208, y: 300 }, { x: 0, y: 297 }]);
    assert.ok(Math.abs(hoch.hoehe / hoch.breite - A4_VERHAELTNIS) < 0.01);

    const quer = zielgroesse([{ x: 0, y: 0 }, { x: 297, y: 0 }, { x: 297, y: 210 }, { x: 0, y: 210 }]);
    assert.ok(Math.abs(quer.breite / quer.hoehe - A4_VERHAELTNIS) < 0.01);
});

test('a ratio far from A4 is left as measured', () => {
    const quadratisch = zielgroesse([{ x: 0, y: 0 }, { x: 100, y: 0 }, { x: 100, y: 100 }, { x: 0, y: 100 }]);
    assert.deepEqual(quadratisch, { breite: 100, hoehe: 100 });
});

test('the long edge is capped at maxKante, proportionally', () => {
    const ziel = zielgroesse(
        [{ x: 0, y: 0 }, { x: 5000, y: 0 }, { x: 5000, y: 5000 * A4_VERHAELTNIS }, { x: 0, y: 5000 * A4_VERHAELTNIS }],
        { maxKante: 2480 },
    );

    assert.equal(ziel.hoehe, 2480);
    assert.equal(ziel.breite, Math.round(2480 / A4_VERHAELTNIS));
});

test('a degenerate quad (zero area) still returns a usable 1x1 fallback', () => {
    const ziel = zielgroesse([{ x: 5, y: 5 }, { x: 5, y: 5 }, { x: 5, y: 5 }, { x: 5, y: 5 }]);
    assert.deepEqual(ziel, { breite: 1, hoehe: 1 });
});

test('an axis-aligned sub-rectangle is an exact crop (no perspective involved)', () => {
    // 4x4-Schachbrett; die Ecken markieren das mittlere 2x2-Quadrat.
    const bild = bildErzeugen(4, 4, (x, y) => ((x + y) % 2 === 0 ? 255 : 0));
    const ecken = [{ x: 1, y: 1 }, { x: 3, y: 1 }, { x: 3, y: 3 }, { x: 1, y: 3 }];

    const out = entzerren(bild, ecken, { breite: 2, hoehe: 2 });

    // Entspricht genau den Originalpixeln (1,1), (2,1), (1,2), (2,2).
    assert.deepEqual(Array.from(out.data), [
        255, 255, 255, 255, 0, 0, 0, 255,
        0, 0, 0, 255, 255, 255, 255, 255,
    ]);
});

test('a perspective-warped checkerboard straightens back correctly away from cell edges', () => {
    const ZELLE = 10;
    const checker = (x, y) => {
        const zx = Math.floor(x / ZELLE);
        const zy = Math.floor(y / ZELLE);
        return (zx + zy) % 2 === 0 ? 255 : 0;
    };

    const ziel = { breite: 80, hoehe: 80 };
    const zielEcken = [
        { x: 0, y: 0 }, { x: ziel.breite, y: 0 },
        { x: ziel.breite, y: ziel.hoehe }, { x: 0, y: ziel.hoehe },
    ];
    // "Fotografierte" Ecken auf einer größeren Leinwand - ein echtes Foto
    // hätte Rand um das Blatt.
    const ecken = [{ x: 15, y: 8 }, { x: 92, y: 15 }, { x: 85, y: 95 }, { x: 5, y: 80 }];
    // Quelle direkt aus H^-1 aufbauen (nicht über entzerren()!), damit der
    // Test nicht zirkulär ist: jedes Quellpixel bekommt den Schachbrettwert
    // an der Stelle, auf die es im flachen Zielrechteck abbildet.
    const hZielNachQuelle = homographie(ecken, zielEcken);

    const quelle = bildErzeugen(110, 110, (sx, sy) => {
        const zp = abbilden(hZielNachQuelle, { x: sx + 0.5, y: sy + 0.5 });
        if (zp === null || zp.x < 0 || zp.x > ziel.breite || zp.y < 0 || zp.y > ziel.hoehe) {
            return 255;
        }

        return checker(zp.x, zp.y);
    });

    const out = entzerren(quelle, ecken, ziel);

    let treffer = 0;
    let gesamt = 0;
    for (let y = 0; y < ziel.hoehe; y++) {
        for (let x = 0; x < ziel.breite; x++) {
            // Pixel direkt an einer Zellgrenze ausklammern (beide Seiten) -
            // dort ist auch eine korrekte bilineare Interpolation kein
            // reiner Schwarz- oder Weißwert.
            const naheGrenze = x % ZELLE < 1 || x % ZELLE >= ZELLE - 1
                || y % ZELLE < 1 || y % ZELLE >= ZELLE - 1;
            if (naheGrenze) {
                continue;
            }

            gesamt++;
            const soll = checker(x + 0.5, y + 0.5);
            const ist = out.data[(y * ziel.breite + x) * 4];
            if (Math.abs(ist - soll) < 40) {
                treffer++;
            }
        }
    }

    assert.ok(treffer / gesamt >= 0.97, `nur ${treffer}/${gesamt} korrekt`);
});

test('a degenerate quad throws instead of dividing by zero', () => {
    const bild = bildErzeugen(4, 4, () => 128);
    const entartet = [{ x: 0, y: 0 }, { x: 1, y: 0 }, { x: 2, y: 0 }, { x: 0, y: 1 }];

    assert.throws(() => entzerren(bild, entartet, { breite: 2, hoehe: 2 }));
});

test('entzerren() does not modify the input image', () => {
    const bild = bildErzeugen(4, 4, (x, y) => x * 10 + y);
    const kopie = Uint8ClampedArray.from(bild.data);
    const ecken = [{ x: 0, y: 0 }, { x: 4, y: 0 }, { x: 4, y: 4 }, { x: 0, y: 4 }];

    entzerren(bild, ecken, { breite: 4, hoehe: 4 });

    assert.deepEqual(Array.from(bild.data), Array.from(kopie));
});
