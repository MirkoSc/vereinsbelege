// The live camera of the capture pages (issue #34/M5-4,
// public/js/scanner/kamera.js): availability, the getUserMedia constraints,
// the guide frame's geometry and the error texts. The stream itself needs a
// real browser, see the PR's manual checklist.

const test = require('node:test');
const assert = require('node:assert/strict');
const {
    KAMERA_QUALITAET,
    KAMERA_RAHMEN_VERHAELTNIS,
    kameraVerfuegbar,
    kameraEinschraenkungen,
    rahmenGeometrie,
    kameraFehlertext,
    aufnahmeName,
} = require('../../public/js/scanner/kamera.js');

const mitKamera = { isSecureContext: true, navigator: { mediaDevices: { getUserMedia: function () {} } } };

test('the live camera needs getUserMedia in a secure context', () => {
    assert.equal(kameraVerfuegbar(mitKamera), true);
    assert.equal(kameraVerfuegbar(Object.assign({}, mitKamera, { isSecureContext: false })), false, 'plain http');
    assert.equal(kameraVerfuegbar({ isSecureContext: true, navigator: {} }), false, 'old browser');
    assert.equal(kameraVerfuegbar({ isSecureContext: true, navigator: { mediaDevices: {} } }), false);
    assert.equal(kameraVerfuegbar(null), false);
});

test('the rear camera is asked for, but only as ideal, and without sound', () => {
    const einschraenkungen = kameraEinschraenkungen();

    assert.equal(einschraenkungen.audio, false);
    assert.deepEqual(einschraenkungen.video.facingMode, { ideal: 'environment' });
    assert.ok(einschraenkungen.video.width.ideal >= 1920, 'enough pixels for small print');
});

test('the guide frame is A4 portrait, centred, inside the video with a margin', () => {
    for (const [breite, hoehe] of [[640, 480], [360, 640], [1080, 1920], [1920, 1080], [300, 300]]) {
        const rahmen = rahmenGeometrie(breite, hoehe);

        assert.ok(rahmen.x > 0 && rahmen.y > 0, `margin at ${breite}x${hoehe}`);
        assert.ok(rahmen.x + rahmen.breite < breite && rahmen.y + rahmen.hoehe < hoehe, `inside at ${breite}x${hoehe}`);
        assert.ok(Math.abs(rahmen.breite / rahmen.hoehe - KAMERA_RAHMEN_VERHAELTNIS) < 0.02, `A4 at ${breite}x${hoehe}`);
        assert.ok(Math.abs(rahmen.x - (breite - rahmen.x - rahmen.breite)) <= 1, `centred at ${breite}x${hoehe}`);
    }
});

test('a portrait phone video is filled by width, a landscape one by height', () => {
    const hochkant = rahmenGeometrie(360, 800, 0.05);
    assert.equal(hochkant.x, 18);
    assert.equal(hochkant.breite, 324);

    const quer = rahmenGeometrie(800, 360, 0.05);
    assert.equal(quer.y, 18);
    assert.equal(quer.hoehe, 324);
});

test('a video without size yet gives an empty frame instead of negative numbers', () => {
    assert.deepEqual(rahmenGeometrie(0, 0), { x: 0, y: 0, breite: 0, hoehe: 0 });
});

test('a refused or missing camera points to the system camera', () => {
    assert.match(kameraFehlertext({ name: 'NotAllowedError' }), /nicht erlaubt.*Kamera-App verwenden/);
    assert.match(kameraFehlertext({ name: 'NotFoundError' }), /keine passende Kamera/);
    assert.match(kameraFehlertext({ name: 'NotReadableError' }), /anderen App/);
    assert.match(kameraFehlertext(new Error('irgendwas')), /nicht gestartet werden/);
    assert.match(kameraFehlertext(null), /nicht gestartet werden/);
});

test('a captured frame is the original: high quality, dated file name', () => {
    assert.ok(KAMERA_QUALITAET > 0.85);
    assert.equal(aufnahmeName(new Date(2026, 8, 7, 5, 4, 3)), 'kamera-20260907-050403.jpg');
});

// --- Live edge detection (issue #146) --------------------------------------

const {
    LIVE_INTERVALL_MS,
    LIVE_BREITE,
    LIVE_STABIL_ANZAHL,
    LIVE_STABIL_TOLERANZ,
    vorschauGroesse,
    naechsteErkennungIn,
    eckenAbweichung,
    liveStart,
    liveFortschreiben,
    liveEcken,
    startEckenAusLive,
    anzeigeAbbildung,
    overlayPunkte,
    liveHinweis,
} = require('../../public/js/scanner/kamera.js');
const { kantenErkennen } = require('../../public/js/scanner/kanten.js');

/** A 200 x 300 quad, moved by `dx`/`dy` pixels. */
function viereck(dx, dy) {
    const x = dx || 0;
    const y = dy || 0;

    return [
        { x: 60 + x, y: 50 + y },
        { x: 260 + x, y: 55 + y },
        { x: 255 + x, y: 360 + y },
        { x: 55 + x, y: 350 + y },
    ];
}

/** Runs a sequence of detections through liveFortschreiben on a 320 x 427 frame. */
function verlauf(folge) {
    return folge.reduce((zustand, ecken) => liveFortschreiben(zustand, ecken, 320, 427), liveStart());
}

test('the live defaults are what the issue asks for: ~400 ms, ~320 px, 3 detections within 2 %', () => {
    assert.ok(LIVE_INTERVALL_MS >= 300 && LIVE_INTERVALL_MS <= 500);
    assert.equal(LIVE_BREITE, 320);
    assert.equal(LIVE_STABIL_ANZAHL, 3);
    assert.equal(LIVE_STABIL_TOLERANZ, 0.02);
});

test('the detection frame is 320 px wide with the video aspect, never upscaled', () => {
    assert.deepEqual(vorschauGroesse(2560, 1920), { breite: 320, hoehe: 240, faktor: 8 });
    assert.deepEqual(vorschauGroesse(1920, 2560), { breite: 320, hoehe: 427, faktor: 6 });
    assert.deepEqual(vorschauGroesse(240, 180, 320), { breite: 240, hoehe: 180, faktor: 1 });
    assert.equal(vorschauGroesse(0, 0), null, 'no frame yet');
});

test('detection is throttled: at most one start per interval, and at least as much idle time as it took', () => {
    assert.equal(naechsteErkennungIn(0, 400), 400);
    assert.equal(naechsteErkennungIn(50, 400), 350, 'fast phone: one start every 400 ms');
    assert.equal(naechsteErkennungIn(200, 400), 200);
    assert.equal(naechsteErkennungIn(300, 400), 300, 'slow phone: the video keeps half the main thread');
    assert.equal(naechsteErkennungIn(900, 400), 900);
    assert.equal(naechsteErkennungIn(-5, 400), 400, 'a clock going backwards is no reason to hurry');
    assert.equal(naechsteErkennungIn(undefined), LIVE_INTERVALL_MS);

    for (const dauer of [0, 10, 120, 399, 400, 1200]) {
        const pause = naechsteErkennungIn(dauer, 400);
        assert.ok(dauer + pause >= 400, `start-to-start >= 400 ms at ${dauer} ms`);
        assert.ok(pause >= dauer, `idle >= busy at ${dauer} ms`);
    }
});

test('corner deviation is the largest corner move as a share of the frame diagonal', () => {
    assert.equal(eckenAbweichung(viereck(), viereck(), 320, 427), 0);
    const diagonale = Math.hypot(320, 427);
    assert.ok(Math.abs(eckenAbweichung(viereck(), viereck(3, 4), 320, 427) - 5 / diagonale) < 1e-12);
    assert.equal(eckenAbweichung(viereck(), null, 320, 427), Infinity);
    assert.equal(eckenAbweichung(viereck(), viereck(), 0, 0), Infinity);
});

test('three consecutive detections within 2 % make the quad stable', () => {
    // 2 % of the 533 px diagonal are about 10.7 px.
    assert.equal(verlauf([viereck()]).stabil, false, 'one is a glimpse');
    assert.equal(verlauf([viereck(), viereck(2, 1)]).stabil, false, 'two are not enough');
    assert.equal(verlauf([viereck(), viereck(2, 1), viereck(-3, 2)]).stabil, true);
    assert.equal(verlauf([viereck(), viereck(2, 1), viereck(-3, 2), viereck(-1, 0)]).stabil, true, 'and stays stable');
});

test('a miss or a moving phone resets stability', () => {
    assert.equal(verlauf([viereck(), viereck(), null]).stabil, false);
    assert.equal(verlauf([viereck(), viereck(), null, viereck()]).stabil, false, 'counting starts over after a miss');
    assert.equal(verlauf([viereck(), viereck(), null, viereck(), viereck(), viereck()]).stabil, true);
    assert.equal(verlauf([viereck(), viereck(), viereck(30, 0)]).stabil, false, 'a jump of 30 px');
    assert.deepEqual(verlauf([viereck(), null]), liveStart());
});

test('a slow drift is not standing still: every pair in the window must agree', () => {
    // Each step 8 px (< 2 %), the first to the last 16 px (> 2 %).
    assert.equal(verlauf([viereck(0, 0), viereck(8, 0), viereck(16, 0)]).stabil, false);
});

test('the stability state is pure and keeps only the last few quads', () => {
    const vorher = verlauf([viereck(), viereck()]);
    const kopie = JSON.parse(JSON.stringify(vorher));
    const eingabe = viereck(1, 1);
    const nachher = liveFortschreiben(vorher, eingabe, 320, 427);

    assert.deepEqual(vorher, kopie, 'old state unchanged');
    eingabe[0].x = 999;
    assert.equal(liveEcken(nachher)[0].x, 61, 'the caller\'s array is copied');
    assert.equal(verlauf([viereck(), viereck(), viereck(), viereck(), viereck()]).verlauf.length, LIVE_STABIL_ANZAHL);
    assert.equal(liveEcken(liveStart()), null);
    assert.equal(liveEcken(null), null);
});

test('start corners for the editor come only from a stable quad, scaled to video pixels and clamped', () => {
    assert.equal(startEckenAusLive(verlauf([viereck()]), 6, 1920, 2560), null, 'not stable: the editor detects itself');
    assert.equal(startEckenAusLive(liveStart(), 6, 1920, 2560), null);
    assert.equal(startEckenAusLive(null, 6, 1920, 2560), null);

    const stabil = verlauf([viereck(), viereck(), viereck()]);
    assert.deepEqual(startEckenAusLive(stabil, 6, 1920, 2560), [
        { x: 360, y: 300 }, { x: 1560, y: 330 }, { x: 1530, y: 2160 }, { x: 330, y: 2100 },
    ]);

    const amRand = verlauf([[{ x: -2, y: -1 }, { x: 330, y: 0 }, { x: 321, y: 430 }, { x: 0, y: 427 }]].concat(
        [[{ x: -2, y: -1 }, { x: 330, y: 0 }, { x: 321, y: 430 }, { x: 0, y: 427 }]],
        [[{ x: -2, y: -1 }, { x: 330, y: 0 }, { x: 321, y: 430 }, { x: 0, y: 427 }]],
    ));
    for (const p of startEckenAusLive(amRand, 6, 1920, 2560)) {
        assert.ok(p.x >= 0 && p.x <= 1920 && p.y >= 0 && p.y <= 2560);
    }
});

test('the overlay follows object-fit: contain - letterbox offsets and one scale', () => {
    // A portrait 1920x2560 video in a 360x640 element: 360 wide, 480 high, 80 px bars top and bottom.
    const abbildung = anzeigeAbbildung(1920, 2560, 360, 640);
    assert.ok(Math.abs(abbildung.skala - 0.1875) < 1e-12);
    assert.equal(abbildung.x, 0);
    assert.equal(abbildung.y, 80);

    // A landscape video in a portrait element of the same height: bars left and right.
    const quer = anzeigeAbbildung(2560, 1920, 800, 400);
    assert.equal(quer.y, 0);
    assert.ok(quer.x > 0);

    assert.equal(anzeigeAbbildung(0, 0, 360, 640), null, 'no video size yet');
    assert.equal(anzeigeAbbildung(1920, 2560, 0, 0), null, 'dialog not laid out');
});

test('overlay points map the small frame through the video into the element', () => {
    const abbildung = anzeigeAbbildung(1920, 2560, 360, 640);
    const punkte = overlayPunkte([{ x: 0, y: 0 }, { x: 320, y: 0 }, { x: 320, y: 427 }, { x: 0, y: 427 }], 6, abbildung);

    assert.equal(punkte, '0.0,80.0 360.0,80.0 360.0,560.4 0.0,560.4');
    assert.equal(overlayPunkte(null, 6, abbildung), '', 'nothing found: nothing drawn');
    assert.equal(overlayPunkte(viereck(), 6, null), '');
});

test('the status line says "Beleg erkannt" only for a stable quad, and announces an automatic capture', () => {
    assert.equal(liveHinweis(false, false), '');
    assert.equal(liveHinweis(false, true), '');
    assert.equal(liveHinweis(true, false), 'Beleg erkannt.');
    assert.match(liveHinweis(true, true), /Beleg erkannt.*aufgenommen/);
});

/** mulberry32 - deterministic noise for the synthetic camera frames. */
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

/**
 * A 320 x 427 live frame (the size vorschauGroesse gives a portrait phone
 * video): a bright, slightly skewed sheet on a noisy dark table, shifted by
 * `dx`, with fresh sensor noise per frame.
 */
function liveFrame(saat, dx) {
    const breite = 320;
    const hoehe = 427;
    const zufall = pseudorng(saat);
    const ecken = [{ x: 50 + dx, y: 40 }, { x: 266 + dx, y: 52 }, { x: 258 + dx, y: 392 }, { x: 40 + dx, y: 378 }];
    const data = new Uint8ClampedArray(breite * hoehe * 4);

    function innen(x, y) {
        for (let i = 0; i < 4; i++) {
            const a = ecken[i];
            const b = ecken[(i + 1) % 4];
            if ((b.x - a.x) * (y - a.y) - (b.y - a.y) * (x - a.x) < 0) {
                return false;
            }
        }

        return true;
    }

    for (let y = 0; y < hoehe; y++) {
        for (let x = 0; x < breite; x++) {
            const wert = (innen(x, y) ? 228 : 62) + Math.round((zufall() - 0.5) * 16);
            const i = (y * breite + x) * 4;
            data[i] = wert;
            data[i + 1] = wert;
            data[i + 2] = wert;
            data[i + 3] = 255;
        }
    }

    return { bild: { width: breite, height: hoehe, data: data }, ecken: ecken };
}

test('kantenErkennen on consecutive small live frames of a held-still receipt becomes stable', () => {
    let zustand = liveStart();
    for (const [saat, dx] of [[1, 0], [2, 1], [3, -1]]) {
        const { bild, ecken } = liveFrame(saat, dx);
        const gefunden = kantenErkennen(bild);

        assert.notEqual(gefunden, null, `frame ${saat}: sheet found`);
        assert.ok(eckenAbweichung(gefunden, ecken, bild.width, bild.height) <= 0.03, `frame ${saat}: corners within 3 %`);
        zustand = liveFortschreiben(zustand, gefunden, bild.width, bild.height);
    }
    assert.equal(zustand.stabil, true);

    // The phone moves: the sheet jumps 40 px - no longer stable.
    const { bild } = liveFrame(4, 40);
    zustand = liveFortschreiben(zustand, kantenErkennen(bild), bild.width, bild.height);
    assert.equal(zustand.stabil, false);
});
