// docs/spec/03-erfassung-und-ki.md section 2, "Pflicht-Tests": Viereck-
// Auswahl (issue #32/M5-2). The real-photo hit rate lives in
// tests/fixtures/scanner/trefferquote.js and docs/ENTSCHEIDUNGEN.md (E-03) -
// this file covers the pure-function building blocks with synthetic data,
// so it needs no fixtures beyond what it generates itself.

const test = require('node:test');
const assert = require('node:assert/strict');
const {
    eckenOrdnen,
    schnittpunkt,
    viereckAuswaehlen,
    kantenErkennen,
} = require('../../public/js/scanner/kanten.js');

/** mulberry32, same tiny deterministic PRNG as erzeuge-referenz.js. */
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

function abstand(a, b) {
    return Math.hypot(b.x - a.x, b.y - a.y);
}

/** Größte Abweichung der 4 gefundenen Ecken von den Soll-Ecken, in % der Bilddiagonale. */
function groessteAbweichungAnteil(gefunden, soll, breite, hoehe) {
    const diagonale = Math.hypot(breite, hoehe);
    const sollGeordnet = eckenOrdnen(soll);
    const gefundenGeordnet = eckenOrdnen(gefunden);

    let groesste = 0;
    for (let i = 0; i < 4; i++) {
        groesste = Math.max(groesste, abstand(gefundenGeordnet[i], sollGeordnet[i]) / diagonale);
    }

    return groesste;
}

test('eckenOrdnen orders 4 points as [oben-links, oben-rechts, unten-rechts, unten-links] regardless of input order', () => {
    const obenLinks = { x: 10, y: 12 };
    const obenRechts = { x: 190, y: 8 };
    const untenRechts = { x: 195, y: 290 };
    const untenLinks = { x: 5, y: 285 };

    for (const permutation of [
        [obenLinks, obenRechts, untenRechts, untenLinks],
        [untenLinks, untenRechts, obenRechts, obenLinks],
        [untenRechts, obenLinks, untenLinks, obenRechts],
    ]) {
        assert.deepEqual(eckenOrdnen(permutation), [obenLinks, obenRechts, untenRechts, untenLinks]);
    }
});

test('schnittpunkt returns null for (near-)parallel lines', () => {
    const a = { rho: 10, theta: 0 };
    const b = { rho: 50, theta: 0.0000001 };
    assert.equal(schnittpunkt(a, b), null);
});

test('schnittpunkt finds the intersection of a horizontal and a vertical line', () => {
    const waagerecht = { rho: 30, theta: Math.PI / 2 }; // y = 30
    const senkrecht = { rho: 40, theta: 0 }; // x = 40
    const punkt = schnittpunkt(waagerecht, senkrecht);

    assert.ok(Math.abs(punkt.x - 40) < 1e-9);
    assert.ok(Math.abs(punkt.y - 30) < 1e-9);
});

/** A blank RGBA canvas, `zeichnen(x, y, wert)` sets one pixel's grayscale value (also fills alpha). */
function leinwand(breite, hoehe, hintergrund) {
    const data = new Uint8ClampedArray(breite * hoehe * 4).fill(255);
    for (let i = 0; i < breite * hoehe; i++) {
        data[i * 4] = hintergrund;
        data[i * 4 + 1] = hintergrund;
        data[i * 4 + 2] = hintergrund;
    }

    return {
        width: breite,
        height: hoehe,
        data,
        setzen(x, y, wert) {
            if (x < 0 || x >= breite || y < 0 || y >= hoehe) {
                return;
            }
            const i = (Math.round(y) * breite + Math.round(x)) * 4;
            data[i] = wert;
            data[i + 1] = wert;
            data[i + 2] = wert;
        },
    };
}

/** Zeichnet eine gefüllte konvexe Vierecksfläche (Scanline-Füllung) mit `wert` plus etwas Rauschen. */
function viereckFuellen(bild, ecken, wert, zufall, rauschen) {
    const minY = Math.max(0, Math.floor(Math.min(...ecken.map((p) => p.y))));
    const maxY = Math.min(bild.height - 1, Math.ceil(Math.max(...ecken.map((p) => p.y))));

    for (let y = minY; y <= maxY; y++) {
        const schnittpunkte = [];
        for (let i = 0; i < ecken.length; i++) {
            const a = ecken[i];
            const b = ecken[(i + 1) % ecken.length];
            if ((a.y <= y && b.y > y) || (b.y <= y && a.y > y)) {
                const t = (y - a.y) / (b.y - a.y);
                schnittpunkte.push(a.x + t * (b.x - a.x));
            }
        }
        schnittpunkte.sort((a, b) => a - b);
        for (let i = 0; i + 1 < schnittpunkte.length; i += 2) {
            const xStart = Math.round(schnittpunkte[i]);
            const xEnde = Math.round(schnittpunkte[i + 1]);
            for (let x = xStart; x <= xEnde; x++) {
                bild.setzen(x, y, wert + Math.round((zufall() - 0.5) * rauschen));
            }
        }
    }
}

/** Ein leicht schräg fotografiertes, helles Blatt Papier auf dunklerem, verrauschtem Untergrund. */
function papierFoto(zufall) {
    const breite = 320;
    const hoehe = 420;
    const bild = leinwand(breite, hoehe, 0);

    for (let i = 0; i < breite * hoehe; i++) {
        const rauschen = 60 + Math.round((zufall() - 0.5) * 18);
        bild.data[i * 4] = rauschen;
        bild.data[i * 4 + 1] = rauschen;
        bild.data[i * 4 + 2] = rauschen;
    }

    const ecken = [
        { x: 46, y: 34 }, // oben-links
        { x: 268, y: 48 }, // oben-rechts
        { x: 256, y: 388 }, // unten-rechts
        { x: 34, y: 372 }, // unten-links
    ];
    viereckFuellen(bild, ecken, 232, zufall, 10);

    // Ein paar "Textzeilen" innerhalb des Papiers - dürfen die Papierkante
    // nicht als stärkere/größere Kontur überstimmen.
    for (let zeile = 0; zeile < 6; zeile++) {
        const y = 90 + zeile * 40;
        const textEcken = [
            { x: 70, y: y }, { x: 220, y: y + 4 }, { x: 218, y: y + 12 }, { x: 68, y: y + 8 },
        ];
        viereckFuellen(bild, textEcken, 70, zufall, 8);
    }

    return { bild, ecken, breite, hoehe };
}

test('kantenErkennen finds the corners of a perspective-skewed bright sheet on a noisy dark background', () => {
    const zufall = pseudorng(20260925);
    const { bild, ecken, breite, hoehe } = papierFoto(zufall);

    const gefunden = kantenErkennen(bild);

    assert.notEqual(gefunden, null);
    const abweichung = groessteAbweichungAnteil(gefunden, ecken, breite, hoehe);
    assert.ok(abweichung <= 0.03, `Abweichung ${(abweichung * 100).toFixed(1)}% > 3% der Diagonale`);
});

test('kantenErkennen returns null for a blank, featureless image', () => {
    const bild = leinwand(200, 260, 128);
    assert.equal(kantenErkennen(bild), null);
});

test('viereckAuswaehlen prefers the larger, better-supported outer quad over a smaller inner one', () => {
    const breite = 200;
    const hoehe = 280;

    // Äußeres Viereck: fast achsparallel, an allen 4 Seiten von der
    // Kantenkarte unterstützt. Inneres Viereck (z. B. eine Tabelle auf dem
    // Beleg): kleiner, ohne jede Kantenunterstützung.
    const aussen = {
        obenLinks: { theta: 0, rho: 20 }, // x = 20 (senkrecht)
        obenRechts: { theta: 0, rho: 180 }, // x = 180 (senkrecht)
        oben: { theta: Math.PI / 2, rho: 15 }, // y = 15 (waagerecht)
        unten: { theta: Math.PI / 2, rho: 265 }, // y = 265 (waagerecht)
    };

    const linien = {
        senkrecht: [
            { ...aussen.obenLinks, stimmen: 200 },
            { ...aussen.obenRechts, stimmen: 190 },
            { theta: 0, rho: 80, stimmen: 20 }, // inneres Viereck, schwach
            { theta: 0, rho: 120, stimmen: 18 },
        ],
        waagerecht: [
            { ...aussen.oben, stimmen: 195 },
            { ...aussen.unten, stimmen: 185 },
            { theta: Math.PI / 2, rho: 100, stimmen: 15 },
            { theta: Math.PI / 2, rho: 160, stimmen: 14 },
        ],
    };

    // Kantenkarte: nur entlang des äußeren Rechtecks Kanten gesetzt.
    const kanten = new Uint8ClampedArray(breite * hoehe);
    for (let x = 20; x <= 180; x++) {
        kanten[15 * breite + x] = 255;
        kanten[265 * breite + x] = 255;
    }
    for (let y = 15; y <= 265; y++) {
        kanten[y * breite + 20] = 255;
        kanten[y * breite + 180] = 255;
    }

    const ergebnis = viereckAuswaehlen(linien, breite, hoehe, kanten);
    assert.notEqual(ergebnis, null);

    const flaeche = (ecken) => {
        let summe = 0;
        for (let i = 0; i < ecken.length; i++) {
            const a = ecken[i];
            const b = ecken[(i + 1) % ecken.length];
            summe += a.x * b.y - b.x * a.y;
        }
        return Math.abs(summe) / 2;
    };

    // Das äußere Rechteck hat Fläche 160*250 = 40000, das innere nur 40*60 = 2400.
    assert.ok(flaeche(ergebnis) > 30000, `Fläche ${flaeche(ergebnis)} - erwartet das äußere, nicht das innere Viereck`);
});

test('viereckAuswaehlen rejects a non-convex (self-intersecting) candidate', () => {
    // Zwei "senkrechte" und zwei "waagerechte" Linien, deren Schnittpunkte
    // ein überkreuztes (nicht konvexes) Viereck ergeben, plus ohne jede
    // Kantenunterstützung - es darf kein Ergebnis geliefert werden.
    const linien = {
        senkrecht: [
            { theta: 0.3, rho: 20, stimmen: 50 },
            { theta: -0.3, rho: 150, stimmen: 45 },
        ],
        waagerecht: [
            { theta: Math.PI / 2 + 0.3, rho: 20, stimmen: 48 },
            { theta: Math.PI / 2 - 0.3, rho: 200, stimmen: 44 },
        ],
    };
    const kanten = new Uint8ClampedArray(220 * 220);

    assert.equal(viereckAuswaehlen(linien, 220, 220, kanten), null);
});

test('viereckAuswaehlen returns null when fewer than 2 lines exist in a group', () => {
    const linien = { senkrecht: [{ theta: 0, rho: 20, stimmen: 50 }], waagerecht: [] };
    const kanten = new Uint8ClampedArray(100 * 100);

    assert.equal(viereckAuswaehlen(linien, 100, 100, kanten), null);
});

// Die folgenden drei Tests reproduzieren Fehlerbilder, die beim Messen der
// Trefferquote an echten Belegfotos auffielen (issue #32/M5-2): eine starke
// fremde Linie neben dem Beleg (Tischkante), eine gedruckte Linie innerhalb
// des Belegs (Tabellenraster) und eine deutliche Drehung. Die Fotos selbst
// sind nicht Teil des Repos (CLAUDE.md §1, E-13) - diese synthetischen
// Bilder halten die daraus gelernten Fixes als Regression fest.

test("kantenErkennen finds the receipt's own bottom edge, not a brighter table edge just below it", () => {
    const zufall = pseudorng(20260926);
    const breite = 300;
    const hoehe = 420;
    const bild = leinwand(breite, hoehe, 0);

    for (let i = 0; i < breite * hoehe; i++) {
        const rauschen = 55 + Math.round((zufall() - 0.5) * 16);
        bild.data[i * 4] = rauschen;
        bild.data[i * 4 + 1] = rauschen;
        bild.data[i * 4 + 2] = rauschen;
    }

    const ecken = [
        { x: 60, y: 30 }, { x: 240, y: 34 }, { x: 236, y: 300 }, { x: 56, y: 296 },
    ];
    viereckFuellen(bild, ecken, 225, zufall, 10);

    // Eine helle Tischkante quer über das ganze Bild, ein Stück unterhalb
    // des Belegs - länger und mindestens so stark wie die eigentliche
    // Beleg-Unterkante, darf aber nicht gewinnen: auf beiden Seiten dieser
    // Linie ist es dunkel (Tischplatte), nirgends papierhell.
    const tischkante = [
        { x: 0, y: 345 }, { x: breite, y: 347 }, { x: breite, y: 360 }, { x: 0, y: 358 },
    ];
    viereckFuellen(bild, tischkante, 200, zufall, 10);

    const gefunden = kantenErkennen(bild);

    assert.notEqual(gefunden, null);
    const abweichung = groessteAbweichungAnteil(gefunden, ecken, breite, hoehe);
    assert.ok(abweichung <= 0.03, `Abweichung ${(abweichung * 100).toFixed(1)}% > 3% der Diagonale`);
});

test("kantenErkennen finds a sheet's outer edge, not a printed table line close to it", () => {
    const zufall = pseudorng(20260927);
    const breite = 300;
    const hoehe = 400;
    const bild = leinwand(breite, hoehe, 0);

    for (let i = 0; i < breite * hoehe; i++) {
        const rauschen = 50 + Math.round((zufall() - 0.5) * 14);
        bild.data[i * 4] = rauschen;
        bild.data[i * 4 + 1] = rauschen;
        bild.data[i * 4 + 2] = rauschen;
    }

    const ecken = [
        { x: 20, y: 15 }, { x: 283, y: 18 }, { x: 280, y: 388 }, { x: 16, y: 384 },
    ];
    viereckFuellen(bild, ecken, 230, zufall, 8);

    // Gedrucktes Tabellenraster nahe am Rand - dunkler als das Papier, aber
    // auf beiden Seiten Papier (kein Hell/Dunkel-Sprung von innen nach
    // außen): darf die echte Außenkante nicht schlagen, egal wie kräftig.
    const randAbstand = 28;
    viereckFuellen(bild, [
        { x: 20 + randAbstand, y: 15 + randAbstand },
        { x: 283 - randAbstand, y: 18 + randAbstand },
        { x: 283 - randAbstand, y: 18 + randAbstand + 5 },
        { x: 20 + randAbstand, y: 15 + randAbstand + 5 },
    ], 55, zufall, 6);
    viereckFuellen(bild, [
        { x: 20 + randAbstand, y: 15 + randAbstand },
        { x: 20 + randAbstand + 5, y: 15 + randAbstand },
        { x: 16 + randAbstand + 5, y: 384 - randAbstand },
        { x: 16 + randAbstand, y: 384 - randAbstand },
    ], 55, zufall, 6);

    const gefunden = kantenErkennen(bild);

    assert.notEqual(gefunden, null);
    const abweichung = groessteAbweichungAnteil(gefunden, ecken, breite, hoehe);
    assert.ok(abweichung <= 0.03, `Abweichung ${(abweichung * 100).toFixed(1)}% > 3% der Diagonale`);
});

test('kantenErkennen finds the corners of a sheet rotated about 30°', () => {
    const zufall = pseudorng(20260928);
    const breite = 360;
    const hoehe = 360;
    const bild = leinwand(breite, hoehe, 0);

    for (let i = 0; i < breite * hoehe; i++) {
        const rauschen = 55 + Math.round((zufall() - 0.5) * 16);
        bild.data[i * 4] = rauschen;
        bild.data[i * 4 + 1] = rauschen;
        bild.data[i * 4 + 2] = rauschen;
    }

    const mitte = { x: 180, y: 180 };
    const halbBreite = 80;
    const halbHoehe = 110;
    const winkel = (30 * Math.PI) / 180;
    const drehen = (dx, dy) => ({
        x: mitte.x + dx * Math.cos(winkel) - dy * Math.sin(winkel),
        y: mitte.y + dx * Math.sin(winkel) + dy * Math.cos(winkel),
    });
    const ecken = [
        drehen(-halbBreite, -halbHoehe),
        drehen(halbBreite, -halbHoehe),
        drehen(halbBreite, halbHoehe),
        drehen(-halbBreite, halbHoehe),
    ];
    viereckFuellen(bild, ecken, 228, zufall, 10);

    const gefunden = kantenErkennen(bild);

    assert.notEqual(gefunden, null);
    const abweichung = groessteAbweichungAnteil(gefunden, ecken, breite, hoehe);
    assert.ok(abweichung <= 0.03, `Abweichung ${(abweichung * 100).toFixed(1)}% > 3% der Diagonale`);
});

test('eckenOrdnen returns a stable, non-crossed cyclic order at a rotation where picking the top-left corner is inherently ambiguous', () => {
    // Bei ~55° gibt selbst das "richtige" Rechteck kein eindeutiges "oben
    // links" mehr her (Summe x+y ist für zwei gegenüberliegende Ecken fast
    // gleich) - das darf niemanden crashen oder eine übersprungene/
    // überkreuzte Reihenfolge liefern, nur *welche* der 4 Ecken als Start
    // gilt, ist an dieser Drehung nicht mehr sinnvoll vorschreibbar.
    const mitte = { x: 150, y: 150 };
    const halbBreite = 65;
    const halbHoehe = 80;
    const winkel = (55 * Math.PI) / 180;
    const drehen = (dx, dy) => ({
        x: mitte.x + dx * Math.cos(winkel) - dy * Math.sin(winkel),
        y: mitte.y + dx * Math.sin(winkel) + dy * Math.cos(winkel),
    });
    const wahrerZyklus = [
        drehen(-halbBreite, -halbHoehe),
        drehen(halbBreite, -halbHoehe),
        drehen(halbBreite, halbHoehe),
        drehen(-halbBreite, halbHoehe),
    ];

    const ergebnisse = [
        eckenOrdnen([wahrerZyklus[0], wahrerZyklus[1], wahrerZyklus[2], wahrerZyklus[3]]),
        eckenOrdnen([wahrerZyklus[3], wahrerZyklus[0], wahrerZyklus[1], wahrerZyklus[2]]),
        eckenOrdnen([wahrerZyklus[2], wahrerZyklus[3], wahrerZyklus[0], wahrerZyklus[1]]),
        eckenOrdnen([wahrerZyklus[1], wahrerZyklus[2], wahrerZyklus[3], wahrerZyklus[0]]),
    ];

    // Jede Eingabereihenfolge (= derselbe Zyklus, nur anders "aufgeschnitten")
    // muss dasselbe Ergebnis liefern - der Startpunkt hängt nicht vom Zufall
    // der Eingabereihenfolge ab.
    for (const ergebnis of ergebnisse) {
        assert.deepEqual(ergebnis, ergebnisse[0]);
    }

    // Das Ergebnis muss der wahre Zyklus sein, ggf. an einem anderen Punkt
    // begonnen - niemals eine vertauschte oder übersprungene Ecke.
    const startIndex = wahrerZyklus.indexOf(ergebnisse[0][0]);
    assert.notEqual(startIndex, -1, 'Startpunkt ist keine der 4 wahren Ecken');
    const erwartet = [0, 1, 2, 3].map((i) => wahrerZyklus[(startIndex + i) % 4]);
    assert.deepEqual(ergebnisse[0], erwartet);
});
