// Pure helpers of public/js/suche.js (issue #94): the step chain that
// builds the session's full-text index.
const test = require('node:test');
const assert = require('node:assert/strict');
const { maxSchritte, weiter, standText } = require('../../public/js/suche.js');

test('the chain has an upper bound that grows with the open documents', () => {
    assert.equal(maxSchritte(0, 40), 25);
    assert.equal(maxSchritte(40, 40), 26);
    assert.equal(maxSchritte(41, 40), 27);
    assert.equal(maxSchritte(41, '20'), 28);
    assert.equal(maxSchritte(undefined, 40), 25);
    assert.equal(maxSchritte(-5, 40), 25);
    assert.equal(maxSchritte(3, undefined), 28, 'without a step size every document counts as a step');
});

test('the first step always runs, then until the index is up to date', () => {
    assert.equal(weiter(null, 0, 10), true);
    assert.equal(weiter({ fertig: false, offen: 3 }, 1, 10), true);
    assert.equal(weiter({ fertig: true, offen: 0 }, 2, 10), false);
});

test('the chain stops at its bound even if the server never says done', () => {
    assert.equal(weiter({ fertig: false, offen: 3 }, 10, 10), false);
});

test('the progress line counts down in German', () => {
    assert.equal(standText(1234), 'Der Suchindex dieser Sitzung wird aufgebaut – noch 1.234 Belege …');
    assert.equal(standText(1), 'Der Suchindex dieser Sitzung wird aufgebaut – noch 1 Beleg …');
    assert.equal(standText(0), 'Der Suchindex ist gleich fertig …');
});
