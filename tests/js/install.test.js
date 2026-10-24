// Pure progress logic of public/js/install.js (CLAUDE.md section 8: JS logic
// is tested with node --test). The DOM wiring is not covered here - the file
// guards its init behind `typeof document`.

const test = require('node:test');
const assert = require('node:assert/strict');

const { fortschrittProzent, statusText, feldgruppenFuer } = require('../../public/js/install.js');

test('progress is the share of statements applied, in whole percent', () => {
    assert.equal(fortschrittProzent(0, 400), 0);
    assert.equal(fortschrittProzent(200, 400), 50);
    assert.equal(fortschrittProzent(399, 400), 99, 'never rounds up to 100 before it is done');
    assert.equal(fortschrittProzent(400, 400), 100);
});

test('progress never exceeds 100', () => {
    assert.equal(fortschrittProzent(500, 400), 100);
});

test('an empty dump counts as done rather than dividing by zero', () => {
    assert.equal(fortschrittProzent(0, 0), 100);
    assert.equal(fortschrittProzent(0, undefined), 100);
});

test('the status line names the position, and the end', () => {
    assert.equal(
        statusText({ fertig: false, offset: 2048, gesamt: 8192, anweisungen: 200 }),
        'Anweisung 200 eingespielt (25 %) …',
        'the dump phase counts bytes of dump.sql and names the statements run so far',
    );
    assert.equal(statusText({ fertig: true, offset: 400, gesamt: 400 }), 'Fertig. Das Backup ist eingespielt.');
    assert.equal(statusText(null), 'Starte …');
});

test('the blob phase counts files, not statements', () => {
    assert.equal(
        statusText({ fertig: false, phase: 'blobs', offset: 0, gesamt: 12 }),
        'Datei 0 von 12 …',
        'the switch into the blob phase already names the new total',
    );
    assert.equal(statusText({ fertig: false, phase: 'blobs', offset: 7, gesamt: 12 }), 'Datei 7 von 12 …');
    assert.equal(
        statusText({ fertig: false, phase: 'sql', offset: 6, gesamt: 12, anweisungen: 3 }),
        'Anweisung 3 eingespielt (50 %) …',
        'an answer without a phase is the dump phase',
    );
    assert.equal(
        statusText({ fertig: true, phase: 'blobs', offset: 12, gesamt: 12 }),
        'Fertig. Das Backup ist eingespielt.',
    );
});

test('a fresh install shows the admin fieldset and hides the backup upload', () => {
    assert.deepEqual(feldgruppenFuer('frisch'), {
        ersterZugangVerdeckt: false,
        backupVerdeckt: true,
    });
});

test('a restore shows the backup upload and hides the admin fieldset', () => {
    assert.deepEqual(feldgruppenFuer('restore'), {
        ersterZugangVerdeckt: true,
        backupVerdeckt: false,
    });
});

test('an unknown mode is treated like a restore, not a fresh install', () => {
    // Whatever the server did not preselect must not accidentally reveal
    // the admin fieldset - the safer default is "hidden".
    assert.deepEqual(feldgruppenFuer('irgendwas'), {
        ersterZugangVerdeckt: true,
        backupVerdeckt: true,
    });
});

test('a finished restore without server key sends the page on to the access step', () => {
    const { zielNachAbschluss } = require('../../public/js/install.js');
    assert.equal(zielNachAbschluss({ fertig: true, weiter: '/install' }), '/install');
    assert.equal(zielNachAbschluss({ fertig: true }), null, 'a complete restore stays on the page');
    assert.equal(zielNachAbschluss({ fertig: false, weiter: '/install' }), null, 'only once the restore is through');
    assert.equal(zielNachAbschluss(null), null);
});

test('the access-step destination is only ever a path on this host', () => {
    const { zielNachAbschluss } = require('../../public/js/install.js');
    assert.equal(zielNachAbschluss({ fertig: true, weiter: 'https://example.org/' }), null);
    assert.equal(zielNachAbschluss({ fertig: true, weiter: '//example.org/install' }), null);
    assert.equal(zielNachAbschluss({ fertig: true, weiter: '' }), null);
    assert.equal(zielNachAbschluss({ fertig: true, weiter: 42 }), null);
});

test('the status line says where a key-less restore goes next', () => {
    assert.equal(
        statusText({ fertig: true, offset: 12, gesamt: 12, weiter: '/install' }),
        'Fertig. Das Backup ist eingespielt – weiter zum Zugang …',
    );
});
