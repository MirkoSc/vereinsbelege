// Pure logic of public/js/archivimport.js (issue #77/M12-3, CLAUDE.md
// section 8: JS logic is tested with node --test). The DOM wiring is not
// covered here - the file guards its init behind `typeof document`, so
// requiring it in node loads the helpers only.

const test = require('node:test');
const assert = require('node:assert/strict');

const { dateiPruefen, naechsteAktion, fortschrittText } = require('../../public/js/archivimport.js');

const MAX = 2 * 1024 * 1024 * 1024;

test('a ZIP within the limit is fine', () => {
    assert.equal(dateiPruefen({ name: 'Belege 2026.zip', size: 1000 }, MAX), null);
    assert.equal(dateiPruefen({ name: 'ARCHIV.ZIP', size: 1000 }, MAX), null);
});

test('no file, another type, an empty or too large file are refused before uploading', () => {
    assert.match(dateiPruefen(undefined, MAX), /ZIP-Datei wählen/);
    assert.match(dateiPruefen({ name: 'Bauhaus 03.02.2026.pdf', size: 1000 }, MAX), /Endung \.zip/);
    assert.match(dateiPruefen({ name: 'leer.zip', size: 0 }, MAX), /leer/);
    assert.match(dateiPruefen({ name: 'riesig.zip', size: MAX + 1 }, MAX), /zu groß/);
});

test('the chain goes on while the import runs and moves', () => {
    assert.equal(naechsteAktion({ status: 'laeuft', verarbeitet: 10, gesamt: 400 }, 0), 'weiter');
    assert.equal(naechsteAktion({ status: 'laeuft', verarbeitet: 20, gesamt: 400 }, 10), 'weiter');
});

test('a finished or cut-off import reloads the page', () => {
    assert.equal(naechsteAktion({ status: 'fertig', verarbeitet: 400, gesamt: 400 }, 390), 'fertig');
    // Another tab finished it meanwhile: still done, not an error.
    assert.equal(naechsteAktion({ status: 'fertig', verarbeitet: 400, gesamt: 400 }, 400), 'fertig');
    // The upload expired on the server: the page explains it.
    assert.equal(naechsteAktion({ status: 'abgebrochen', verarbeitet: 30, gesamt: 400, meldung: 'weg' }, 30), 'fertig');
});

test('a step without progress stops instead of looping', () => {
    assert.equal(naechsteAktion({ status: 'laeuft', verarbeitet: 10, gesamt: 400 }, 10), 'stopp');
});

test('an error or an unexpected answer stops the chain', () => {
    assert.equal(naechsteAktion({ fehler: 'Dieser Import ist noch nicht gestartet.' }, 0), 'stopp');
    assert.equal(naechsteAktion(null, 0), 'stopp');
    assert.equal(naechsteAktion({ status: 'vorschau', verarbeitet: 0 }, 0), 'stopp');
});

test('the progress line counts entries and documents', () => {
    assert.equal(fortschrittText(10, 400, 9), 'Das Archiv wird übernommen: 10 von 400 Einträgen, 9 Belege angelegt.');
    assert.equal(fortschrittText(1, 400, 1), 'Das Archiv wird übernommen: 1 von 400 Einträgen, 1 Beleg angelegt.');
    assert.equal(fortschrittText(400, 400, 380), 'Alle 400 Einträge verarbeitet – das Ergebnis wird geladen …');
});
