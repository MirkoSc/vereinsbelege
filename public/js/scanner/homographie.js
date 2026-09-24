// Homography from 4 point correspondences (docs/spec/03-erfassung-und-ki.md
// section 2: "Perspektiv-Entzerrung: Homographie aus 4 Punkten") - the pure
// math behind straightening a corner-dragged receipt photo. No DOM, no
// canvas: public/js/scanner/entzerrung.js is the only caller that touches
// pixels (issue #31/M5-1).
//
// A point is { x, y }. A homography is a flat 9-element array (row-major
// 3x3, h[8] normalized to 1) mapping a source point to a destination point
// in homogeneous coordinates: [X*w, Y*w, w] = H * [x, y, 1].

/**
 * Solves the 3x3 homography H with H*von[i] ~ nach[i] for i = 0..3 - the
 * direct linear transform, h[8] fixed at 1 so 8 unknowns remain, exactly
 * what 4 point pairs give (2 equations each). `null` if the system is
 * singular (e.g. 3 or more of the 4 `von` points collinear) - the caller
 * (the corner editor) must fall back to the last valid quad rather than
 * divide by a near-zero determinant.
 */
function homographie(von, nach) {
    if (!Array.isArray(von) || !Array.isArray(nach) || von.length !== 4 || nach.length !== 4) {
        return null;
    }

    // 8 equations (2 per point pair) in 8 unknowns h0..h7, the right-hand
    // side appended as a 9th column for in-place Gaussian elimination.
    const zeilen = [];
    for (let i = 0; i < 4; i++) {
        const { x, y } = von[i];
        const { x: X, y: Y } = nach[i];

        zeilen.push([x, y, 1, 0, 0, 0, -x * X, -y * X, X]);
        zeilen.push([0, 0, 0, x, y, 1, -x * Y, -y * Y, Y]);
    }

    const h = loeseLinearesGleichungssystem(zeilen);

    return h === null ? null : [h[0], h[1], h[2], h[3], h[4], h[5], h[6], h[7], 1];
}

/**
 * Gauss-Jordan elimination with partial pivoting on an 8x9 augmented matrix
 * (each row: 8 coefficients + 1 right-hand side). Operates on a private
 * copy; `null` on a singular system (pivot below EPS) instead of returning
 * garbage from dividing by ~0.
 */
function loeseLinearesGleichungssystem(zeilenEingabe) {
    const n = zeilenEingabe.length;
    const zeilen = zeilenEingabe.map((zeile) => zeile.slice());
    const EPS = 1e-10;

    for (let spalte = 0; spalte < n; spalte++) {
        let pivotZeile = spalte;
        for (let i = spalte + 1; i < n; i++) {
            if (Math.abs(zeilen[i][spalte]) > Math.abs(zeilen[pivotZeile][spalte])) {
                pivotZeile = i;
            }
        }

        if (Math.abs(zeilen[pivotZeile][spalte]) < EPS) {
            return null;
        }

        if (pivotZeile !== spalte) {
            [zeilen[spalte], zeilen[pivotZeile]] = [zeilen[pivotZeile], zeilen[spalte]];
        }

        const pivot = zeilen[spalte][spalte];
        for (let i = 0; i < n; i++) {
            if (i === spalte) {
                continue;
            }

            const faktor = zeilen[i][spalte] / pivot;
            if (faktor === 0) {
                continue;
            }

            for (let k = spalte; k <= n; k++) {
                zeilen[i][k] -= faktor * zeilen[spalte][k];
            }
        }
    }

    return zeilen.map((zeile, i) => zeile[n] / zeile[i]);
}

/** Applies H to a point in homogeneous coordinates; `null` if w is ~0. */
function abbilden(h, punkt) {
    const w = h[6] * punkt.x + h[7] * punkt.y + h[8];
    if (Math.abs(w) < 1e-10) {
        return null;
    }

    return {
        x: (h[0] * punkt.x + h[1] * punkt.y + h[2]) / w,
        y: (h[3] * punkt.x + h[4] * punkt.y + h[5]) / w,
    };
}

/**
 * The inverse homography, re-normalized so its own h[8] is 1 again (the
 * same convention `homographie()` returns). `null` if H is singular.
 */
function invertieren(h) {
    const [h11, h12, h13, h21, h22, h23, h31, h32, h33] = h;
    const det = h11 * (h22 * h33 - h23 * h32)
        - h12 * (h21 * h33 - h23 * h31)
        + h13 * (h21 * h32 - h22 * h31);

    if (Math.abs(det) < 1e-10) {
        return null;
    }

    // Adjugate (transpose of the cofactor matrix), same row-major layout as
    // `h`. H^-1 = adj/det, and normalizing its element 8 back to 1 collapses
    // to adj/adj[8] - det cancels out of every element the same way.
    const adj = [
        h22 * h33 - h23 * h32, h13 * h32 - h12 * h33, h12 * h23 - h13 * h22,
        h23 * h31 - h21 * h33, h11 * h33 - h13 * h31, h13 * h21 - h11 * h23,
        h21 * h32 - h22 * h31, h12 * h31 - h11 * h32, h11 * h22 - h12 * h21,
    ];

    if (Math.abs(adj[8]) < 1e-10) {
        return null;
    }

    return adj.map((wert) => wert / adj[8]);
}

// Node (tests/js) loads the same file; browsers ignore this block because
// `module` does not exist there.
if (typeof module === 'object' && module.exports) {
    module.exports = { homographie, abbilden, invertieren };
}
