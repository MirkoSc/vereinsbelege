// Pure step-chain logic of public/js/tresor-rotation.js (issue #90). The DOM
// wiring is guarded behind `typeof document`, so requiring the file in node
// loads the helpers only.

const test = require('node:test');
const assert = require('node:assert/strict');

const { weiter, standText, OHNE_FORTSCHRITT_MAX } = require('../../public/js/tresor-rotation.js');

test('without an answer yet the chain sends its first step', () => {
    assert.equal(weiter(null, 0), true);
});

test('the chain stops once everything is re-sealed', () => {
    assert.equal(weiter({ umgeschluesselt: true, offen: 0, defekt: 0 }, 0), false);
});

test('the chain goes on while rows are left and it makes progress', () => {
    assert.equal(weiter({ umgeschluesselt: false, offen: 120, defekt: 0 }, 0), true);
    assert.equal(weiter({ umgeschluesselt: false, offen: 120, defekt: 0 }, OHNE_FORTSCHRITT_MAX - 1), true);
});

test('the chain gives up after several steps without progress instead of looping', () => {
    assert.equal(weiter({ umgeschluesselt: false, offen: 5, defekt: 0 }, OHNE_FORTSCHRITT_MAX), false);
});

test('the status line names what is left, and unreadable rows at the end', () => {
    assert.equal(standText(null), '');
    assert.equal(standText({ umgeschluesselt: false, offen: 42, defekt: 0 }), 'Noch 42 Datensätze mit dem alten Schlüssel …');
    assert.equal(standText({ umgeschluesselt: true, offen: 0, defekt: 0 }), 'Alle Datenschlüssel sind umgestellt.');
    assert.match(standText({ umgeschluesselt: true, offen: 2, defekt: 2 }), /2 Datensätze waren schon vorher nicht lesbar/);
});
