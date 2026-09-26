// docs/spec/03-erfassung-und-ki.md section 2, "Pflicht-Tests": Eck-Editor
// (Standardrahmen, Anzeige-/Bildkoordinaten-Umrechnung, Eckpunkt-Verschieben
// mit Ablehnung entarteter Vierecke, Farbmodus-Anwendung), issue #33/M5-3.

const test = require('node:test');
const assert = require('node:assert/strict');
const {
    standardRahmen,
    ganzesBild,
    anzeigeSkala,
    zuAnzeige,
    zuBild,
    eckeVerschieben,
    tastaturSchritt,
    lupenPosition,
    lupenAusschnitt,
    FARBMODI,
    farbmodusAnwenden,
    ergebnisErzeugen,
} = require('../../public/js/scanner/eckeditor.js');
const { istKonvex } = require('../../public/js/scanner/kanten.js');
const { A4_VERHAELTNIS } = require('../../public/js/scanner/entzerrung.js');

/** A flat RGBA image filled by `fn(x, y)` -> [r, g, b] (alpha always 255). */
function bildErzeugen(breite, hoehe, fn) {
    const data = new Uint8ClampedArray(breite * hoehe * 4);
    for (let y = 0; y < hoehe; y++) {
        for (let x = 0; x < breite; x++) {
            const [r, g, b] = fn(x, y);
            const i = (y * breite + x) * 4;
            data[i] = r;
            data[i + 1] = g;
            data[i + 2] = b;
            data[i + 3] = 255;
        }
    }

    return { width: breite, height: hoehe, data: data };
}

test('standardRahmen is inset, inside the image, in clockwise order', () => {
    const ecken = standardRahmen(1000, 500, 0.1);

    assert.deepEqual(ecken, [
        { x: 100, y: 50 },
        { x: 900, y: 50 },
        { x: 900, y: 450 },
        { x: 100, y: 450 },
    ]);
    assert.ok(istKonvex(ecken));
});

test('standardRahmen defaults to a 10 % inset', () => {
    assert.deepEqual(standardRahmen(1000, 1000), standardRahmen(1000, 1000, 0.1));
});

test('ganzesBild is the image\'s own 4 corners', () => {
    assert.deepEqual(ganzesBild(800, 600), [
        { x: 0, y: 0 },
        { x: 800, y: 0 },
        { x: 800, y: 600 },
        { x: 0, y: 600 },
    ]);
});

test('anzeigeSkala shrinks to fit but never upscales', () => {
    assert.equal(anzeigeSkala(4000, 3000, 400, 400), 0.1);
    assert.equal(anzeigeSkala(100, 50, 400, 400), 1, 'a smaller-than-the-box image is not blown up');
    assert.equal(anzeigeSkala(0, 100, 400, 400), 1, 'degenerate input falls back to 1 rather than dividing by 0');
});

test('zuAnzeige and zuBild are inverses of each other', () => {
    const bildPunkt = { x: 321, y: 987 };
    const skala = 0.37;

    const zurueck = zuBild(zuAnzeige(bildPunkt, skala), skala);
    assert.ok(Math.abs(zurueck.x - bildPunkt.x) < 1e-9);
    assert.ok(Math.abs(zurueck.y - bildPunkt.y) < 1e-9);
});

test('eckeVerschieben clamps the new position to the image bounds', () => {
    const ecken = standardRahmen(1000, 1000, 0.1);

    const verschoben = eckeVerschieben(ecken, 0, { x: -500, y: -500 }, 1000, 1000);
    assert.deepEqual(verschoben[0], { x: 0, y: 0 });
});

test('eckeVerschieben rejects a move that would make the quad concave or crossed', () => {
    // Dragging the top-left corner (index 0) far past its diagonal neighbour
    // (bottom-right, index 2) would cross the quad's sides.
    const ecken = standardRahmen(1000, 1000, 0.1);

    const verschoben = eckeVerschieben(ecken, 0, { x: 950, y: 950 }, 1000, 1000);
    assert.deepEqual(verschoben, ecken, 'the quad is left unchanged rather than becoming self-intersecting');
});

test('eckeVerschieben rejects a move that collapses the quad below the minimum area', () => {
    // A thin strip on a 1000x1000 image: area 11 500, above the 1 % (10 000)
    // minimum - a valid starting quad. Dragging its bottom-right corner up
    // to y=1 (right next to the already-low bottom-left corner) would
    // shrink it to 1 500 - below the minimum, so the move must be rejected.
    const ecken = [
        { x: 0, y: 0 },
        { x: 1000, y: 0 },
        { x: 1000, y: 21 },
        { x: 0, y: 2 },
    ];

    const verschoben = eckeVerschieben(ecken, 2, { x: 1000, y: 1 }, 1000, 1000);
    assert.deepEqual(verschoben, ecken, 'a too-small quad is rejected, not accepted');
});

test('eckeVerschieben accepts a valid move', () => {
    const ecken = standardRahmen(1000, 1000, 0.1);

    const verschoben = eckeVerschieben(ecken, 0, { x: 50, y: 60 }, 1000, 1000);
    assert.deepEqual(verschoben[0], { x: 50, y: 60 });
    assert.deepEqual(verschoben.slice(1), ecken.slice(1), 'the other 3 corners are untouched');
});

test('tastaturSchritt maps arrow keys to a 1px/10px step', () => {
    assert.deepEqual(tastaturSchritt('ArrowUp', false), { dx: 0, dy: -1 });
    assert.deepEqual(tastaturSchritt('ArrowDown', false), { dx: 0, dy: 1 });
    assert.deepEqual(tastaturSchritt('ArrowLeft', true), { dx: -10, dy: 0 });
    assert.deepEqual(tastaturSchritt('ArrowRight', true), { dx: 10, dy: 0 });
    assert.equal(tastaturSchritt('Enter', false), null);
});

test('lupenPosition sits top-left, but flips to top-right under the dragged point', () => {
    const normal = lupenPosition({ x: 300, y: 300 }, 400, 600, 120, 8);
    assert.deepEqual(normal, { x: 8, y: 8 });

    const verdeckt = lupenPosition({ x: 20, y: 20 }, 400, 600, 120, 8);
    assert.equal(verdeckt.y, 8);
    assert.ok(verdeckt.x > 8, 'flips away from the top-left corner');
});

test('lupenAusschnitt centres a zoom/skala-sized source square on the point', () => {
    const ausschnitt = lupenAusschnitt({ x: 500, y: 400 }, 2.5, 120, 0.5);

    // seite = 120 / (2.5 * 0.5) = 96
    assert.equal(ausschnitt.breite, 96);
    assert.equal(ausschnitt.hoehe, 96);
    assert.equal(ausschnitt.x, 500 - 48);
    assert.equal(ausschnitt.y, 400 - 48);
});

test('FARBMODI lists exactly the 3 modes the spec names', () => {
    assert.deepEqual(FARBMODI, ['sw', 'grau', 'farbe']);
});

test('farbmodusAnwenden: sw is pure black/white', () => {
    const bild = bildErzeugen(4, 4, () => [180, 90, 45]);
    const ergebnis = farbmodusAnwenden(bild, 'sw');

    for (let i = 0; i < ergebnis.data.length; i += 4) {
        assert.ok(ergebnis.data[i] === 0 || ergebnis.data[i] === 255);
        assert.equal(ergebnis.data[i], ergebnis.data[i + 1]);
        assert.equal(ergebnis.data[i], ergebnis.data[i + 2]);
    }
});

test('farbmodusAnwenden: grau sets r = g = b', () => {
    const bild = bildErzeugen(4, 4, (x, y) => [x * 10, y * 20, 30]);
    const ergebnis = farbmodusAnwenden(bild, 'grau');

    for (let i = 0; i < ergebnis.data.length; i += 4) {
        assert.equal(ergebnis.data[i], ergebnis.data[i + 1]);
        assert.equal(ergebnis.data[i], ergebnis.data[i + 2]);
    }
});

test('farbmodusAnwenden: farbe is an unchanged copy', () => {
    const bild = bildErzeugen(3, 3, (x, y) => [x * 10, y * 10, 5]);
    const ergebnis = farbmodusAnwenden(bild, 'farbe');

    assert.deepEqual(Array.from(ergebnis.data), Array.from(bild.data));
    assert.notEqual(ergebnis.data, bild.data, 'a copy, not the same buffer');
});

test('farbmodusAnwenden throws on an unknown mode', () => {
    const bild = bildErzeugen(2, 2, () => [0, 0, 0]);
    assert.throws(() => farbmodusAnwenden(bild, 'sepia'));
});

test('ergebnisErzeugen straightens and applies the colour mode in one call', () => {
    const bild = bildErzeugen(400, 300, () => [200, 150, 100]);
    const ecken = [
        { x: 0, y: 0 },
        { x: 210, y: 0 },
        { x: 210, y: 297 },
        { x: 0, y: 297 },
    ];

    const ergebnis = ergebnisErzeugen(bild, ecken, 'grau');

    // 210 x 297 is A4 portrait (docs/spec 03 §2, "Zielformat ... A4-Snap").
    assert.ok(Math.abs(ergebnis.height / ergebnis.width - A4_VERHAELTNIS) < 0.01);
    for (let i = 0; i < ergebnis.data.length; i += 4) {
        assert.equal(ergebnis.data[i], ergebnis.data[i + 1]);
        assert.equal(ergebnis.data[i], ergebnis.data[i + 2]);
    }
});
