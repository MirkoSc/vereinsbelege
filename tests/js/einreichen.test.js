// The browser file is loaded directly: `document` does not exist in Node, so
// initEinreichen() never runs and the DOM-wiring half of the file is
// untouched - only the pure helpers and the two async functions (which take
// their collaborators as parameters) are tested here.

const test = require('node:test');
const assert = require('node:assert/strict');
const {
    seitenVerschieben,
    seitenEntfernen,
    istHeic,
    einreichenNutzlast,
    seiteHochladen,
    absenden,
} = require('../../public/js/einreichen.js');

test('moving a page shifts it between its neighbours', () => {
    const seiten = [{ id: 'a' }, { id: 'b' }, { id: 'c' }];

    assert.deepEqual(seitenVerschieben(seiten, 0, 2), [{ id: 'b' }, { id: 'c' }, { id: 'a' }]);
    assert.deepEqual(seitenVerschieben(seiten, 2, 0), [{ id: 'c' }, { id: 'a' }, { id: 'b' }]);
});

test('moving does not touch the array it was given', () => {
    const seiten = [{ id: 'a' }, { id: 'b' }];
    seitenVerschieben(seiten, 0, 1);

    assert.deepEqual(seiten, [{ id: 'a' }, { id: 'b' }]);
});

test('an out-of-range index leaves the order unchanged', () => {
    const seiten = [{ id: 'a' }, { id: 'b' }];

    assert.deepEqual(seitenVerschieben(seiten, 0, 5), seiten);
    assert.deepEqual(seitenVerschieben(seiten, -1, 0), seiten);
});

test('removing a page keeps the others in order', () => {
    const seiten = [{ id: 'a' }, { id: 'b' }, { id: 'c' }];

    assert.deepEqual(seitenEntfernen(seiten, 'b'), [{ id: 'a' }, { id: 'c' }]);
});

test('removing an id that is not there changes nothing', () => {
    const seiten = [{ id: 'a' }];

    assert.deepEqual(seitenEntfernen(seiten, 'x'), seiten);
});

test('HEIC is recognised by MIME type or file extension', () => {
    assert.equal(istHeic({ type: 'image/heic', name: 'foto.heic' }), true);
    assert.equal(istHeic({ type: 'image/heif', name: 'foto.heif' }), true);
    assert.equal(istHeic({ type: '', name: 'IMG_0001.HEIC' }), true, 'extension alone is enough');
    assert.equal(istHeic({ type: 'image/jpeg', name: 'foto.jpg' }), false);
});

test('the submit payload carries only blob ids, in page order', () => {
    const seiten = [{ id: 'a', blobId: 3 }, { id: 'b', blobId: 1 }];
    const angaben = { name: 'Max Muster', email: null, erstattung: 'keine', iban: null, kontoinhaber: null, freitext: 'Getränke', kostenstelle: null, datenschutz: true };

    assert.deepEqual(einreichenNutzlast(seiten, angaben), {
        blobs: [3, 1],
        name: 'Max Muster',
        email: null,
        erstattung: 'keine',
        iban: null,
        kontoinhaber: null,
        freitext: 'Getränke',
        kostenstelle: null,
        datenschutz: true,
    });
});

test('seiteHochladen calls the injected uploader with the public base path', async () => {
    const aufrufe = [];
    const fakeHochladen = async (datei, optionen) => {
        aufrufe.push({ datei: datei, optionen: optionen });

        return { blob_id: 42 };
    };

    const ergebnis = await seiteHochladen({ name: 'beleg.jpg' }, { token: 'tok', hochladen: fakeHochladen });

    assert.equal(ergebnis.blob_id, 42);
    assert.equal(aufrufe.length, 1);
    assert.equal(aufrufe[0].optionen.csrf, 'tok');
    assert.equal(aufrufe[0].optionen.basis, '/einreichen/upload');
});

test('seiteHochladen without an uploader available refuses instead of touching the network', async () => {
    await assert.rejects(seiteHochladen({ name: 'x' }, {}), /Kein Upload verfügbar/);
});

/** A fetch stand-in that records the call and answers with one fixed response. */
function fakeFetch(status, body) {
    const aufrufe = [];
    const holen = async (pfad, optionen) => {
        aufrufe.push({ pfad: pfad, optionen: optionen });

        return { ok: status >= 200 && status < 300, status: status, json: async () => body };
    };

    return { holen: holen, aufrufe: aufrufe };
}

test('absenden posts the token header and the payload to /einreichen', async () => {
    const fake = fakeFetch(201, { referenz: 'R-2026-0001' });
    const seiten = [{ id: 'a', blobId: 5 }];
    const angaben = { name: 'A', email: null, erstattung: 'keine', iban: null, kontoinhaber: null, freitext: 'x', kostenstelle: null, datenschutz: true };

    const antwort = await absenden(seiten, angaben, { fetch: fake.holen, token: 'tok' });

    assert.equal(antwort.ok, true);
    assert.equal(antwort.daten.referenz, 'R-2026-0001');
    assert.equal(fake.aufrufe[0].pfad, '/einreichen');
    assert.equal(fake.aufrufe[0].optionen.method, 'POST');
    assert.equal(fake.aufrufe[0].optionen.headers['X-CSRF-Token'], 'tok');
    assert.deepEqual(JSON.parse(fake.aufrufe[0].optionen.body).blobs, [5]);
});

test('absenden returns a field-error answer without throwing', async () => {
    const fake = fakeFetch(422, { fehler: { name: 'Bitte einen Namen angeben.' } });

    const antwort = await absenden([{ id: 'a', blobId: 1 }], {
        name: '', email: null, erstattung: 'keine', iban: null, kontoinhaber: null, freitext: 'x', kostenstelle: null, datenschutz: true,
    }, { fetch: fake.holen, token: 'tok' });

    assert.equal(antwort.ok, false);
    assert.equal(antwort.status, 422);
    assert.deepEqual(antwort.daten.fehler, { name: 'Bitte einen Namen angeben.' });
});
