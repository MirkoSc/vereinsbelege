// Pure logic of public/js/nextcloud.js (issue #95, CLAUDE.md section 8: JS
// logic is tested with node --test). The DOM wiring is not covered here -
// the file guards its init behind `typeof document`, so requiring it in
// node loads the helpers only.

const test = require('node:test');
const assert = require('node:assert/strict');

const { naechsteAktion, fortschrittText } = require('../../public/js/nextcloud.js');

test('a step that moved the cursor goes on', () => {
    assert.equal(naechsteAktion({ status: 'laeuft', verarbeitet: 21, gesamt: 40 }, 1), 'weiter');
});

test('the finished chain reloads the page', () => {
    assert.equal(naechsteAktion({ status: 'fertig', verarbeitet: 40, gesamt: 40 }, 21), 'fertig');
});

test('an error, an unknown status or a step without progress stops', () => {
    assert.equal(naechsteAktion({ fehler: 'Die Nextcloud hat die Anmeldung abgelehnt.' }, 3), 'stopp');
    assert.equal(naechsteAktion(null, 3), 'stopp');
    assert.equal(naechsteAktion({ status: 'unbekannt', verarbeitet: 9 }, 3), 'stopp');
    assert.equal(naechsteAktion({ status: 'laeuft', verarbeitet: 3, gesamt: 40 }, 3), 'stopp');
});

test('the progress line counts files and never overshoots', () => {
    assert.equal(fortschrittText(5, 40), 'Wird abgelegt: 5 von 40 Dateien.');
    assert.match(fortschrittText(40, 40), /^Alle 40 Dateien abgelegt/);
    assert.match(fortschrittText(41, 40), /^Alle 40 Dateien abgelegt/);
});
