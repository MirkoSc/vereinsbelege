// The scanner glue of the capture pages (issue #34/M5-4,
// public/js/scanner/scanner.js): what it decides without a DOM - whether a
// browser can process at all, which files are processed, the starting
// corners, and the order and outcome of the two uploads of a page. The DOM
// part (editor dialog, canvas encoding) is checked by hand, see the PR.

const test = require('node:test');
const assert = require('node:assert/strict');
const {
    SCAN_QUALITAET,
    HEIC_HINWEIS,
    kannScannen,
    istScanbar,
    istHeicDatei,
    heicJpegName,
    aufbereitetName,
    startEcken,
    seiteVerarbeiten,
} = require('../../public/js/scanner/scanner.js');
const { standardRahmen, ganzesBild } = require('../../public/js/scanner/eckeditor.js');

function browser(ueberschreiben) {
    function Canvas() {}
    Canvas.prototype.toBlob = function () {};
    function Dialog() {}
    Dialog.prototype.showModal = function () {};

    return Object.assign({
        createImageBitmap: function () {},
        ImageData: function () {},
        HTMLCanvasElement: Canvas,
        HTMLDialogElement: Dialog,
    }, ueberschreiben || {});
}

test('a browser with bitmaps, canvas.toBlob and <dialog> can process', () => {
    assert.equal(kannScannen(browser()), true);
});

test('a browser missing any one piece leaves the processing to the server', () => {
    assert.equal(kannScannen(null), false);
    assert.equal(kannScannen(browser({ createImageBitmap: undefined })), false);
    assert.equal(kannScannen(browser({ ImageData: undefined })), false);
    assert.equal(kannScannen(browser({ HTMLDialogElement: undefined })), false);

    function OhneToBlob() {}
    assert.equal(kannScannen(browser({ HTMLCanvasElement: OhneToBlob })), false);
});

test('only JPEG and PNG are processed, never a PDF', () => {
    assert.equal(istScanbar({ type: 'image/jpeg', name: 'a.jpg' }), true);
    assert.equal(istScanbar({ type: 'image/png', name: 'a.png' }), true);
    assert.equal(istScanbar({ type: 'application/pdf', name: 'a.pdf' }), false);
    assert.equal(istScanbar({ type: 'image/heic', name: 'a.heic' }), false);
    assert.equal(istScanbar({ type: '', name: 'IMG_1.JPEG' }), true, 'no MIME type: the extension decides');
    assert.equal(istScanbar({ type: '', name: 'scan.pdf' }), false);
    assert.equal(istScanbar(null), false);
});

test('the processed file is named after the original, always .jpg', () => {
    assert.equal(aufbereitetName('IMG_0001.png'), 'IMG_0001-aufbereitet.jpg');
    assert.equal(aufbereitetName('beleg.foto.jpeg'), 'beleg.foto-aufbereitet.jpg');
    assert.equal(aufbereitetName(''), 'seite-aufbereitet.jpg');
    assert.equal(aufbereitetName(undefined), 'seite-aufbereitet.jpg');
});

test('the scan is encoded at the quality the spec names', () => {
    assert.equal(SCAN_QUALITAET, 0.85);
});

test('detected corners win; without, interactive gets the inset frame, automatic the whole image', () => {
    const gefunden = [{ x: 1, y: 2 }, { x: 90, y: 3 }, { x: 88, y: 70 }, { x: 2, y: 69 }];

    assert.equal(startEcken(gefunden, 100, 80, true), gefunden);
    assert.equal(startEcken(gefunden, 100, 80, false), gefunden);
    assert.deepEqual(startEcken(null, 100, 80, true), standardRahmen(100, 80));
    assert.deepEqual(startEcken(null, 100, 80, false), ganzesBild(100, 80));
});

function hochlader() {
    const reihenfolge = [];
    let naechste = 10;

    return {
        reihenfolge: reihenfolge,
        hochladen: async function (datei) {
            reihenfolge.push(datei.name);

            return { blob_id: naechste++ };
        },
    };
}

test('a processed image uploads the original first, then the processed version', async () => {
    const upload = hochlader();
    const scan = { name: 'foto-aufbereitet.jpg', type: 'image/jpeg' };

    const ergebnis = await seiteVerarbeiten({ name: 'foto.jpg', type: 'image/jpeg' }, {
        kann: true,
        aufbereiten: async () => scan,
        hochladen: upload.hochladen,
    });

    assert.deepEqual(upload.reihenfolge, ['foto.jpg', 'foto-aufbereitet.jpg']);
    assert.deepEqual(ergebnis, { abgebrochen: false, blobId: 10, aufbereitetId: 11, aufbereitet: scan, serverFallback: false, scanDatei: null });
});

test('cancelling the editor uploads nothing at all', async () => {
    const upload = hochlader();

    const ergebnis = await seiteVerarbeiten({ name: 'foto.jpg', type: 'image/jpeg' }, {
        kann: true,
        aufbereiten: async () => null,
        hochladen: upload.hochladen,
    });

    assert.deepEqual(ergebnis, { abgebrochen: true });
    assert.deepEqual(upload.reihenfolge, []);
});

test('a browser that cannot process uploads the original only and marks the server fallback', async () => {
    const upload = hochlader();
    let gefragt = false;

    const ergebnis = await seiteVerarbeiten({ name: 'foto.png', type: 'image/png' }, {
        kann: false,
        aufbereiten: async () => {
            gefragt = true;

            return null;
        },
        hochladen: upload.hochladen,
    });

    assert.equal(gefragt, false);
    assert.deepEqual(upload.reihenfolge, ['foto.png']);
    assert.deepEqual(ergebnis, { abgebrochen: false, blobId: 10, aufbereitetId: null, aufbereitet: null, serverFallback: true, scanDatei: null });
});

test('processing that fails on one image still uploads its original for the server fallback', async () => {
    const upload = hochlader();

    const ergebnis = await seiteVerarbeiten({ name: 'riesig.jpg', type: 'image/jpeg' }, {
        kann: true,
        aufbereiten: async () => {
            throw new Error('out of memory');
        },
        hochladen: upload.hochladen,
    });

    assert.deepEqual(upload.reihenfolge, ['riesig.jpg']);
    assert.equal(ergebnis.aufbereitetId, null);
    assert.equal(ergebnis.serverFallback, true);
});

test('a PDF is uploaded as it is, without processing and without a server fallback', async () => {
    const upload = hochlader();

    const ergebnis = await seiteVerarbeiten({ name: 'rechnung.pdf', type: 'application/pdf' }, {
        kann: true,
        aufbereiten: async () => {
            throw new Error('darf nicht aufgerufen werden');
        },
        hochladen: upload.hochladen,
    });

    assert.deepEqual(upload.reihenfolge, ['rechnung.pdf']);
    assert.deepEqual(ergebnis, { abgebrochen: false, blobId: 10, aufbereitetId: null, aufbereitet: null, serverFallback: false, scanDatei: null });
});

test('a failed upload rejects, so the page shows the error', async () => {
    await assert.rejects(
        seiteVerarbeiten({ name: 'foto.jpg', type: 'image/jpeg' }, {
            kann: true,
            aufbereiten: async () => ({ name: 'scan.jpg' }),
            hochladen: async () => {
                throw new Error('Die Verbindung ist abgebrochen.');
            },
        }),
        /Verbindung/,
    );
});

test('HEIC is recognised by MIME type or extension', () => {
    assert.equal(istHeicDatei({ type: 'image/heic', name: 'a.heic' }), true);
    assert.equal(istHeicDatei({ type: 'image/heif', name: 'a.heif' }), true);
    assert.equal(istHeicDatei({ type: '', name: 'IMG_0001.HEIC' }), true);
    assert.equal(istHeicDatei({ type: 'image/jpeg', name: 'a.jpg' }), false);
    assert.equal(istHeicDatei(null), false);
});

test('the converted HEIC gets a .jpg name', () => {
    assert.equal(heicJpegName('IMG_0001.HEIC'), 'IMG_0001.jpg');
    assert.equal(heicJpegName('a.b.heif'), 'a.b.jpg');
    assert.equal(heicJpegName(''), 'foto.jpg');
});

function heicOptionen(ueberschreiben) {
    const aufrufe = [];

    return Object.assign({
        aufrufe: aufrufe,
        kann: true,
        heicWandeln: async function (datei) {
            return { name: 'foto.jpg', type: 'image/jpeg', quelle: datei.name };
        },
        aufbereiten: async function (bild) {
            aufrufe.push('aufbereiten:' + bild.name);

            return { name: 'foto-aufbereitet.jpg', type: 'image/jpeg' };
        },
        hochladen: async function (teil) {
            aufrufe.push('hochladen:' + teil.name);

            return { blob_id: 'blob-' + aufrufe.length };
        },
    }, ueberschreiben || {});
}

test('a HEIC photo is converted, scanned, and uploaded as original plus processed JPEG', async () => {
    const optionen = heicOptionen();
    const ergebnis = await seiteVerarbeiten({ name: 'foto.heic', type: 'image/heic' }, optionen);

    assert.deepEqual(optionen.aufrufe, ['aufbereiten:foto.jpg', 'hochladen:foto.heic', 'hochladen:foto-aufbereitet.jpg']);
    assert.equal(ergebnis.serverFallback, false);
    assert.equal(ergebnis.scanDatei.name, 'foto.jpg');
    assert.ok(ergebnis.aufbereitetId);
});

test('a converted HEIC is the processed version when the scanner cannot run', async () => {
    const optionen = heicOptionen({ kann: false });
    const ergebnis = await seiteVerarbeiten({ name: 'foto.heic', type: 'image/heic' }, optionen);

    assert.deepEqual(optionen.aufrufe, ['hochladen:foto.heic', 'hochladen:foto.jpg']);
    assert.equal(ergebnis.aufbereitet.name, 'foto.jpg');
    assert.equal(ergebnis.serverFallback, false);
});

test('a converted HEIC is the processed version when the scanner fails', async () => {
    const optionen = heicOptionen({ aufbereiten: async function () { throw new Error('kaputt'); } });
    const ergebnis = await seiteVerarbeiten({ name: 'foto.heic', type: 'image/heic' }, optionen);

    assert.deepEqual(optionen.aufrufe, ['hochladen:foto.heic', 'hochladen:foto.jpg']);
    assert.equal(ergebnis.scanDatei.name, 'foto.jpg');
});

test('a cancelled editor uploads nothing for a HEIC photo either', async () => {
    const optionen = heicOptionen({ aufbereiten: async function () { return null; } });
    const ergebnis = await seiteVerarbeiten({ name: 'foto.heic', type: 'image/heic' }, optionen);

    assert.equal(ergebnis.abgebrochen, true);
    assert.deepEqual(optionen.aufrufe, []);
});

test('a browser that cannot decode HEIC refuses the page before any upload', async () => {
    for (const wandeln of [undefined, async function () { throw new Error('nicht lesbar'); }]) {
        const optionen = heicOptionen({ heicWandeln: wandeln });

        await assert.rejects(
            seiteVerarbeiten({ name: 'foto.heic', type: 'image/heic' }, optionen),
            function (fehler) {
                return fehler.heic === true && fehler.message === HEIC_HINWEIS;
            },
        );
        assert.deepEqual(optionen.aufrufe, [], 'nothing is uploaded');
    }
});
