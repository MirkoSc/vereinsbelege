// The browser file is loaded directly: `document` does not exist in Node, so
// initErfassen() never runs - only the pure helpers and the two async
// functions (which take their collaborators as parameters) are tested here
// (issue #28/M4-6, the internal capture /app/belege/neu).

const test = require('node:test');
const assert = require('node:assert/strict');
const {
    belegAnlegen,
    belegEntfernen,
    seitenAendern,
    belegeBereit,
    erfassenNutzlast,
    fehlerZuordnen,
    erfassenHochladen,
    erfassenAbsenden,
} = require('../../public/js/erfassen.js');

const fertig = (id, blobId) => ({ id: id, status: 'fertig', blobId: blobId });

test('a new receipt starts empty at the end of the list', () => {
    const belege = belegAnlegen(belegAnlegen([], 'a'), 'b');

    assert.deepEqual(belege, [{ id: 'a', seiten: [] }, { id: 'b', seiten: [] }]);
});

test('removing a receipt keeps the others in order and the input untouched', () => {
    const belege = [{ id: 'a', seiten: [] }, { id: 'b', seiten: [] }, { id: 'c', seiten: [] }];

    assert.deepEqual(belegEntfernen(belege, 'b').map((b) => b.id), ['a', 'c']);
    assert.equal(belege.length, 3);
});

test('changing the pages of one receipt leaves the others alone', () => {
    const belege = [{ id: 'a', seiten: [fertig('s1', 1)] }, { id: 'b', seiten: [] }];

    const neu = seitenAendern(belege, 'b', (seiten) => seiten.concat([fertig('s2', 2)]));

    assert.deepEqual(neu[1].seiten, [fertig('s2', 2)]);
    assert.equal(neu[0], belege[0]);
    assert.deepEqual(belege[1].seiten, [], 'the input is not mutated');
});

test('a pass is ready only with pages that all finished uploading', () => {
    assert.match(belegeBereit([]), /mindestens einen Beleg/);
    assert.match(belegeBereit([{ id: 'a', seiten: [] }]), /leere Belege/);
    assert.match(belegeBereit([{ id: 'a', seiten: [{ id: 's', status: 'laedt' }] }]), /warten/);
    assert.match(belegeBereit([{ id: 'a', seiten: [{ id: 's', status: 'fehler' }] }]), /fehlgeschlagene/);
    assert.equal(belegeBereit([{ id: 'a', seiten: [fertig('s', 1)] }]), null);
});

test('the body carries every receipt with its pages in order and optional fields as null', () => {
    const belege = [
        { id: 'a', seiten: [fertig('s1', 11), fertig('s2', 12)] },
        { id: 'b', seiten: [fertig('s3', 13)] },
    ];

    const nutzlast = erfassenNutzlast('ab'.repeat(16), belege, [
        { freitext: '', kostenstelle: '', erstattung: '', iban: 'DE89370400440532013000', kontoinhaber: 'X' },
        { freitext: 'Trikots', kostenstelle: '3', erstattung: 'ueberweisung', iban: 'DE89370400440532013000', kontoinhaber: 'Erika' },
    ]);

    assert.deepEqual(nutzlast, {
        erfassung: 'ab'.repeat(16),
        belege: [
            { blobs: [11, 12], freitext: '', kostenstelle: null, erstattung: null, iban: null, kontoinhaber: null },
            { blobs: [13], freitext: 'Trikots', kostenstelle: '3', erstattung: 'ueberweisung', iban: 'DE89370400440532013000', kontoinhaber: 'Erika' },
        ],
    });
});

test('bank details are only sent for a transfer', () => {
    const nutzlast = erfassenNutzlast('x', [{ id: 'a', seiten: [fertig('s', 1)] }], [
        { erstattung: 'bar', iban: 'DE89370400440532013000', kontoinhaber: 'Erika' },
    ]);

    assert.equal(nutzlast.belege[0].erstattung, 'bar');
    assert.equal(nutzlast.belege[0].iban, null);
    assert.equal(nutzlast.belege[0].kontoinhaber, null);
});

test('field errors are split by receipt index, the rest is general', () => {
    const zuordnung = fehlerZuordnen({
        '0.seiten': 'Seite fehlt',
        '2.iban': 'IBAN falsch',
        '2.kontoinhaber': 'Name fehlt',
        belege: 'Zu viele',
    });

    assert.equal(zuordnung.allgemein, 'Zu viele');
    assert.deepEqual(zuordnung.jeBeleg, {
        0: { seiten: 'Seite fehlt' },
        2: { iban: 'IBAN falsch', kontoinhaber: 'Name fehlt' },
    });
});

test('a plain error sentence becomes the general message', () => {
    assert.deepEqual(fehlerZuordnen('Sitzung abgelaufen'), { allgemein: 'Sitzung abgelaufen', jeBeleg: {} });
    assert.equal(fehlerZuordnen(undefined).allgemein, 'Die Erfassung ist fehlgeschlagen.');
});

test('a page goes to the session upload with the capture id on every request', async () => {
    let gesehen = null;
    const hochladen = async (datei, optionen) => {
        gesehen = optionen;

        return { blob_id: 42 };
    };

    const ergebnis = await erfassenHochladen({ name: 'a.pdf' }, { csrf: 'token', erfassung: 'cd'.repeat(16), hochladen: hochladen });

    assert.deepEqual(ergebnis, { blob_id: 42 });
    assert.equal(gesehen.basis, '/api/upload');
    assert.equal(gesehen.csrf, 'token');
    assert.deepEqual(gesehen.headers, { 'X-Erfassung': 'cd'.repeat(16) });
});

test('the submit posts JSON with the CSRF header and hands back a 422 as data', async () => {
    const anfragen = [];
    const fetch = async (pfad, anfrage) => {
        anfragen.push({ pfad: pfad, anfrage: anfrage });

        return { ok: false, status: 422, json: async () => ({ fehler: { '0.iban': 'IBAN falsch' } }) };
    };

    const antwort = await erfassenAbsenden({ erfassung: 'x', belege: [] }, { csrf: 'token', fetch: fetch });

    assert.deepEqual(antwort, { ok: false, status: 422, daten: { fehler: { '0.iban': 'IBAN falsch' } } });
    assert.equal(anfragen.length, 1);
    assert.equal(anfragen[0].pfad, '/app/belege/neu');
    assert.equal(anfragen[0].anfrage.method, 'POST');
    assert.equal(anfragen[0].anfrage.headers['X-CSRF-Token'], 'token');
    assert.equal(anfragen[0].anfrage.headers['Content-Type'], 'application/json');
    assert.deepEqual(JSON.parse(anfragen[0].anfrage.body), { erfassung: 'x', belege: [] });
});

test('a successful submit returns the references', async () => {
    const fetch = async () => ({ ok: true, status: 201, json: async () => ({ referenzen: ['R-2026-0001', 'R-2026-0002'] }) });

    const antwort = await erfassenAbsenden({ erfassung: 'x', belege: [] }, { csrf: 't', fetch: fetch });

    assert.equal(antwort.ok, true);
    assert.deepEqual(antwort.daten.referenzen, ['R-2026-0001', 'R-2026-0002']);
});
