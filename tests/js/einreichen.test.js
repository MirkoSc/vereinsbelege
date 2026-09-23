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
    powLoesen,
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
    const angaben = {
        name: 'Max Muster', email: null, erstattung: 'keine', iban: null, kontoinhaber: null,
        freitext: 'Getränke', kostenstelle: null, datenschutz: true, webseite: '',
    };

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
        webseite: '',
    });
});

test('the honeypot travels through the payload unchanged (issue #25/M4-3)', () => {
    const seiten = [{ id: 'a', blobId: 1 }];
    const angaben = {
        name: 'Bot', email: null, erstattung: 'keine', iban: null, kontoinhaber: null,
        freitext: 'x', kostenstelle: null, datenschutz: true, webseite: 'https://bot.example',
    };

    assert.equal(einreichenNutzlast(seiten, angaben).webseite, 'https://bot.example');
});

test('powLoesen finds the number a real client would find (issue #25/M4-3)', async () => {
    const salt = 'ein-testsalz';
    let gesucht = -1;
    let challenge = '';
    for (let zahl = 0; zahl <= 500; zahl++) {
        const puffer = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(salt + zahl));
        const hex = Array.from(new Uint8Array(puffer)).map((b) => b.toString(16).padStart(2, '0')).join('');
        // Any fixed rule reproducibly picks one candidate as "the" challenge
        // without hard-coding a precomputed hash into the test.
        if (zahl === 137) {
            challenge = hex;
            gesucht = zahl;
        }
    }

    const loesung = await powLoesen(salt, challenge, 500);

    assert.equal(loesung, String(gesucht));
});

test('powLoesen gives up and returns null once max is exhausted', async () => {
    const loesung = await powLoesen('salz', 'nie-erreichbare-challenge', 20);

    assert.equal(loesung, null);
});

test('powLoesen returns null for an empty challenge instead of hashing forever', async () => {
    assert.equal(await powLoesen('', '', 0), null);
    assert.equal(await powLoesen('salz', 'x', 0), null);
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

test('seiteHochladen forwards the proof-of-work header (issue #25/M4-3)', async () => {
    const aufrufe = [];
    const fakeHochladen = async (datei, optionen) => {
        aufrufe.push(optionen);

        return { blob_id: 1 };
    };

    await seiteHochladen({ name: 'x' }, { token: 'tok', hochladen: fakeHochladen, headers: { 'X-Pow-Loesung': '99' } });

    assert.deepEqual(aufrufe[0].headers, { 'X-Pow-Loesung': '99' });
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

test('absenden forwards the proof-of-work header alongside the CSRF token (issue #25/M4-3)', async () => {
    const fake = fakeFetch(201, { referenz: 'R-2026-0002' });

    await absenden([{ id: 'a', blobId: 1 }], {
        name: 'A', email: null, erstattung: 'keine', iban: null, kontoinhaber: null, freitext: 'x', kostenstelle: null, datenschutz: true, webseite: '',
    }, { fetch: fake.holen, token: 'tok', headers: { 'X-Pow-Loesung': '55' } });

    assert.equal(fake.aufrufe[0].optionen.headers['X-Pow-Loesung'], '55');
    assert.equal(fake.aufrufe[0].optionen.headers['X-CSRF-Token'], 'tok');
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
