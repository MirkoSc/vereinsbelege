// Pure step-chain logic of public/js/speicher.js (CLAUDE.md section 8: JS
// logic is tested with node --test). The DOM wiring is not covered here -
// the file guards its init() behind `typeof document`, so requiring it in
// node loads the helpers only.

const test = require('node:test');
const assert = require('node:assert/strict');

const { naechsterSchritt, umzugText, pruefText, misslungenText } = require('../../public/js/speicher.js');

test('without an answer yet the chain starts by moving', () => {
    assert.equal(naechsterSchritt(null), 'verschieben');
    assert.equal(naechsterSchritt(undefined), 'verschieben');
});

test('moving repeats while blobs are open and progress is being made', () => {
    assert.equal(naechsterSchritt({ offen: 120, verschoben: 25, misslungen: [] }), 'verschieben');
});

// The termination guard: if a request moved nothing although blobs are open,
// every remaining one failed. Repeating would loop forever, so the chain
// stops and the page says which rows are stuck.
test('a request that moves nothing although blobs are open ends the chain', () => {
    assert.equal(naechsterSchritt({ offen: 3, verschoben: 0, misslungen: [7, 8, 9] }), null);
});

test('once nothing is open the leftovers are cleaned up', () => {
    assert.equal(naechsterSchritt({ offen: 0, verschoben: 12, misslungen: [] }), 'aufraeumen');
});

test('the progress line counts what has arrived, not what is left', () => {
    assert.equal(umzugText({ offen: 40, gesamt: 100, verschoben: 10 }), '60 von 100 Dateien verschoben.');
    assert.equal(umzugText({ offen: 0, gesamt: 100, verschoben: 10 }), 'Alle Dateien liegen im Ziel-Backend.');
});

// The table can grow while the chain runs (a receipt is uploaded), so "done"
// may exceed the total counted at the start. The line must not go negative
// or claim more than it checked.
test('the progress line survives a total that has moved on', () => {
    assert.equal(umzugText({ offen: 120, gesamt: 100, verschoben: 5 }), '0 von 100 Dateien verschoben.');
    assert.equal(umzugText(null), '');
});

test('the check line shows progress while it walks the table', () => {
    assert.equal(
        pruefText({ geprueft: 37, gesamt: 340, beschaedigt: [], fertig: false }),
        '37 von 340 Dateien geprüft …',
    );
});

test('a finished check without findings says so', () => {
    assert.equal(
        pruefText({ geprueft: 340, gesamt: 340, beschaedigt: [], fertig: true }),
        '340 von 340 Dateien geprüft – ohne Befund.',
    );
});

test('a finished check names how many blobs are damaged', () => {
    assert.equal(
        pruefText({ geprueft: 340, gesamt: 340, beschaedigt: [12, 77], fertig: true }),
        '340 von 340 Dateien geprüft – 2 beschädigt.',
    );
});

test('a check state that was never started renders nothing odd', () => {
    assert.equal(pruefText(null), '');
    assert.equal(pruefText({}), '0 von 0 Dateien geprüft …');
});

test('a single stuck file is reported in the singular', () => {
    assert.equal(
        misslungenText([4]),
        '1 Datei konnte nicht verschoben werden (ID: 4) und liegt unverändert im alten Backend; ' +
            'der Rest ist umgezogen.',
    );
});

test('several stuck files are named by id', () => {
    assert.equal(
        misslungenText([4, 9]),
        '2 Dateien konnten nicht verschoben werden (IDs: 4, 9) und liegen unverändert im alten Backend; ' +
            'der Rest ist umgezogen.',
    );
});
