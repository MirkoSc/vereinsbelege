// Pure step-chain logic of public/js/update.js (CLAUDE.md section 8: JS
// logic is tested with node --test). The DOM wiring is not covered here -
// the file guards its init() behind `typeof document`, so requiring it in
// node loads the helpers only.

const test = require('node:test');
const assert = require('node:assert/strict');

const { naechsteSchritte, abSchritt, letzteMeldung } = require('../../public/js/update.js');

const SCHRITTE = ['check', 'download', 'extract', 'backup', 'switch', 'migrate', 'finish'];

test('after the version check the rest of the chain follows', () => {
    assert.deepEqual(naechsteSchritte(SCHRITTE, 'check'), [
        'download',
        'extract',
        'backup',
        'switch',
        'migrate',
        'finish',
    ]);
});

test('nothing is left after the last step', () => {
    assert.deepEqual(naechsteSchritte(SCHRITTE, 'finish'), []);
});

// A state file written by an older release can name a step this version
// does not know. Running the whole chain is the safe answer: every step is
// idempotent, whereas skipping steps would switch releases without a
// download.
test('an unknown step means the whole chain runs', () => {
    assert.deepEqual(naechsteSchritte(SCHRITTE, 'nachbessern'), SCHRITTE);
    assert.deepEqual(naechsteSchritte(SCHRITTE, undefined), SCHRITTE);
});

test('a retry repeats the failed step and everything after it', () => {
    assert.deepEqual(abSchritt(SCHRITTE, 'switch'), ['switch', 'migrate', 'finish']);
    assert.deepEqual(abSchritt(SCHRITTE, 'check'), SCHRITTE);
});

test('a retry of an unknown step does nothing', () => {
    assert.deepEqual(abSchritt(SCHRITTE, 'rollback'), []);
});

test('the step log shows the last line of the state', () => {
    assert.equal(letzteMeldung({ meldungen: ['erste', 'zweite'] }), 'zweite');
    assert.equal(letzteMeldung({ meldungen: [] }), '');
    assert.equal(letzteMeldung({}), '');
    assert.equal(letzteMeldung(null), '');
});

test('naechsteSchritte does not modify the list it was given', () => {
    const kopie = SCHRITTE.slice();
    naechsteSchritte(kopie, 'check');
    assert.deepEqual(kopie, SCHRITTE);
});
