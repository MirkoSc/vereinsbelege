// Pure progress logic of public/js/install.js (CLAUDE.md section 8: JS logic
// is tested with node --test). The DOM wiring is not covered here - the file
// guards its init behind `typeof document`.

const test = require('node:test');
const assert = require('node:assert/strict');

const { fortschrittProzent, statusText } = require('../../public/js/install.js');

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
    assert.equal(statusText({ fertig: false, offset: 200, gesamt: 400 }), 'Anweisung 200 von 400 …');
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
        statusText({ fertig: false, phase: 'sql', offset: 7, gesamt: 12 }),
        'Anweisung 7 von 12 …',
        'an answer without a phase is the dump phase',
    );
    assert.equal(
        statusText({ fertig: true, phase: 'blobs', offset: 12, gesamt: 12 }),
        'Fertig. Das Backup ist eingespielt.',
    );
});
