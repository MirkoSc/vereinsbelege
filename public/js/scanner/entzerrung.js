// Perspective correction (docs/spec/03-erfassung-und-ki.md section 2:
// "Perspektiv-Entzerrung: Homographie aus 4 Punkten, inverse Abbildung mit
// bilinearer Interpolation ... Zielformat aus Seitenverhältnis (A4-Snap
// wenn nahe)") - the pure math behind turning the 4 dragged corners of a
// photographed receipt into a flat, upright image. No DOM, no canvas: a
// "bild" here is a plain { width, height, data } object (data an RGBA
// Uint8ClampedArray of width*height*4 bytes) so this runs the same in a
// browser (from getImageData()) and in Node (issue #31/M5-1).
//
// Corners are always given in the order [oben-links, oben-rechts,
// unten-rechts, unten-links] (clockwise from top-left) - the corner editor
// (M5-3) and the edge detector (M5-2) both produce that order.

// The homography helpers: globals in the browser (classic scripts), a
// require() in Node (tests/js) - same pattern as public/js/erfassen.js's
// dependency on public/js/einreichen.js.
const entzerrungHilfen = typeof module === 'object' && module.exports
    ? require('./homographie.js')
    : { homographie: homographie, abbilden: abbilden, invertieren: invertieren };

/** 297:210 - the long side of A4 is sqrt(2) times the short one. */
const A4_VERHAELTNIS = Math.SQRT2;

function abstand(a, b) {
    return Math.hypot(b.x - a.x, b.y - a.y);
}

/**
 * The pixel size to straighten a quad into. Width/height are the average of
 * the two opposing edge lengths (a perspective photo rarely has all 4 sides
 * exactly equal); a ratio within `a4Toleranz` of sqrt(2) snaps to the exact
 * A4 aspect (portrait or landscape, whichever the quad already is) so a
 * later PDF page (03 §3) doesn't need to letterbox. The long edge is capped
 * at `maxKante` (~300 dpi at A4's 297 mm).
 */
function zielgroesse(ecken, optionen) {
    const einstellungen = optionen || {};
    const maxKante = einstellungen.maxKante || 2480;
    const toleranz = einstellungen.a4Toleranz !== undefined ? einstellungen.a4Toleranz : 0.06;

    const [tl, tr, br, bl] = ecken;
    let breite = (abstand(tl, tr) + abstand(bl, br)) / 2;
    let hoehe = (abstand(tl, bl) + abstand(tr, br)) / 2;

    if (!(breite > 0) || !(hoehe > 0)) {
        return { breite: 1, hoehe: 1 };
    }

    const lang = Math.max(breite, hoehe);
    const kurz = Math.min(breite, hoehe);

    if (Math.abs(lang / kurz - A4_VERHAELTNIS) / A4_VERHAELTNIS <= toleranz) {
        if (breite >= hoehe) {
            hoehe = breite / A4_VERHAELTNIS;
        } else {
            breite = hoehe / A4_VERHAELTNIS;
        }
    }

    const skala = Math.min(1, maxKante / Math.max(breite, hoehe));

    return {
        breite: Math.max(1, Math.round(breite * skala)),
        hoehe: Math.max(1, Math.round(hoehe * skala)),
    };
}

/**
 * Bilinear sample of `bild` at fractional pixel coordinates (x, y);
 * coordinates outside the image are clamped to the edge (the quad's mapped
 * corners land exactly on the border, but rounding can push a sample a
 * fraction past it).
 */
function bilinearAbtasten(bild, x, y) {
    const { width, height, data } = bild;
    const xg = Math.min(Math.max(x, 0), width - 1);
    const yg = Math.min(Math.max(y, 0), height - 1);

    const x0 = Math.floor(xg);
    const y0 = Math.floor(yg);
    const x1 = Math.min(x0 + 1, width - 1);
    const y1 = Math.min(y0 + 1, height - 1);
    const fx = xg - x0;
    const fy = yg - y0;

    const ergebnis = [0, 0, 0, 0];
    for (let kanal = 0; kanal < 4; kanal++) {
        const p00 = data[(y0 * width + x0) * 4 + kanal];
        const p10 = data[(y0 * width + x1) * 4 + kanal];
        const p01 = data[(y1 * width + x0) * 4 + kanal];
        const p11 = data[(y1 * width + x1) * 4 + kanal];

        const oben = p00 + (p10 - p00) * fx;
        const unten = p01 + (p11 - p01) * fx;
        ergebnis[kanal] = oben + (unten - oben) * fy;
    }

    return ergebnis;
}

/**
 * Straightens `bild` into a new `ziel.breite` x `ziel.hoehe` image: for
 * every destination pixel, maps its center back into the source through the
 * inverse homography and bilinearly samples it there - the standard
 * inverse-mapping approach, which unlike a forward mapping never leaves
 * holes in the output. Both sides use the pixel-center convention (pixel
 * (px, py) is the continuous point (px + 0.5, py + 0.5)), so the mapped
 * quad corners land exactly on the output's border.
 *
 * The homography is built as `ecken -> zielEcken` (the source quad first)
 * and then inverted, not the other way round: `homographie()`'s degeneracy
 * check only looks at its first argument's collinearity, and `ecken` - the
 * corners a person actually dragged in the photo - is the side that can be
 * degenerate. `zielEcken` is always a proper rectangle, so computing
 * `homographie(zielEcken, ecken)` directly would silently accept a
 * degenerate `ecken` instead of rejecting it.
 *
 * Throws on a degenerate quad; does not modify `bild`.
 */
function entzerren(bild, ecken, ziel) {
    const zielEcken = [
        { x: 0, y: 0 },
        { x: ziel.breite, y: 0 },
        { x: ziel.breite, y: ziel.hoehe },
        { x: 0, y: ziel.hoehe },
    ];

    const hVorwaerts = entzerrungHilfen.homographie(ecken, zielEcken);
    const h = hVorwaerts === null ? null : entzerrungHilfen.invertieren(hVorwaerts);
    if (h === null) {
        throw new Error('Entartetes Viereck: Homographie nicht lösbar.');
    }

    const ausgabe = new Uint8ClampedArray(ziel.breite * ziel.hoehe * 4);

    for (let py = 0; py < ziel.hoehe; py++) {
        for (let px = 0; px < ziel.breite; px++) {
            const quelle = entzerrungHilfen.abbilden(h, { x: px + 0.5, y: py + 0.5 });
            const werte = quelle === null
                ? [255, 255, 255, 255]
                : bilinearAbtasten(bild, quelle.x - 0.5, quelle.y - 0.5);

            const index = (py * ziel.breite + px) * 4;
            ausgabe[index] = werte[0];
            ausgabe[index + 1] = werte[1];
            ausgabe[index + 2] = werte[2];
            ausgabe[index + 3] = werte[3];
        }
    }

    return { width: ziel.breite, height: ziel.hoehe, data: ausgabe };
}

// Node (tests/js) loads the same file; browsers ignore this block because
// `module` does not exist there.
if (typeof module === 'object' && module.exports) {
    module.exports = { zielgroesse, entzerren, bilinearAbtasten, A4_VERHAELTNIS };
}
