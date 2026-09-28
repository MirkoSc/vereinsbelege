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
