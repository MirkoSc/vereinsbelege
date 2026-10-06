// Pure step-chain logic of public/js/kontoauszug.js (issue #62/M9-4,
// CLAUDE.md section 8: JS logic is tested with node --test). The DOM wiring
// is not covered here - the file guards its init behind `typeof document`,
// so requiring it in node loads the helpers only.

const test = require('node:test');
const assert = require('node:assert/strict');

const { naechsteAktion, fortschrittText } = require('../../public/js/kontoauszug.js');

test('the chain goes on while the import runs and moves', () => {
    assert.equal(naechsteAktion({ status: 'laeuft', verarbeitet: 100, gesamt: 250 }, 0), 'weiter');
    assert.equal(naechsteAktion({ status: 'laeuft', verarbeitet: 200, gesamt: 250 }, 100), 'weiter');
});

test('a finished import ends the chain', () => {
    assert.equal(naechsteAktion({ status: 'fertig', verarbeitet: 250, gesamt: 250 }, 200), 'fertig');
    // Another tab finished it meanwhile: still done, not an error.
    assert.equal(naechsteAktion({ status: 'fertig', verarbeitet: 250, gesamt: 250 }, 250), 'fertig');
});

// The termination guard: a step that moved nothing although the import still
// runs would repeat forever - the chain stops and offers to continue.
test('a step without progress stops instead of looping', () => {
    assert.equal(naechsteAktion({ status: 'laeuft', verarbeitet: 100, gesamt: 250 }, 100), 'stopp');
});

test('an error or an unexpected answer stops the chain', () => {
    assert.equal(naechsteAktion({ fehler: 'Dieser Import ist nicht bestätigt.' }, 0), 'stopp');
    assert.equal(naechsteAktion(null, 0), 'stopp');
    assert.equal(naechsteAktion({ status: 'vorschau', verarbeitet: 0, gesamt: 3 }, 0), 'stopp');
});

test('the progress line counts what is done and never overshoots', () => {
    assert.equal(fortschrittText(100, 250), 'Die Buchungen werden übernommen: 100 von 250.');
    assert.equal(fortschrittText(0, 3), 'Die Buchungen werden übernommen: 0 von 3.');
    assert.match(fortschrittText(250, 250), /^Alle 250 Buchungen geprüft/);
    assert.match(fortschrittText(300, 250), /^Alle 250 Buchungen/);
});
