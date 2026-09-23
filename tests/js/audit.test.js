// Pure step-chain logic of public/js/audit.js (CLAUDE.md section 8: JS
// logic is tested with node --test). The DOM wiring is not covered here -
// the file guards its init() behind `typeof document`, so requiring it in
// node loads the helpers only.

const test = require('node:test');
const assert = require('node:assert/strict');

const { naechsterSchritt, pruefText, ergebnis, kontrollwert } = require('../../public/js/audit.js');

test('without an answer yet the check starts at the beginning of the chain', () => {
    assert.equal(naechsterSchritt(null), 0);
    assert.equal(naechsterSchritt(undefined), 0);
});

test('an unfinished answer continues after the last checked row', () => {
    assert.equal(naechsterSchritt({ fertig: false, geprueft: 2000, nach_id: 2000 }), 2000);
});

test('a finished answer ends the chain', () => {
    assert.equal(naechsterSchritt({ fertig: true, geprueft: 12, nach_id: 2012 }), null);
});

// The termination guard: an answer that checked nothing cannot move the
// chain on, so repeating it would loop forever.
test('an answer that checked nothing ends the chain even if not marked finished', () => {
    assert.equal(naechsterSchritt({ fertig: false, geprueft: 0, nach_id: 7 }), null);
});

test('the progress line never counts past what it checked', () => {
    assert.equal(pruefText(2000, 4500), '2000 von 4500 Einträgen geprüft …');
    assert.equal(pruefText(4600, 4500), '4600 von 4600 Einträgen geprüft …');
});

test('an intact chain reports the control value and the last row', () => {
    const aus = ergebnis(3, { fertig: true, geprueft: 3, nach_id: 3, bruch: null, kopf: 'ab'.repeat(32) });

    assert.equal(aus.ok, true);
    assert.match(aus.text, /Alle 3 Einträge sind unverändert/);
    assert.match(aus.text, /Nr\. 3/);
    assert.ok(aus.text.includes(kontrollwert('ab'.repeat(32))));
});

test('a break names the row and the reason', () => {
    const aus = ergebnis(41, {
        fertig: true,
        geprueft: 1,
        nach_id: 41,
        bruch: { id: 42, meldung: 'Der Inhalt des Eintrags wurde verändert.' },
        kopf: null,
    });

    assert.equal(aus.ok, false);
    assert.match(aus.text, /Nr\. 42/);
    assert.match(aus.text, /Inhalt des Eintrags wurde verändert/);
    assert.match(aus.text, /41 Einträge davor/);
});

test('an empty log is reported as such, not as a pass with a control value', () => {
    const aus = ergebnis(0, { fertig: true, geprueft: 0, nach_id: 0, bruch: null, kopf: null });

    assert.equal(aus.ok, true);
    assert.match(aus.text, /leer/);
});

test('the control value is written in groups of eight', () => {
    const hex = '0123456789abcdef'.repeat(4);

    assert.equal(kontrollwert(hex), '01234567 89abcdef 01234567 89abcdef 01234567 89abcdef 01234567 89abcdef');
    assert.equal(kontrollwert(hex).replace(/ /g, ''), hex);
});
