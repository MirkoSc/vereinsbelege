// The browser file is loaded directly: `document` does not exist in Node, so
// nothing wires itself up (initRasterung() guards behind `typeof document`),
// pdf.js and the canvas never enter the picture - verarbeiteJob() takes a
// `ladeQuelle` in their place, and fetch is injected like public/js/
// upload.js's dateiHochladen().

const test = require('node:test');
const assert = require('node:assert/strict');
const {
    massstab,
    wartezeitRaster,
    verarbeiteJob,
    naechsteAufgabe,
    verarbeite,
    QUALITAETSSTUFEN,
    MAX_BYTES,
} = require('../../public/js/rasterung.js');

/**
 * A fetch that records every call and answers from a queue. An entry is the
 * JSON body directly (200 OK - every one of this API's own bodies carries
 * its own `status` field, so unlike tests/js/upload.test.js's fakeFetch that
 * name is reserved for { httpStatus, body } below, a non-2xx response), or
 * an Error to reject with (a network failure).
 */
function fakeFetch(antworten) {
    const aufrufe = [];
    const holen = async (pfad, optionen) => {
        aufrufe.push({ pfad: pfad, optionen: optionen });
        const naechste = antworten.shift() || {};
        if (naechste instanceof Error) {
            throw naechste;
        }
        const httpStatus = naechste.httpStatus || 200;

        return {
            ok: httpStatus >= 200 && httpStatus < 300,
            status: httpStatus,
            json: async () => (naechste.body === undefined ? naechste : naechste.body),
        };
    };

    return { holen: holen, aufrufe: aufrufe };
}

/** A rendered byte string stand-in: only .byteLength is read. */
function seite(bytes) {
    return { byteLength: bytes };
}

/** A pdf.js document stand-in: numPages plus a page renderer keyed by page number. */
function fakeDokument(numPages, groessen) {
    return {
        numPages: numPages,
        seite: async (nummer) => seite((groessen && groessen[nummer]) || 100),
    };
}

test('massstab targets 150 DPI and caps the longest side at 2000 px', () => {
    // A4 at 72 dpi (595 x 842 pt): well under the cap either way.
    assert.equal(Math.round(massstab(595, 842) * 100) / 100, Math.round((150 / 72) * 100) / 100);
    // A very long receipt: the long side would blow past 2000 px at 150 DPI,
    // so the scale shrinks to fit it exactly.
    assert.equal(massstab(200, 4000) * 4000, 2000);
    assert.equal(massstab(4000, 200) * 4000, 2000);
});

test('massstab falls back to 150 DPI for a missing or zero size', () => {
    assert.equal(massstab(0, 0), 150 / 72);
    assert.equal(massstab(-1, 100), 150 / 72);
});

test('quality steps run from best to worst, and the byte budget matches the server', () => {
    assert.deepEqual(QUALITAETSSTUFEN, [0.85, 0.7, 0.5]);
    assert.equal(MAX_BYTES, 2 * 1024 * 1024);
});

// docs/spec/06-betrieb.md section 4's table, adapted for a job whose steps
// take real time to render (public/js/rasterung.js's own docblock).
test('leer backs off gently, faster while the tab is visible', () => {
    assert.equal(wartezeitRaster('leer', true), 15000);
    assert.equal(wartezeitRaster('leer', false), 60000);
});

test('a locked vault or a login problem stops the loop', () => {
    assert.equal(wartezeitRaster('gesperrt', true), null);
    assert.equal(wartezeitRaster('anmeldung', false), null);
});

test('a network or server error backs off further than an empty poll', () => {
    assert.equal(wartezeitRaster('fehler', true), 30000);
    assert.equal(wartezeitRaster('fehler', false), 120000);
});

test('anything else (a page stored, a job finished, a stale task) asks again almost at once', () => {
    assert.equal(wartezeitRaster('ok', true), 250);
    assert.equal(wartezeitRaster('fertig', false), 30000);
    assert.equal(wartezeitRaster('unerwartet', true), 250);
});

test('naechsteAufgabe maps 401/403 to anmeldung and a bad response to fehler', async () => {
    const fake = fakeFetch([{ httpStatus: 403 }]);
    assert.deepEqual(await naechsteAufgabe({ csrf: 'x', fetch: fake.holen }), { status: 'anmeldung' });

    const fake2 = fakeFetch([{ httpStatus: 500 }]);
    assert.deepEqual(await naechsteAufgabe({ csrf: 'x', fetch: fake2.holen }), { status: 'fehler' });

    const fake3 = fakeFetch([new Error('Netzwerk weg')]);
    assert.deepEqual(await naechsteAufgabe({ csrf: 'x', fetch: fake3.holen }), { status: 'fehler' });
});

test('naechsteAufgabe carries the CSRF token and an empty body', async () => {
    const fake = fakeFetch([{ status: 'leer', offen: 0 }]);

    await naechsteAufgabe({ csrf: 'geheim', fetch: fake.holen });

    assert.equal(fake.aufrufe[0].pfad, '/api/rasterung/naechste');
    assert.equal(fake.aufrufe[0].optionen.headers['X-CSRF-Token'], 'geheim');
    assert.equal(fake.aufrufe[0].optionen.body, '');
});

test('one source, two pages: both are uploaded in order and the job finishes', async () => {
    const fake = fakeFetch([{ status: 'ok', quelle: 0, seite: 2 }, { status: 'fertig' }]);
    const aufgabe = { job: 5, lock: 'abc', quelle: 0, seite: 1 };
    const dokument = fakeDokument(2);

    const ergebnis = await verarbeiteJob(aufgabe, {
        csrf: 'geheim',
        fetch: fake.holen,
        ladeQuelle: async () => dokument,
    });

    assert.deepEqual(ergebnis, { status: 'fertig' });
    assert.deepEqual(fake.aufrufe.map((a) => a.pfad), [
        '/api/rasterung/5/abc/seite/0/1/2',
        '/api/rasterung/5/abc/seite/0/2/2',
    ]);
});

test('a second source is fetched only once the server names it, with the same job and lock', async () => {
    const fake = fakeFetch([{ status: 'ok', quelle: 1, seite: 1 }, { status: 'fertig' }]);
    const aufgabe = { job: 9, lock: 'lock-a', quelle: 0, seite: 1 };
    const geladen = [];
    const ladeQuelle = async (job, lock, quelleIndex) => {
        geladen.push([job, lock, quelleIndex]);

        return fakeDokument(1);
    };

    const ergebnis = await verarbeiteJob(aufgabe, { csrf: 'x', fetch: fake.holen, ladeQuelle: ladeQuelle });

    assert.deepEqual(ergebnis, { status: 'fertig' });
    assert.deepEqual(geladen, [[9, 'lock-a', 0], [9, 'lock-a', 1]]);
    assert.deepEqual(fake.aufrufe.map((a) => a.pfad), [
        '/api/rasterung/9/lock-a/seite/0/1/1',
        '/api/rasterung/9/lock-a/seite/1/1/1',
    ]);
});

test('a stale task stops the job without a further request', async () => {
    const fake = fakeFetch([{ status: 'unerwartet' }]);
    const aufgabe = { job: 1, lock: 'x', quelle: 0, seite: 1 };

    const ergebnis = await verarbeiteJob(aufgabe, {
        csrf: 'x',
        fetch: fake.holen,
        ladeQuelle: async () => fakeDokument(1),
    });

    assert.deepEqual(ergebnis, { status: 'unerwartet' });
    assert.equal(fake.aufrufe.length, 1, 'no page after a non-ok answer');
});

test('a page too large even at the lowest quality gives up on the whole job', async () => {
    const fake = fakeFetch([]);
    const aufgabe = { job: 3, lock: 'x', quelle: 0, seite: 1 };
    // Every quality attempt is oversized.
    const riesig = { numPages: 1, seite: async () => seite(MAX_BYTES + 1) };

    const ergebnis = await verarbeiteJob(aufgabe, { csrf: 'geheim', fetch: fake.holen, ladeQuelle: async () => riesig });

    assert.deepEqual(ergebnis, { status: 'defekt' });
    assert.equal(fake.aufrufe.length, 1, 'only the abbruch call, no page upload');
    assert.equal(fake.aufrufe[0].pfad, '/api/rasterung/3/x/abbruch');
    assert.deepEqual(JSON.parse(fake.aufrufe[0].optionen.body), { grund: 'defekt' });
});

test('a page that fits only at a lower quality still uploads once', async () => {
    const fake = fakeFetch([{ status: 'fertig' }]);
    const aufgabe = { job: 4, lock: 'x', quelle: 0, seite: 1 };
    let versuche = 0;
    const dokument = {
        numPages: 1,
        seite: async () => {
            versuche++;

            // Fits only on the third (lowest) quality attempt.
            return seite(versuche < 3 ? MAX_BYTES + 1 : 100);
        },
    };

    const ergebnis = await verarbeiteJob(aufgabe, { csrf: 'x', fetch: fake.holen, ladeQuelle: async () => dokument });

    assert.deepEqual(ergebnis, { status: 'fertig' });
    assert.equal(versuche, 3);
    assert.equal(fake.aufrufe.length, 1, 'one upload, not one per quality attempt');
});

test('pdf.js refusing a password-protected file aborts with grund passwort', async () => {
    const fake = fakeFetch([]);
    const aufgabe = { job: 2, lock: 'x', quelle: 0, seite: 1 };
    const fehler = new Error('kaputt');
    fehler.name = 'PasswordException';

    const ergebnis = await verarbeiteJob(aufgabe, {
        csrf: 'x',
        fetch: fake.holen,
        ladeQuelle: async () => {
            throw fehler;
        },
    });

    assert.deepEqual(ergebnis, { status: 'passwort' });
    assert.deepEqual(JSON.parse(fake.aufrufe[0].optionen.body), { grund: 'passwort' });
});

test('any other failure to open the source aborts with grund defekt', async () => {
    const fake = fakeFetch([]);
    const aufgabe = { job: 2, lock: 'x', quelle: 0, seite: 1 };

    const ergebnis = await verarbeiteJob(aufgabe, {
        csrf: 'x',
        fetch: fake.holen,
        ladeQuelle: async () => {
            throw new Error('kaputte Datei');
        },
    });

    assert.deepEqual(ergebnis, { status: 'defekt' });
});

test('verarbeite claims a task and drives it, without touching the network again for leer', async () => {
    const fake = fakeFetch([{ status: 'leer', offen: 0 }]);

    const ergebnis = await verarbeite({ csrf: 'x', fetch: fake.holen, ladeQuelle: async () => fakeDokument(1) });

    assert.deepEqual(ergebnis, { status: 'leer', offen: 0 });
    assert.equal(fake.aufrufe.length, 1);
});

test('verarbeite drives a claimed task to done', async () => {
    const fake = fakeFetch([
        { status: 'aufgabe', job: 1, lock: 'l', quelle: 0, seite: 1, quellen: 1 },
        { status: 'fertig' },
    ]);

    const ergebnis = await verarbeite({ csrf: 'x', fetch: fake.holen, ladeQuelle: async () => fakeDokument(1) });

    assert.deepEqual(ergebnis, { status: 'fertig' });
    assert.deepEqual(fake.aufrufe.map((a) => a.pfad), [
        '/api/rasterung/naechste',
        '/api/rasterung/1/l/seite/0/1/1',
    ]);
});
