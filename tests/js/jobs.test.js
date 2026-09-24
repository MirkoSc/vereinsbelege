// Pure helpers of public/js/jobs.js (CLAUDE.md section 8: JS logic is
// tested with node --test). The DOM wiring (initJobs()) is not covered
// here - the file guards it behind `typeof document`, so requiring it in
// node loads the helpers only.

const test = require('node:test');
const assert = require('node:assert/strict');

const { kopfText, wartezeit } = require('../../public/js/jobs.js');

test('the badge is hidden for zero', () => {
    assert.equal(kopfText(0), '');
});

test('one waiting job gets the singular', () => {
    assert.equal(kopfText(1), '1 Beleg in Verarbeitung');
});

test('more than one gets the plural with the count', () => {
    assert.equal(kopfText(3), '3 Belege in Verarbeitung');
});

// docs/spec/06-betrieb.md section 4's table, one row at a time.
test('a step that ran asks again almost at once, slower in the background', () => {
    assert.equal(wartezeit('gearbeitet', 0, true), 250);
    assert.equal(wartezeit('gearbeitet', 5, false), 30000);
});

test('nothing to do but jobs still waiting asks again soon', () => {
    assert.equal(wartezeit('leer', 4, true), 10000);
    assert.equal(wartezeit('leer', 4, false), 60000);
});

test('nothing to do and nothing waiting backs off further', () => {
    assert.equal(wartezeit('leer', 0, true), 30000);
    assert.equal(wartezeit('leer', 0, false), 120000);
});

test('a locked vault or a login problem stops the loop, visible or not', () => {
    assert.equal(wartezeit('gesperrt', 0, true), null);
    assert.equal(wartezeit('gesperrt', 3, false), null);
    assert.equal(wartezeit('anmeldung', 0, true), null);
    assert.equal(wartezeit('anmeldung', 0, false), null);
});

test('a network or server error backs off like an empty poll, not a stop', () => {
    assert.equal(wartezeit('fehler', 0, true), 30000);
    assert.equal(wartezeit('fehler', 0, false), 120000);
});
