// The browser file is loaded directly: `document` does not exist in Node, so
// nothing wires itself up and the network is never touched - dateiHochladen()
// takes its fetch as a parameter.

const test = require('node:test');
const assert = require('node:assert/strict');
const {
    chunkAnzahl,
    chunkGrenzen,
    fortschrittProzent,
    fehlertext,
    dateiHochladen,
} = require('../../public/js/upload.js');

/** A File stand-in: size, name and slice() are all the module uses. */
function datei(groesse, name) {
    return {
        size: groesse,
        name: name || 'beleg.pdf',
        slice: (start, ende) => 'bytes:' + start + '-' + ende,
    };
}

/**
 * A fetch that records every call and answers from a queue. An entry may be
 * an answer body or { status, body } for a failure.
 */
function fakeFetch(antworten) {
    const aufrufe = [];
    const holen = async (pfad, optionen) => {
        aufrufe.push({ pfad: pfad, optionen: optionen });
        const naechste = antworten.shift() || {};
        const status = naechste.status || 200;

        return {
            ok: status >= 200 && status < 300,
            status: status,
            json: async () => (naechste.body === undefined ? naechste : naechste.body),
        };
    };

    return { holen: holen, aufrufe: aufrufe };
}

test('a file that is not a multiple of the chunk size gets a shorter last chunk', () => {
    assert.equal(chunkAnzahl(5, 2), 3);
    assert.deepEqual(chunkGrenzen(5, 2), [
        { index: 0, start: 0, ende: 2 },
        { index: 1, start: 2, ende: 4 },
        { index: 2, start: 4, ende: 5 },
    ]);
});

test('a file that fits the chunk size exactly gets no empty last chunk', () => {
    assert.equal(chunkAnzahl(4, 2), 2);
    assert.deepEqual(chunkGrenzen(4, 2), [
        { index: 0, start: 0, ende: 2 },
        { index: 1, start: 2, ende: 4 },
    ]);
});

test('a single byte is one chunk, an empty file is none', () => {
    assert.deepEqual(chunkGrenzen(1, 2), [{ index: 0, start: 0, ende: 1 }]);
    assert.deepEqual(chunkGrenzen(0, 2), []);
});

test('progress never rounds up to 100 before the last byte is through', () => {
    assert.equal(fortschrittProzent(0, 10), 0);
    assert.equal(fortschrittProzent(9.999, 10), 99, 'never rounds up to 100 before it is done');
    assert.equal(fortschrittProzent(10, 10), 100);
    assert.equal(fortschrittProzent(1, 0), 100, 'an empty file is done at once');
});

test('the servers sentence wins over the status code', () => {
    assert.equal(fehlertext({ status: 415 }, { fehler: 'Nur JPEG, PNG und PDF sind möglich.' }),
        'Nur JPEG, PNG und PDF sind möglich.');
    assert.equal(fehlertext({ status: 500 }, {}), 'HTTP 500');
});

test('an upload opens, sends its chunks in order and closes', async () => {
    const fake = fakeFetch([
        { id: 'a'.repeat(32), chunks: 3, chunk_bytes: 2 },
        { chunk: 0 },
        { chunk: 1 },
        { chunk: 2 },
        { blob_id: 7, groesse: 5, typ: 'application/pdf' },
    ]);
    const fortschritt = [];

    const ergebnis = await dateiHochladen(datei(5), {
        csrf: 'geheim',
        fetch: fake.holen,
        onFortschritt: (prozent) => fortschritt.push(prozent),
    });

    assert.deepEqual(fake.aufrufe.map((a) => a.pfad), [
        '/api/upload',
        '/api/upload/' + 'a'.repeat(32) + '/chunk/0',
        '/api/upload/' + 'a'.repeat(32) + '/chunk/1',
        '/api/upload/' + 'a'.repeat(32) + '/chunk/2',
        '/api/upload/' + 'a'.repeat(32) + '/finish',
    ]);
    assert.deepEqual(fortschritt, [40, 80, 100]);
    assert.equal(ergebnis.blob_id, 7);
});

test('every request carries the CSRF token', async () => {
    const fake = fakeFetch([
        { id: 'b'.repeat(32), chunks: 1, chunk_bytes: 2 },
        { chunk: 0 },
        { blob_id: 1 },
    ]);

    await dateiHochladen(datei(1), { csrf: 'geheim', fetch: fake.holen });

    for (const aufruf of fake.aufrufe) {
        assert.equal(aufruf.optionen.headers['X-CSRF-Token'], 'geheim');
        assert.equal(aufruf.optionen.method, 'POST');
    }
});

test('the chunk body is the slice of the file that belongs to that index', async () => {
    const fake = fakeFetch([
        { id: 'c'.repeat(32), chunks: 2, chunk_bytes: 2 },
        { chunk: 0 },
        { chunk: 1 },
        { blob_id: 1 },
    ]);

    await dateiHochladen(datei(3), { csrf: 'x', fetch: fake.holen });

    assert.equal(fake.aufrufe[1].optionen.body, 'bytes:0-2');
    assert.equal(fake.aufrufe[2].optionen.body, 'bytes:2-3');
});

test('the server chunk size wins over the one compiled in here', async () => {
    const fake = fakeFetch([
        { id: 'd'.repeat(32), chunks: 1, chunk_bytes: 8 },
        { chunk: 0 },
        { blob_id: 1 },
    ]);

    await dateiHochladen(datei(6), { csrf: 'x', fetch: fake.holen });

    assert.equal(fake.aufrufe.length, 3, 'six bytes are one chunk of eight, not three of two');
});

test('a refused file reports the servers reason and gives the chunks back', async () => {
    const fake = fakeFetch([
        { id: 'e'.repeat(32), chunks: 1, chunk_bytes: 2 },
        { chunk: 0 },
        { status: 415, body: { fehler: 'Nur JPEG, PNG und PDF sind möglich.' } },
        { status: 'geloescht' },
    ]);

    await assert.rejects(
        dateiHochladen(datei(2), { csrf: 'x', fetch: fake.holen }),
        /Nur JPEG, PNG und PDF sind möglich\./,
    );
    assert.equal(fake.aufrufe[3].pfad, '/api/upload/' + 'e'.repeat(32) + '/abort');
});

test('a failing abort does not hide the error that caused it', async () => {
    const fake = fakeFetch([
        { id: 'f'.repeat(32), chunks: 1, chunk_bytes: 2 },
        { status: 413, body: { fehler: 'Der Abschnitt ist zu groß.' } },
        { status: 500, body: {} },
    ]);

    await assert.rejects(
        dateiHochladen(datei(2), { csrf: 'x', fetch: fake.holen }),
        /Der Abschnitt ist zu groß\./,
    );
});

test('a basis option routes every request under a different prefix (issue #24/M4-2)', async () => {
    const fake = fakeFetch([
        { id: 'g'.repeat(32), chunks: 1, chunk_bytes: 2 },
        { chunk: 0 },
        { blob_id: 9 },
    ]);

    await dateiHochladen(datei(1), { csrf: 'x', fetch: fake.holen, basis: '/einreichen/upload' });

    assert.deepEqual(fake.aufrufe.map((a) => a.pfad), [
        '/einreichen/upload',
        '/einreichen/upload/' + 'g'.repeat(32) + '/chunk/0',
        '/einreichen/upload/' + 'g'.repeat(32) + '/finish',
    ]);
});

test('without a basis option, the internal route is still the default', async () => {
    const fake = fakeFetch([
        { id: 'h'.repeat(32), chunks: 1, chunk_bytes: 2 },
        { chunk: 0 },
        { blob_id: 9 },
    ]);

    await dateiHochladen(datei(1), { csrf: 'x', fetch: fake.holen });

    assert.equal(fake.aufrufe[0].pfad, '/api/upload');
});

test('an error answer with status 200 is still an error', async () => {
    const fake = fakeFetch([
        { fehler: 'Die Datei ist zu groß.' },
    ]);

    await assert.rejects(
        dateiHochladen(datei(99), { csrf: 'x', fetch: fake.holen }),
        /Die Datei ist zu groß\./,
    );
});

test('extra headers (issue #25/M4-3, the proof-of-work solution) go out on every request', async () => {
    const fake = fakeFetch([
        { id: 'i'.repeat(32), chunks: 1, chunk_bytes: 2 },
        { chunk: 0 },
        { blob_id: 1 },
    ]);

    await dateiHochladen(datei(1), { csrf: 'x', fetch: fake.holen, headers: { 'X-Pow-Loesung': '4711' } });

    for (const aufruf of fake.aufrufe) {
        assert.equal(aufruf.optionen.headers['X-Pow-Loesung'], '4711');
        assert.equal(aufruf.optionen.headers['X-CSRF-Token'], 'x', 'the CSRF header is still there too');
    }
});
