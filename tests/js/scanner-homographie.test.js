// docs/spec/03-erfassung-und-ki.md section 2, "Pflicht-Tests":
// Homographie-Roundtrip.

const test = require('node:test');
const assert = require('node:assert/strict');
const { homographie, abbilden, invertieren } = require('../../public/js/scanner/homographie.js');

const EINHEITSQUADRAT = [{ x: 0, y: 0 }, { x: 1, y: 0 }, { x: 1, y: 1 }, { x: 0, y: 1 }];

function nah(a, b, epsilon) {
    return Math.abs(a - b) < (epsilon || 1e-9);
}

test('mapping the unit square onto itself is the identity', () => {
    const h = homographie(EINHEITSQUADRAT, EINHEITSQUADRAT);

    assert.deepEqual(h, [1, 0, 0, 0, 1, 0, 0, 0, 1]);
});

test('a pure scale maps every point by the same factor', () => {
    const nach = EINHEITSQUADRAT.map((p) => ({ x: p.x * 2, y: p.y * 2 }));
    const h = homographie(EINHEITSQUADRAT, nach);

    const abgebildet = abbilden(h, { x: 0.3, y: 0.7 });
    assert.ok(nah(abgebildet.x, 0.6));
    assert.ok(nah(abgebildet.y, 1.4));
});

test('a pure translation maps every point by the same offset', () => {
    const nach = EINHEITSQUADRAT.map((p) => ({ x: p.x + 5, y: p.y - 2 }));
    const h = homographie(EINHEITSQUADRAT, nach);

    const abgebildet = abbilden(h, { x: 0.5, y: 0.5 });
    assert.ok(nah(abgebildet.x, 5.5));
    assert.ok(nah(abgebildet.y, -1.5));
});

test('the 4 source corners map exactly onto the 4 destination corners', () => {
    const von = [{ x: 0, y: 0 }, { x: 10, y: 0 }, { x: 10, y: 10 }, { x: 0, y: 10 }];
    const nach = [{ x: 2, y: 1 }, { x: 9, y: 0 }, { x: 11, y: 12 }, { x: 0, y: 11 }];
    const h = homographie(von, nach);

    for (let i = 0; i < 4; i++) {
        const abgebildet = abbilden(h, von[i]);
        assert.ok(nah(abgebildet.x, nach[i].x, 1e-6), `Ecke ${i} x`);
        assert.ok(nah(abgebildet.y, nach[i].y, 1e-6), `Ecke ${i} y`);
    }
});

test('roundtrip through H and its inverse recovers random points', () => {
    const von = [{ x: 0, y: 0 }, { x: 10, y: 0 }, { x: 10, y: 10 }, { x: 0, y: 10 }];
    const nach = [{ x: 2, y: 1 }, { x: 9, y: 0 }, { x: 11, y: 12 }, { x: 0, y: 11 }];
    const h = homographie(von, nach);
    const hInvertiert = invertieren(h);

    // invertieren() muss dieselbe Abbildung liefern wie homographie() in
    // umgekehrter Richtung berechnet - beide Wege müssen übereinstimmen.
    const hDirekt = homographie(nach, von);
    for (let i = 0; i < 9; i++) {
        assert.ok(nah(hInvertiert[i], hDirekt[i], 1e-9), `Element ${i}`);
    }

    let saat = 42;
    const zufall = () => {
        saat = (saat * 1103515245 + 12345) & 0x7fffffff;
        return saat / 0x7fffffff;
    };

    for (let i = 0; i < 50; i++) {
        const punkt = { x: zufall() * 10, y: zufall() * 10 };
        const abgebildet = abbilden(h, punkt);
        const zurueck = abbilden(hInvertiert, abgebildet);

        assert.ok(nah(punkt.x, zurueck.x, 1e-6), `roundtrip x bei ${i}`);
        assert.ok(nah(punkt.y, zurueck.y, 1e-6), `roundtrip y bei ${i}`);
    }
});

test('3 collinear source points make the system unsolvable', () => {
    const von = [{ x: 0, y: 0 }, { x: 1, y: 0 }, { x: 2, y: 0 }, { x: 0, y: 1 }];
    const nach = [{ x: 2, y: 1 }, { x: 9, y: 0 }, { x: 11, y: 12 }, { x: 0, y: 11 }];

    assert.equal(homographie(von, nach), null);
});

test('fewer or more than 4 point pairs is rejected', () => {
    assert.equal(homographie(EINHEITSQUADRAT.slice(0, 3), EINHEITSQUADRAT.slice(0, 3)), null);
    assert.equal(homographie(null, EINHEITSQUADRAT), null);
});

test('invertieren() rejects a singular matrix', () => {
    // h[8] = 0 macht die Matrix bei dieser Normierung singulär.
    assert.equal(invertieren([1, 0, 0, 0, 1, 0, 0, 0, 0]), null);
});

test('abbilden() rejects a point that maps to the line at infinity', () => {
    // h so gewählt, dass w = 0 für (1,0): h6*x + h7*y + h8 = -1*1 + 0 + 1 = 0.
    const h = [1, 0, 0, 0, 1, 0, -1, 0, 1];
    assert.equal(abbilden(h, { x: 1, y: 0 }), null);
});
