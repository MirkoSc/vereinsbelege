// Corner editor (docs/spec/03-erfassung-und-ki.md section 2: "Manuelle
// Korrektur: 4 ziehbare Eckpunkte (Pointer Events, Touch-Ziel ≥ 44 px, Lupe
// beim Ziehen)" + the colour mode switch "Graustufen / Schwarzweiß /
// Original (Farbe)") - lets a person correct the 4 corners `kantenErkennen()`
// (M5-2) found, or draw them from scratch when it returned `null`, before
// the receipt is straightened. No DOM in the pure helpers below: a "bild" is
// the usual plain { width, height, data } object, ecken are always
// [oben-links, oben-rechts, unten-rechts, unten-links] - the conventions of
// the rest of public/js/scanner/ (issue #33/M5-3).
//
// The DOM part (`eckEditorBinden`, bottom of this file) is the one exception
// among the scanner files: it is what M5-4 (wiring into /einreichen and the
// internal capture) will actually call, so it lives next to the math it
// drives instead of in a separate page-glue file.
//
// Dependencies: globals in the browser (classic scripts, loaded in order
// homographie.js -> entzerrung.js -> schwelle.js -> kanten.js ->
// eckeditor.js), a require() in Node (tests/js) - same pattern as the rest
// of public/js/scanner/.
const eckEditorHilfen = typeof module === 'object' && module.exports
    ? Object.assign({}, require('./entzerrung.js'), require('./schwelle.js'), require('./kanten.js'))
    : {
        zielgroesse: zielgroesse,
        entzerren: entzerren,
        schwarzweiss: schwarzweiss,
        graustufenBild: graustufenBild,
        flaeche: flaeche,
        istKonvex: istKonvex,
    };

/**
 * The default quad when `kantenErkennen()` found none (spec: "der Eck-Editor
 * (M5-3) setzt dann einen eingerückten Standardrahmen statt falscher
 * Ecken") - inset by `einzug` (a fraction of each side) so the handles start
 * clearly separated from the image border and from each other.
 */
function standardRahmen(breite, hoehe, einzug) {
    const rand = einzug === undefined ? 0.1 : einzug;
    const x0 = breite * rand;
    const y0 = hoehe * rand;
    const x1 = breite * (1 - rand);
    const y1 = hoehe * (1 - rand);

    return [
        { x: x0, y: y0 },
        { x: x1, y: y0 },
        { x: x1, y: y1 },
        { x: x0, y: y1 },
    ];
}

/** The "Ganzes Bild" button: the quad is the image's own 4 corners. */
function ganzesBild(breite, hoehe) {
    return [
        { x: 0, y: 0 },
        { x: breite, y: 0 },
        { x: breite, y: hoehe },
        { x: 0, y: hoehe },
    ];
}

/**
 * Fit factor for showing a `bildB` x `bildH` image inside a `maxB` x `maxH`
 * box: never upscales (a small photo shown larger than its own resolution
 * would just look blurry), only shrinks a photo wider or taller than the
 * available space.
 */
function anzeigeSkala(bildB, bildH, maxB, maxH) {
    if (!(bildB > 0) || !(bildH > 0) || !(maxB > 0) || !(maxH > 0)) {
        return 1;
    }

    return Math.min(1, maxB / bildB, maxH / bildH);
}

/** Image point -> on-screen point, at the given display scale. */
function zuAnzeige(punkt, skala) {
    return { x: punkt.x * skala, y: punkt.y * skala };
}

/** On-screen point -> image point, the inverse of `zuAnzeige()`. */
function zuBild(punkt, skala) {
    return { x: punkt.x / skala, y: punkt.y / skala };
}

/**
 * The area below which a quad counts as degenerate for dragging purposes -
 * a sliver too thin to straighten meaningfully, not just mathematically
 * non-zero. 1 % of the image area is generous enough to never block a
 * legitimate small receipt corner, tight enough to reject a handle dragged
 * onto (or past) a neighbour.
 */
const MINDEST_FLAECHE_ANTEIL = 0.01;

/**
 * Moves corner `index` to `punkt` (an image-space point, typically from a
 * pointer/touch event already converted with `zuBild()`), clamped to stay
 * inside the `breite` x `hoehe` image. Returns the new quad only if it is
 * still a valid, non-degenerate, convex shape (homographie.js: "the caller
 * ... must fall back to the last valid quad rather than divide by a
 * near-zero determinant") - otherwise returns `ecken` unchanged, so a drag
 * that would cross an adjacent side simply stops there instead of producing
 * a self-intersecting quad no homography can straighten.
 */
function eckeVerschieben(ecken, index, punkt, breite, hoehe) {
    const geklemmt = {
        x: Math.min(Math.max(punkt.x, 0), breite),
        y: Math.min(Math.max(punkt.y, 0), hoehe),
    };

    const neu = ecken.slice();
    neu[index] = geklemmt;

    if (!eckEditorHilfen.istKonvex(neu)) {
        return ecken;
    }

    const mindestflaeche = breite * hoehe * MINDEST_FLAECHE_ANTEIL;
    if (eckEditorHilfen.flaeche(neu) < mindestflaeche) {
        return ecken;
    }

    return neu;
}

/**
 * Arrow-key nudging for a corner handle (a `<button>`, keyboard-reachable
 * like every other control): 1 image pixel per press, 10 with Shift held -
 * `null` for any other key, so the caller's keydown handler only calls
 * `eckeVerschieben()` for keys that actually mean "move".
 */
function tastaturSchritt(taste, gross) {
    const schritt = gross ? 10 : 1;

    switch (taste) {
        case 'ArrowUp':
            return { dx: 0, dy: -schritt };
        case 'ArrowDown':
            return { dx: 0, dy: schritt };
        case 'ArrowLeft':
            return { dx: -schritt, dy: 0 };
        case 'ArrowRight':
            return { dx: schritt, dy: 0 };
        default:
            return null;
    }
}

/**
 * Where the magnifier (a `lupenGroesse` x `lupenGroesse` square, in display
 * pixels) sits while dragging: normally in the stage's top-left corner, but
 * flipped to the top-right the moment the dragged point would sit under it
 * (a finger dragging the top-left handle must never cover the very
 * magnifier meant to help place it). `punkt` is in display coordinates.
 */
function lupenPosition(punkt, anzeigeB, anzeigeH, lupenGroesse, rand) {
    const kante = rand === undefined ? 8 : rand;
    const linksOben = { x: kante, y: kante };
    const deckungsBereich = lupenGroesse + kante * 2;

    const verdeckt = punkt.x <= deckungsBereich && punkt.y <= deckungsBereich;
    const x = verdeckt ? Math.max(kante, anzeigeB - lupenGroesse - kante) : linksOben.x;

    return { x: x, y: kante };
}

/**
 * The source rectangle (image coordinates) `drawImage()` reads from for the
 * magnifier: a `lupenGroesse` x `lupenGroesse` display square at `zoom`x
 * magnification corresponds, back in the source image, to a square of side
 * `lupenGroesse / (zoom * skala)` centred on `punkt` (an image-space point -
 * `skala` already undoes the display downscale, `zoom` is the extra
 * magnification on top of that).
 */
function lupenAusschnitt(punkt, zoom, lupenGroesse, skala) {
    const seite = lupenGroesse / (zoom * skala);

    return {
        x: punkt.x - seite / 2,
        y: punkt.y - seite / 2,
        breite: seite,
        hoehe: seite,
    };
}

/** The 3 colour modes the spec names, in the order the fieldset shows them. */
const FARBMODI = ['sw', 'grau', 'farbe'];

/**
 * Applies colour mode `modus` to `bild`. "farbe" (the spec's "Original
 * (Farbe)") is a plain copy - callers must not mutate the result in place -
 * so all 3 modes are equally safe to hand off to `ergebnisErzeugen()`.
 */
function farbmodusAnwenden(bild, modus) {
    switch (modus) {
        case 'sw':
            return eckEditorHilfen.schwarzweiss(bild);
        case 'grau':
            return eckEditorHilfen.graustufenBild(bild);
        case 'farbe':
            return { width: bild.width, height: bild.height, data: new Uint8ClampedArray(bild.data) };
        default:
            throw new Error('Unbekannter Farbmodus: ' + modus);
    }
}

/**
 * The one call the upload path (M5-4) needs: straightens `bild` along
 * `ecken` (via entzerrung.js's `zielgroesse`/`entzerren`, so A4-Snap and the
 * max-edge cap apply the same as everywhere else) and applies the chosen
 * colour mode.
 */
function ergebnisErzeugen(bild, ecken, modus, optionen) {
    const ziel = eckEditorHilfen.zielgroesse(ecken, optionen);
    const entzerrt = eckEditorHilfen.entzerren(bild, ecken, ziel);

    return farbmodusAnwenden(entzerrt, modus);
}

// --- DOM binding (browser only - Node has no document) ---------------------
//
// Markup: app/views/partials/eck-editor.php. Structure inside `wurzel`
// (the ".eck-editor" root element):
//   .eck-editor-buehne              position: relative stage, sized to the
//                                    display image
//     canvas.eck-editor-bild        the (possibly colour-mode-filtered)
//                                    photo, drawn at display resolution
//     svg.eck-editor-rahmen         the quad outline, one <polygon>
//     button.eck-editor-griff       x4, data-ecke="0".."3", positioned via
//                                    inline style left/top (not a CSP
//                                    violation: that is a style *property*
//                                    set through CSSOM, not an inline
//                                    <style> element or attribute the CSP's
//                                    style-src would need to allow)
//     canvas.eck-editor-lupe        the magnifier, hidden outside a drag
//   fieldset (radio "sw"/"grau"/"farbe", data-eck-editor="farbmodus")
//   button[data-eck-editor="ganzes-bild"], button[data-eck-editor="reset"]
//
// `quelle` is a CanvasImageSource (an ImageBitmap in practice) at full
// resolution; `ecken` the starting quad in that resolution (from
// `kantenErkennen()`, or `standardRahmen()` when that returned `null`).

const LUPE_ZOOM = 2.5;
const LUPE_GROESSE = 120;

/**
 * Wires up one corner-editor instance. Returns
 * `{ ecken(), farbmodus(), zuruecksetzen() }` so the caller (the designsystem
 * demo now, /einreichen and the internal capture from M5-4 on) can read the
 * current quad and colour mode, or reset to the initial quad.
 */
function eckEditorBinden(wurzel, quelle, ecken) {
    // A clone of every element this function attaches a listener to, in
    // place of the original, so a second call on the same markup (the
    // designsystem demo rebinds when a new test image is chosen) starts
    // with none of the first call's listeners still firing - cloning is
    // simpler and more robust here than tracking every listener to remove.
    ['.eck-editor-griff', '[data-eck-editor]'].forEach((selektor) => {
        wurzel.querySelectorAll(selektor).forEach((element) => {
            element.replaceWith(element.cloneNode(true));
        });
    });

    const buehne = wurzel.querySelector('.eck-editor-buehne');
    const bildCanvas = wurzel.querySelector('.eck-editor-bild');
    const rahmen = wurzel.querySelector('.eck-editor-rahmen');
    const polygon = rahmen.querySelector('polygon');
    const griffe = Array.prototype.slice.call(wurzel.querySelectorAll('.eck-editor-griff'));
    const lupe = wurzel.querySelector('.eck-editor-lupe');
    const lupeContext = lupe.getContext('2d');
    const bildContext = bildCanvas.getContext('2d');

    if (wurzel.eckEditorResizeHandler) {
        window.removeEventListener('resize', wurzel.eckEditorResizeHandler);
    }

    const anfangsEcken = ecken.map((p) => ({ x: p.x, y: p.y }));
    let aktuelleEcken = ecken.map((p) => ({ x: p.x, y: p.y }));
    let farbmodus = 'sw';

    const bildB = quelle.width;
    const bildH = quelle.height;
    let skala = 1;

    function gefiltertesBild() {
        // The DOM-free helpers work on { width, height, data }, not on a
        // CanvasImageSource - one throwaway canvas at display resolution
        // reads it into that shape for the colour-mode preview. Full-
        // resolution filtering happens once, in `ergebnisErzeugen()`, when
        // the receipt is actually accepted (M5-4).
        const w = bildCanvas.width;
        const h = bildCanvas.height;
        const zwischenCanvas = document.createElement('canvas');
        zwischenCanvas.width = w;
        zwischenCanvas.height = h;
        const zwischenContext = zwischenCanvas.getContext('2d');
        zwischenContext.drawImage(quelle, 0, 0, w, h);
        const bild = zwischenContext.getImageData(0, 0, w, h);

        return farbmodusAnwenden({ width: w, height: h, data: bild.data }, farbmodus);
    }

    function bildNeuZeichnen() {
        const gefiltert = gefiltertesBild();
        bildContext.putImageData(
            new ImageData(gefiltert.data, gefiltert.width, gefiltert.height),
            0,
            0,
        );
    }

    function griffePositionieren() {
        aktuelleEcken.forEach((ecke, index) => {
            const anzeige = zuAnzeige(ecke, skala);
            const griff = griffe[index];
            griff.style.left = anzeige.x + 'px';
            griff.style.top = anzeige.y + 'px';
        });

        const punkte = aktuelleEcken
            .map((ecke) => {
                const a = zuAnzeige(ecke, skala);

                return a.x + ',' + a.y;
            })
            .join(' ');
        polygon.setAttribute('points', punkte);
    }

    function groesseAnpassen() {
        // `wurzel`, not `buehne`: `buehne` gets an explicit pixel width
        // below, so re-measuring it on a later resize would just read back
        // that fixed value instead of the container's actual current space
        // (e.g. after a phone rotation).
        const maxBreite = wurzel.clientWidth || bildB;
        skala = anzeigeSkala(bildB, bildH, maxBreite, maxBreite * (bildH / bildB));
        const anzeigeBreite = Math.round(bildB * skala);
        const anzeigeHoehe = Math.round(bildH * skala);

        bildCanvas.width = anzeigeBreite;
        bildCanvas.height = anzeigeHoehe;
        bildCanvas.style.width = anzeigeBreite + 'px';
        bildCanvas.style.height = anzeigeHoehe + 'px';
        rahmen.setAttribute('width', anzeigeBreite);
        rahmen.setAttribute('height', anzeigeHoehe);
        rahmen.setAttribute('viewBox', '0 0 ' + anzeigeBreite + ' ' + anzeigeHoehe);
        buehne.style.width = anzeigeBreite + 'px';
        buehne.style.height = anzeigeHoehe + 'px';

        bildNeuZeichnen();
        griffePositionieren();
    }

    function lupeZeichnen(anzeigePunkt, bildPunkt) {
        const position = lupenPosition(anzeigePunkt, bildCanvas.width, bildCanvas.height, LUPE_GROESSE, 8);
        lupe.style.left = position.x + 'px';
        lupe.style.top = position.y + 'px';
        lupe.hidden = false;

        const ausschnitt = lupenAusschnitt(bildPunkt, LUPE_ZOOM, LUPE_GROESSE, skala);
        lupeContext.clearRect(0, 0, LUPE_GROESSE, LUPE_GROESSE);
        lupeContext.drawImage(
            quelle,
            ausschnitt.x,
            ausschnitt.y,
            ausschnitt.breite,
            ausschnitt.hoehe,
            0,
            0,
            LUPE_GROESSE,
            LUPE_GROESSE,
        );
        // Crosshair marking the exact point the finger/pointer covers.
        lupeContext.strokeStyle = '#e02020';
        lupeContext.lineWidth = 1;
        lupeContext.beginPath();
        lupeContext.moveTo(LUPE_GROESSE / 2, 0);
        lupeContext.lineTo(LUPE_GROESSE / 2, LUPE_GROESSE);
        lupeContext.moveTo(0, LUPE_GROESSE / 2);
        lupeContext.lineTo(LUPE_GROESSE, LUPE_GROESSE / 2);
        lupeContext.stroke();
    }

    function stagePunkt(ereignis) {
        const feld = buehne.getBoundingClientRect();

        return { x: ereignis.clientX - feld.left, y: ereignis.clientY - feld.top };
    }

    griffe.forEach((griff) => {
        const index = Number(griff.dataset.ecke);
        let ziehend = false;

        griff.addEventListener('pointerdown', (ereignis) => {
            ziehend = true;
            griff.setPointerCapture(ereignis.pointerId);
            ereignis.preventDefault();
        });

        griff.addEventListener('pointermove', (ereignis) => {
            if (!ziehend) {
                return;
            }

            const anzeigePunkt = stagePunkt(ereignis);
            const bildPunkt = zuBild(anzeigePunkt, skala);
            aktuelleEcken = eckeVerschieben(aktuelleEcken, index, bildPunkt, bildB, bildH);
            griffePositionieren();
            lupeZeichnen(anzeigePunkt, bildPunkt);
        });

        function ziehenBeenden(ereignis) {
            if (!ziehend) {
                return;
            }
            ziehend = false;
            griff.releasePointerCapture(ereignis.pointerId);
            lupe.hidden = true;
        }

        griff.addEventListener('pointerup', ziehenBeenden);
        griff.addEventListener('pointercancel', ziehenBeenden);

        griff.addEventListener('keydown', (ereignis) => {
            const schritt = tastaturSchritt(ereignis.key, ereignis.shiftKey);
            if (schritt === null) {
                return;
            }
            ereignis.preventDefault();

            const ecke = aktuelleEcken[index];
            aktuelleEcken = eckeVerschieben(
                aktuelleEcken,
                index,
                { x: ecke.x + schritt.dx, y: ecke.y + schritt.dy },
                bildB,
                bildH,
            );
            griffePositionieren();
        });
    });

    wurzel.querySelectorAll('[data-eck-editor="farbmodus"]').forEach((eingabe) => {
        eingabe.addEventListener('change', () => {
            if (eingabe.checked) {
                farbmodus = eingabe.value;
                bildNeuZeichnen();
            }
        });
    });

    const ganzesBildKnopf = wurzel.querySelector('[data-eck-editor="ganzes-bild"]');
    if (ganzesBildKnopf !== null) {
        ganzesBildKnopf.addEventListener('click', () => {
            aktuelleEcken = ganzesBild(bildB, bildH);
            griffePositionieren();
        });
    }

    const resetKnopf = wurzel.querySelector('[data-eck-editor="reset"]');
    if (resetKnopf !== null) {
        resetKnopf.addEventListener('click', () => {
            aktuelleEcken = anfangsEcken.map((p) => ({ x: p.x, y: p.y }));
            griffePositionieren();
        });
    }

    wurzel.eckEditorResizeHandler = groesseAnpassen;
    window.addEventListener('resize', groesseAnpassen);
    groesseAnpassen();

    return {
        ecken: () => aktuelleEcken.map((p) => ({ x: p.x, y: p.y })),
        farbmodus: () => farbmodus,
        zuruecksetzen: () => {
            aktuelleEcken = anfangsEcken.map((p) => ({ x: p.x, y: p.y }));
            griffePositionieren();
        },
    };
}

// Node (tests/js) loads the same file; browsers ignore this block because
// `module` does not exist there.
if (typeof module === 'object' && module.exports) {
    module.exports = {
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
    };
}
