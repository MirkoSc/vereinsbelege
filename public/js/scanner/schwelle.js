// Adaptive black-and-white threshold (docs/spec/03-erfassung-und-ki.md
// section 2: "Schwarzweiß: adaptive Schwelle (Sauvola/Bradley) über
// Integralbild") - the pure math behind turning a straightened, unevenly
// lit receipt photo into crisp black text on white paper. No DOM, no
// canvas: a "bild" here is a plain { width, height, data } object (data an
// RGBA Uint8ClampedArray) so this runs the same in a browser and in Node
// (issue #31/M5-1).
//
// Bradley (not Sauvola) by design: Sauvola additionally needs a running sum
// of squares (a second integral image, twice the memory of the one below -
// on a phone that means ~70 MB more at A4/300dpi) for a variance term this
// project doesn't need; Bradley's simpler "darker than a fraction of the
// local mean" already handles the shadow gradients a photographed receipt
// has. The spec allows either.

/** Integer luma weights (Rec. 601), matching common getImageData() usage. */
function graustufen(bild) {
    const { width, height, data } = bild;
    const anzahl = width * height;
    const ausgabe = new Uint8ClampedArray(anzahl);

    for (let i = 0; i < anzahl; i++) {
        const r = data[i * 4];
        const g = data[i * 4 + 1];
        const b = data[i * 4 + 2];
        ausgabe[i] = Math.round(0.299 * r + 0.587 * g + 0.114 * b);
    }

    return ausgabe;
}

/** `graustufen()` as an RGBA image (mode "Graustufen" in the corner editor). */
function graustufenBild(bild) {
    const grau = graustufen(bild);
    const ausgabe = new Uint8ClampedArray(grau.length * 4);

    for (let i = 0; i < grau.length; i++) {
        ausgabe[i * 4] = grau[i];
        ausgabe[i * 4 + 1] = grau[i];
        ausgabe[i * 4 + 2] = grau[i];
        ausgabe[i * 4 + 3] = bild.data[i * 4 + 3];
    }

    return { width: bild.width, height: bild.height, data: ausgabe };
}

/**
 * Pixel count above which a summed-area table over 0..255 values can
 * overflow a 32-bit unsigned integer (0xFFFFFFFF / 255, floored) - beyond
 * it `integralbild()` falls back to Float64Array. A4 at 300 dpi (~2480 x
 * 3508 = ~8.7 Mio.) stays well under this.
 */
const UINT32_PIXEL_GRENZE = Math.floor(0xffffffff / 255);

/**
 * Summed-area table of `grau` (w x h), (w+1) x (h+1): `integral[y*(w+1)+x]`
 * is the sum of all pixels strictly above and left of (x, y). Lets
 * `bradleySchwelle()` get any rectangle's sum in 4 lookups instead of
 * rescanning the window per pixel.
 */
function integralbild(grau, w, h) {
    const TypedArray = w * h > UINT32_PIXEL_GRENZE ? Float64Array : Uint32Array;
    const breite = w + 1;
    const integral = new TypedArray(breite * (h + 1));

    for (let y = 0; y < h; y++) {
        let zeilensumme = 0;
        for (let x = 0; x < w; x++) {
            zeilensumme += grau[y * w + x];
            integral[(y + 1) * breite + (x + 1)] = integral[y * breite + (x + 1)] + zeilensumme;
        }
    }

    return integral;
}

/**
 * Bradley's adaptive threshold: a pixel is paper (255) if it is brighter
 * than `1 - t` times the mean of the `fenster` x `fenster` window around
 * it, ink (0) otherwise - so a window whose local mean itself sits in a
 * shadow still separates its own text from its own paper, unlike one fixed
 * threshold for the whole image. `fenster` defaults to 1/8 of the longer
 * side (a receipt photo's usual scale); `t` = 0.15 as in Bradley/Roth 2007.
 */
function bradleySchwelle(grau, w, h, optionen) {
    const einstellungen = optionen || {};
    const fenster = einstellungen.fenster || Math.round(Math.max(w, h) / 8);
    const t = einstellungen.t !== undefined ? einstellungen.t : 0.15;
    const radius = Math.max(1, Math.floor(fenster / 2));

    const integral = integralbild(grau, w, h);
    const breite = w + 1;
    const ausgabe = new Uint8ClampedArray(w * h);

    for (let y = 0; y < h; y++) {
        const y0 = Math.max(0, y - radius);
        const y1 = Math.min(h - 1, y + radius);
        for (let x = 0; x < w; x++) {
            const x0 = Math.max(0, x - radius);
            const x1 = Math.min(w - 1, x + radius);
            const anzahl = (x1 - x0 + 1) * (y1 - y0 + 1);

            const summe = integral[(y1 + 1) * breite + (x1 + 1)]
                - integral[y0 * breite + (x1 + 1)]
                - integral[(y1 + 1) * breite + x0]
                + integral[y0 * breite + x0];

            const mittel = summe / anzahl;
            ausgabe[y * w + x] = grau[y * w + x] > mittel * (1 - t) ? 255 : 0;
        }
    }

    return ausgabe;
}

/** `bradleySchwelle()` as an RGBA image (mode "Schwarzweiß" in the corner editor). */
function schwarzweiss(bild, optionen) {
    const grau = graustufen(bild);
    const schwelle = bradleySchwelle(grau, bild.width, bild.height, optionen);
    const ausgabe = new Uint8ClampedArray(schwelle.length * 4);

    for (let i = 0; i < schwelle.length; i++) {
        const wert = schwelle[i];
        ausgabe[i * 4] = wert;
        ausgabe[i * 4 + 1] = wert;
        ausgabe[i * 4 + 2] = wert;
        ausgabe[i * 4 + 3] = bild.data[i * 4 + 3];
    }

    return { width: bild.width, height: bild.height, data: ausgabe };
}

// Node (tests/js) loads the same file; browsers ignore this block because
// `module` does not exist there.
if (typeof module === 'object' && module.exports) {
    module.exports = { graustufen, graustufenBild, integralbild, bradleySchwelle, schwarzweiss };
}
