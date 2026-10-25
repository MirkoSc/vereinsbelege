// Pure step-chain logic of public/js/integritaet.js (issue #79/M13-2;
// CLAUDE.md section 8: JS logic is tested with node --test). The DOM wiring
// is not covered here - the file guards its init() behind `typeof document`.

const test = require('node:test');
const assert = require('node:assert/strict');

const { statusText, weiter } = require('../../public/js/integritaet.js');

test('the chain goes on until the server reports the check finished', () => {
    assert.equal(weiter({ phase: 'dateien', fertig: false, geprueft: 0, gesamt: 10 }), true);
    assert.equal(weiter({ phase: 'zusaetzlich', fertig: false }), true);
    assert.equal(weiter({ phase: 'fertig', fertig: true }), false);
    assert.equal(weiter(null), false);
});

test('the status line names the progress and the phase', () => {
    assert.equal(
        statusText({ phase: 'dateien', fertig: false, geprueft: 500, gesamt: 2400 }),
        'Dateien geprüft: 500 von 2400 …',
    );
    assert.equal(statusText({ phase: 'zusaetzlich', fertig: false, geprueft: 2400, gesamt: 2400 }), 'Suche nach zusätzlichen Dateien …');
    assert.equal(statusText({ phase: 'umgebung', fertig: false, geprueft: 2400, gesamt: 2400 }), 'Prüfe Web-Verzeichnis und Konfiguration …');
    assert.equal(statusText({ phase: 'fertig', fertig: true }), 'Prüfung abgeschlossen.');
    assert.equal(statusText({ phase: 'dateien', fertig: false, geprueft: 0, gesamt: 0 }), 'Prüfung läuft …');
    assert.equal(statusText(null), '');
});

test('the progress never shows more than all files', () => {
    assert.equal(statusText({ phase: 'dateien', fertig: false, geprueft: 12, gesamt: 10 }), 'Dateien geprüft: 10 von 10 …');
});
