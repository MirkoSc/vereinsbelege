// Edge detection (docs/spec/03-erfassung-und-ki.md section 2:
// "Kantenerkennung: Graustufen → Weichzeichnen → Kanten → größtes Viereck")
// - the pure math behind finding a photographed receipt's four corners, so
// the corner editor (M5-3) opens pre-filled instead of empty. No DOM, no
// canvas: a "bild" here is a plain { width, height, data } object (data an
// RGBA Uint8ClampedArray), same convention as the rest of
// public/js/scanner/ (issue #32/M5-2).
//
// Decision E-03 (docs/ENTSCHEIDUNGEN.md): a lean, dependency-free pipeline
// (Sobel edges + Hough line voting + the largest well-supported
// quadrilateral) on a downscaled image, measured against real, anonymised
// receipt photos (tests/fixtures/scanner/belegfotos/) instead of vendoring
// OpenCV.js - see ENTSCHEIDUNGEN.md for the measured hit rate.
//
// `graustufen()` comes from schwelle.js: a global in the browser (classic
// scripts), a require() in Node (tests/js) - same pattern as
// entzerrung.js's dependency on homographie.js.
const kantenHilfen = typeof module === 'object' && module.exports
    ? require('./schwelle.js')
    : { graustufen: graustufen };

/**
 * Downscales a grayscale plane (from `graustufen()`, `width x height`) to at
 * most `maxKante` on its long edge, by area-averaging each destination
 * pixel's source rectangle - unlike nearest-neighbour or bilinear
 * downsampling, this doesn't alias away a receipt's edge in a high-
 * resolution phone photo. Edge detection then runs on this small image,
 * fast enough for a phone; `zurueck` (source pixels per downscaled pixel)
 * maps found corners back to the original resolution.
 */
function verkleinern(grau, w, h, maxKante) {
    const grenze = maxKante || 500;
    const skala = Math.min(1, grenze / Math.max(w, h));
    const zielBreite = Math.max(1, Math.round(w * skala));
    const zielHoehe = Math.max(1, Math.round(h * skala));

    const ausgabe = new Float64Array(zielBreite * zielHoehe);
    for (let zy = 0; zy < zielHoehe; zy++) {
        const y0 = Math.floor((zy * h) / zielHoehe);
        const y1 = Math.max(y0 + 1, Math.floor(((zy + 1) * h) / zielHoehe));
        for (let zx = 0; zx < zielBreite; zx++) {
            const x0 = Math.floor((zx * w) / zielBreite);
            const x1 = Math.max(x0 + 1, Math.floor(((zx + 1) * w) / zielBreite));

            let summe = 0;
            let anzahl = 0;
            for (let sy = y0; sy < Math.min(y1, h); sy++) {
                for (let sx = x0; sx < Math.min(x1, w); sx++) {
                    summe += grau[sy * w + sx];
                    anzahl++;
                }
            }
            ausgabe[zy * zielBreite + zx] = anzahl > 0 ? summe / anzahl : 0;
        }
    }

    return { daten: ausgabe, breite: zielBreite, hoehe: zielHoehe, zurueck: 1 / skala };
}

/** Binomial 5-tap approximation of a Gaussian (sigma ~ 1), separable. */
const WEICHZEICHNER_KERNEL = [1, 4, 6, 4, 1];
const WEICHZEICHNER_SUMME = 16;

function falten1d(daten, breite, hoehe, horizontal) {
    const ausgabe = new Float64Array(daten.length);
    const radius = 2;

    for (let y = 0; y < hoehe; y++) {
        for (let x = 0; x < breite; x++) {
            let summe = 0;
            for (let k = -radius; k <= radius; k++) {
                const sx = horizontal ? Math.min(Math.max(x + k, 0), breite - 1) : x;
                const sy = horizontal ? y : Math.min(Math.max(y + k, 0), hoehe - 1);
                summe += daten[sy * breite + sx] * WEICHZEICHNER_KERNEL[k + radius];
            }
            ausgabe[y * breite + x] = summe / WEICHZEICHNER_SUMME;
        }
    }

    return ausgabe;
}

/** Blurs a grayscale plane against sensor noise before edge detection. */
function weichzeichnen(daten, breite, hoehe) {
    return falten1d(falten1d(daten, breite, hoehe, true), breite, hoehe, false);
}

/** Sobel gradient: magnitude (edge strength) and direction (radians) per pixel. */
function sobel(daten, breite, hoehe) {
    const betrag = new Float64Array(breite * hoehe);
    const richtung = new Float64Array(breite * hoehe);

    for (let y = 0; y < hoehe; y++) {
        const y0 = Math.max(0, y - 1);
        const y1 = Math.min(hoehe - 1, y + 1);
        for (let x = 0; x < breite; x++) {
            const x0 = Math.max(0, x - 1);
            const x1 = Math.min(breite - 1, x + 1);

            const p00 = daten[y0 * breite + x0];
            const p01 = daten[y0 * breite + x];
            const p02 = daten[y0 * breite + x1];
            const p10 = daten[y * breite + x0];
            const p12 = daten[y * breite + x1];
            const p20 = daten[y1 * breite + x0];
            const p21 = daten[y1 * breite + x];
            const p22 = daten[y1 * breite + x1];

            const gx = (p02 + 2 * p12 + p22) - (p00 + 2 * p10 + p20);
            const gy = (p20 + 2 * p21 + p22) - (p00 + 2 * p01 + p02);

            const i = y * breite + x;
            betrag[i] = Math.hypot(gx, gy);
            richtung[i] = Math.atan2(gy, gx);
        }
    }

    return { betrag, richtung };
}

/** The p-th (0..1) value of `werte`, e.g. p=0.92 for the 92nd percentile. */
function perzentil(werte, p) {
    const sortiert = Float64Array.from(werte).sort();
    const index = Math.min(sortiert.length - 1, Math.max(0, Math.floor(p * sortiert.length)));

    return sortiert[index];
}

/**
 * Thins Sobel ridges to 1px by keeping only local maxima along the (4-way
 * rounded) gradient direction - the standard non-maximum-suppression step
 * of Canny-style edge detection, without full hysteresis linking (the Hough
 * transform below tolerates a few broken pixels along a real edge fine).
 */
function nichtMaximaUnterdrueckung(betrag, richtung, breite, hoehe) {
    const ausgabe = new Float64Array(breite * hoehe);

    for (let y = 1; y < hoehe - 1; y++) {
        for (let x = 1; x < breite - 1; x++) {
            const i = y * breite + x;
            const grad = ((richtung[i] * 180) / Math.PI + 180) % 180;

            let dx1 = 1;
            let dy1 = 0;
            if (grad >= 22.5 && grad < 67.5) {
                dx1 = 1;
                dy1 = 1;
            } else if (grad >= 67.5 && grad < 112.5) {
                dx1 = 0;
                dy1 = 1;
            } else if (grad >= 112.5 && grad < 157.5) {
                dx1 = 1;
                dy1 = -1;
            }

            const wert = betrag[i];
            const nachbar1 = betrag[(y + dy1) * breite + (x + dx1)];
            const nachbar2 = betrag[(y - dy1) * breite + (x - dx1)];

            ausgabe[i] = wert >= nachbar1 && wert >= nachbar2 ? wert : 0;
        }
    }

    return ausgabe;
}

/**
 * Binary edge map: blur, Sobel, non-maximum suppression, then a single
 * threshold derived from the image itself (a percentile of the suppressed
 * magnitudes) rather than a fixed value - receipt photos vary too much in
 * exposure and paper contrast for one constant to work across all of them.
 */
function kantenKarte(grau, breite, hoehe, optionen) {
    const einstellungen = optionen || {};
    const perzentilSchwelle = einstellungen.perzentil !== undefined ? einstellungen.perzentil : 0.92;

    const geglaettet = weichzeichnen(grau, breite, hoehe);
    const { betrag, richtung } = sobel(geglaettet, breite, hoehe);
    const unterdrueckt = nichtMaximaUnterdrueckung(betrag, richtung, breite, hoehe);

    const schwelle = perzentil(unterdrueckt, perzentilSchwelle);
    const ausgabe = new Uint8ClampedArray(breite * hoehe);
    if (schwelle > 0) {
        for (let i = 0; i < ausgabe.length; i++) {
            ausgabe[i] = unterdrueckt[i] > schwelle ? 255 : 0;
        }
    }

    // The blur's and Sobel's edge-clamped padding (no real pixels beyond the
    // frame) systematically raises the gradient right at the border - a
    // straight, artificial "edge" a real photo doesn't have. Blanking the
    // outer few pixels (the blur/Sobel kernel radius) keeps that out of the
    // Hough vote instead of it winning over the receipt's real edges.
    const rand = 4;
    for (let y = 0; y < hoehe; y++) {
        for (let x = 0; x < breite; x++) {
            if (x < rand || x >= breite - rand || y < rand || y >= hoehe - rand) {
                ausgabe[y * breite + x] = 0;
            }
        }
    }

    return ausgabe;
}

/**
 * Repeatedly takes the accumulator's global maximum as a peak, then zeroes
 * a window around it (in both theta, wrapping at 180°, and rho) before the
 * next round - keeps the Hough transform from returning a cluster of
 * near-identical lines for one real edge.
 */
function staerksteSpitzen(akkumulator, anzahlWinkel, anzahlRho, anzahl) {
    const kopie = Uint32Array.from(akkumulator);
    const ergebnisse = [];
    const fensterTheta = Math.max(1, Math.round(anzahlWinkel / 30));
    const fensterRho = Math.max(1, Math.round(anzahlRho / 60));

    for (let n = 0; n < anzahl; n++) {
        let bester = -1;
        let besterWert = 2; // mindestens 3 Stimmen, sonst ist es Rauschen

        for (let i = 0; i < kopie.length; i++) {
            if (kopie[i] > besterWert) {
                besterWert = kopie[i];
                bester = i;
            }
        }
        if (bester < 0) {
            break;
        }

        const theta = Math.floor(bester / anzahlRho);
        const rho = bester % anzahlRho;
        ergebnisse.push({ theta, rho, stimmen: besterWert });

        for (let dt = -fensterTheta; dt <= fensterTheta; dt++) {
            const t = ((theta + dt) % anzahlWinkel + anzahlWinkel) % anzahlWinkel;
            for (let dr = -fensterRho; dr <= fensterRho; dr++) {
                const r = rho + dr;
                if (r >= 0 && r < anzahlRho) {
                    kopie[t * anzahlRho + r] = 0;
                }
            }
        }
    }

    return ergebnisse;
}

/**
 * Standard Hough transform over `kanten`'s edge pixels: votes for lines
 * `x*cos(theta) + y*sin(theta) = rho`, returns the strongest peaks split
 * into "eher senkrecht" (theta near 0°/180° - a receipt's left/right edge)
 * and "eher waagerecht" (theta near 90° - its top/bottom edge), since
 * `viereckAuswaehlen()` needs one line from each group per side.
 */
function houghLinien(kanten, breite, hoehe, optionen) {
    const einstellungen = optionen || {};
    const aufloesungGrad = einstellungen.aufloesungGrad || 1;
    const anzahlWinkel = Math.round(180 / aufloesungGrad);
    const diagonale = Math.ceil(Math.hypot(breite, hoehe));
    const rhoOffset = diagonale;
    const anzahlRho = diagonale * 2 + 1;

    const cosTab = new Float64Array(anzahlWinkel);
    const sinTab = new Float64Array(anzahlWinkel);
    for (let t = 0; t < anzahlWinkel; t++) {
        const winkel = (t * aufloesungGrad * Math.PI) / 180;
        cosTab[t] = Math.cos(winkel);
        sinTab[t] = Math.sin(winkel);
    }

    const akkumulator = new Uint32Array(anzahlWinkel * anzahlRho);
    for (let y = 0; y < hoehe; y++) {
        for (let x = 0; x < breite; x++) {
            if (kanten[y * breite + x] === 0) {
                continue;
            }
            for (let t = 0; t < anzahlWinkel; t++) {
                const rho = Math.round(x * cosTab[t] + y * sinTab[t]) + rhoOffset;
                akkumulator[t * anzahlRho + rho]++;
            }
        }
    }

    const kandidaten = staerksteSpitzen(akkumulator, anzahlWinkel, anzahlRho, einstellungen.anzahlLinien || 40);

    const waagerecht = [];
    const senkrecht = [];
    for (const kandidat of kandidaten) {
        const gradWinkel = kandidat.theta * aufloesungGrad;
        const linie = {
            theta: (gradWinkel * Math.PI) / 180,
            rho: kandidat.rho - rhoOffset,
            stimmen: kandidat.stimmen,
        };
        (gradWinkel > 45 && gradWinkel < 135 ? waagerecht : senkrecht).push(linie);
    }

    return { waagerecht, senkrecht };
}

/**
 * Intersection of two lines given as `{ rho, theta }`; `null` if they are
 * (near-)parallel, which two lines from the same Hough group can be.
 */
function schnittpunkt(a, b) {
    const det = Math.cos(a.theta) * Math.sin(b.theta) - Math.cos(b.theta) * Math.sin(a.theta);
    if (Math.abs(det) < 1e-6) {
        return null;
    }

    const x = (a.rho * Math.sin(b.theta) - b.rho * Math.sin(a.theta)) / det;
    const y = (Math.cos(a.theta) * b.rho - Math.cos(b.theta) * a.rho) / det;

    return { x, y };
}

/** Shoelace formula. */
function flaeche(punkte) {
    let summe = 0;
    for (let i = 0; i < punkte.length; i++) {
        const a = punkte[i];
        const b = punkte[(i + 1) % punkte.length];
        summe += a.x * b.y - b.x * a.y;
    }

    return Math.abs(summe) / 2;
}

/** True if `punkte` (in cyclic order) form a convex polygon. */
function istKonvex(punkte) {
    let vorzeichen = 0;
    for (let i = 0; i < punkte.length; i++) {
        const a = punkte[i];
        const b = punkte[(i + 1) % punkte.length];
        const c = punkte[(i + 2) % punkte.length];
        const kreuz = (b.x - a.x) * (c.y - b.y) - (b.y - a.y) * (c.x - b.x);
        if (Math.abs(kreuz) < 1e-9) {
            continue;
        }
        const aktuell = kreuz > 0 ? 1 : -1;
        if (vorzeichen === 0) {
            vorzeichen = aktuell;
        } else if (aktuell !== vorzeichen) {
            return false;
        }
    }

    return true;
}

/**
 * Orders 4 points (any input order, any rotation) as [oben-links,
 * oben-rechts, unten-rechts, unten-links] (docs/spec/03-erfassung-und-ki.md
 * section 2): sorts them by angle around their centroid, which for a convex
 * quad always yields this clockwise cyclic order (image y grows downward,
 * so increasing atan2(dy,dx) walks clockwise), then rotates the cycle so it
 * starts at the point closest to "top-left" (smallest x+y) - unlike a plain
 * sum/difference split, this stays correct for a receipt photographed at a
 * strong angle (M5-2 issue #32 finding: the old version flipped past ~45°),
 * not just one held roughly upright.
 */
function eckenOrdnen(punkte) {
    let cx = 0;
    let cy = 0;
    for (const p of punkte) {
        cx += p.x;
        cy += p.y;
    }
    cx /= punkte.length;
    cy /= punkte.length;

    const sortiert = [...punkte].sort(
        (a, b) => Math.atan2(a.y - cy, a.x - cx) - Math.atan2(b.y - cy, b.x - cx),
    );

    let startIndex = 0;
    let minSumme = Infinity;
    for (let i = 0; i < sortiert.length; i++) {
        const summe = sortiert[i].x + sortiert[i].y;
        if (summe < minSumme) {
            minSumme = summe;
            startIndex = i;
        }
    }

    const ergebnis = [];
    for (let i = 0; i < sortiert.length; i++) {
        ergebnis.push(sortiert[(startIndex + i) % sortiert.length]);
    }

    return ergebnis;
}

/** `grau`'s value at the nearest in-bounds pixel to (x, y). */
function grauWert(grau, breite, hoehe, x, y) {
    const xi = Math.min(Math.max(Math.round(x), 0), breite - 1);
    const yi = Math.min(Math.max(Math.round(y), 0), hoehe - 1);

    return grau[yi * breite + xi];
}

/**
 * Fraction of points sampled along the segment `p1`-`p2` (a candidate quad
 * side, `p1`->`p2` in the clockwise [oben-links, oben-rechts, unten-rechts,
 * unten-links] order `eckenOrdnen()` produces) that both sit on an edge
 * pixel (within `radius`) and, when `grau` is given, are brighter a few
 * pixels to the inside of the quad than to the outside by at least
 * `kontrastMindest` - a receipt is paper, brighter than whatever it lies on.
 * Plain edge presence alone can't tell the receipt's own (possibly fainter)
 * edge apart from a nearby unrelated strong edge with the wrong side bright
 * (a table edge just past the receipt) or no brightness step at all (a
 * printed line inside the receipt) - both real edges, both on the wrong
 * side of `mindestStuetzung` without this check (issue #32/M5-2 finding).
 */
function kanteUnterstuetzung(kanten, breite, hoehe, p1, p2, radius, grau, kontrastMindest) {
    const anzahlProben = 24;
    let treffer = 0;

    const dx = p2.x - p1.x;
    const dy = p2.y - p1.y;
    const laenge = Math.hypot(dx, dy) || 1;
    // Nach innen zeigende Normale: eckenOrdnen() liefert die Ecken im
    // Uhrzeigersinn, darin zeigt (-dy, dx)/laenge stets nach innen (siehe
    // Funktionskommentar dort für die Herleitung an einem Rechteck-Beispiel).
    const nx = -dy / laenge;
    const ny = dx / laenge;
    const versatz = Math.max(3, radius + 2);

    for (let i = 0; i <= anzahlProben; i++) {
        const t = i / anzahlProben;
        const x = p1.x + dx * t;
        const y = p1.y + dy * t;
        const xi = Math.round(x);
        const yi = Math.round(y);

        let aufKante = false;
        for (let dyk = -radius; dyk <= radius && !aufKante; dyk++) {
            const nyk = yi + dyk;
            if (nyk < 0 || nyk >= hoehe) {
                continue;
            }
            for (let dxk = -radius; dxk <= radius; dxk++) {
                const nxk = xi + dxk;
                if (nxk >= 0 && nxk < breite && kanten[nyk * breite + nxk] > 0) {
                    aufKante = true;
                    break;
                }
            }
        }

        let kontrastPasst = true;
        if (aufKante && grau) {
            const innenWert = grauWert(grau, breite, hoehe, x + nx * versatz, y + ny * versatz);
            const aussenWert = grauWert(grau, breite, hoehe, x - nx * versatz, y - ny * versatz);
            kontrastPasst = innenWert - aussenWert >= kontrastMindest;
        }

        if (aufKante && kontrastPasst) {
            treffer++;
        }
    }

    return treffer / (anzahlProben + 1);
}

/**
 * Picks the receipt's quadrilateral out of the Hough line groups: tries
 * every pair of the strongest "waagerecht" lines against every pair of the
 * strongest "senkrecht" lines (their 4 pairwise intersections are the
 * candidate corners), rejects a candidate that is not convex, too small, or
 * strays too far outside the image, and scores the rest by how much of
 * their 4 sides actually sit on a detected, correctly-oriented edge (see
 * `kanteUnterstuetzung()`) plus their area - so a smaller quad found inside
 * the receipt (e.g. a printed table) loses against the receipt's own,
 * better-supported outline. `null` if nothing scores above `mindestStuetzung`.
 */
function viereckAuswaehlen(linien, breite, hoehe, kanten, optionen) {
    const einstellungen = optionen || {};
    const topK = einstellungen.topK || 10;
    const flaechenAnteilMindest = einstellungen.flaechenAnteilMindest !== undefined
        ? einstellungen.flaechenAnteilMindest
        : 0.15;
    const mindestStuetzung = einstellungen.mindestStuetzung !== undefined ? einstellungen.mindestStuetzung : 0.4;
    const rand = einstellungen.rand !== undefined ? einstellungen.rand : Math.round(Math.max(breite, hoehe) * 0.08);
    const grau = einstellungen.grau || null;
    const kontrastMindest = einstellungen.kontrastMindest !== undefined ? einstellungen.kontrastMindest : 15;

    const waagerecht = [...linien.waagerecht].sort((a, b) => b.stimmen - a.stimmen).slice(0, topK);
    const senkrecht = [...linien.senkrecht].sort((a, b) => b.stimmen - a.stimmen).slice(0, topK);

    let bestesViereck = null;
    let besteWertung = -Infinity;

    for (let i = 0; i < waagerecht.length; i++) {
        for (let j = i + 1; j < waagerecht.length; j++) {
            for (let k = 0; k < senkrecht.length; k++) {
                for (let l = k + 1; l < senkrecht.length; l++) {
                    const eckenRoh = [
                        schnittpunkt(waagerecht[i], senkrecht[k]),
                        schnittpunkt(waagerecht[i], senkrecht[l]),
                        schnittpunkt(waagerecht[j], senkrecht[l]),
                        schnittpunkt(waagerecht[j], senkrecht[k]),
                    ];
                    if (eckenRoh.some((p) => p === null)) {
                        continue;
                    }

                    const innerhalb = eckenRoh.every(
                        (p) => p.x >= -rand && p.x <= breite + rand && p.y >= -rand && p.y <= hoehe + rand,
                    );
                    if (!innerhalb) {
                        continue;
                    }

                    const ecken = eckenOrdnen(eckenRoh);
                    if (!istKonvex(ecken)) {
                        continue;
                    }

                    const flaechenAnteil = flaeche(ecken) / (breite * hoehe);
                    if (flaechenAnteil < flaechenAnteilMindest || flaechenAnteil > 1.05) {
                        continue;
                    }

                    let stuetzung = 0;
                    for (let seite = 0; seite < 4; seite++) {
                        stuetzung += kanteUnterstuetzung(
                            kanten,
                            breite,
                            hoehe,
                            ecken[seite],
                            ecken[(seite + 1) % 4],
                            2,
                            grau,
                            kontrastMindest,
                        );
                    }
                    stuetzung /= 4;
                    if (stuetzung < mindestStuetzung) {
                        continue;
                    }

                    const wertung = stuetzung * 0.7 + Math.min(flaechenAnteil, 1) * 0.3;
                    if (wertung > besteWertung) {
                        besteWertung = wertung;
                        bestesViereck = ecken;
                    }
                }
            }
        }
    }

    return bestesViereck;
}

/**
 * Finds a photographed receipt's 4 corners in `bild`, or `null` if none is
 * found confidently enough - the caller (the corner editor, M5-3) then
 * falls back to a fixed inset rectangle instead of showing wrong corners.
 * Runs the full pipeline (downscale, blur, Sobel, Hough, quad selection) and
 * maps the result back to `bild`'s original resolution.
 */
function kantenErkennen(bild, optionen) {
    const einstellungen = optionen || {};
    const maxKante = einstellungen.maxKante || 500;

    const grauVoll = kantenHilfen.graustufen(bild);
    const { daten: grauKlein, breite, hoehe, zurueck } = verkleinern(grauVoll, bild.width, bild.height, maxKante);

    const kanten = kantenKarte(grauKlein, breite, hoehe, einstellungen.kanten);
    const linien = houghLinien(kanten, breite, hoehe, einstellungen.hough);
    if (linien.waagerecht.length < 2 || linien.senkrecht.length < 2) {
        return null;
    }

    const viereckOptionen = Object.assign({ grau: grauKlein }, einstellungen.viereck);
    const eckenKlein = viereckAuswaehlen(linien, breite, hoehe, kanten, viereckOptionen);
    if (eckenKlein === null) {
        return null;
    }

    return eckenKlein.map((p) => ({
        x: Math.min(Math.max(p.x * zurueck, 0), bild.width),
        y: Math.min(Math.max(p.y * zurueck, 0), bild.height),
    }));
}

// Node (tests/js) loads the same file; browsers ignore this block because
// `module` does not exist there.
if (typeof module === 'object' && module.exports) {
    module.exports = {
        verkleinern,
        weichzeichnen,
        sobel,
        kantenKarte,
        houghLinien,
        schnittpunkt,
        eckenOrdnen,
        viereckAuswaehlen,
        kantenErkennen,
    };
}
