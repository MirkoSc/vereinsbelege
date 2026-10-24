// Pure step-chain logic of public/js/backups.js (issue #112; CLAUDE.md
// section 8: JS logic is tested with node --test). The DOM wiring is not
// covered here - the file guards its init() behind `typeof document`.

const test = require('node:test');
const assert = require('node:assert/strict');

const { statusText, weiter } = require('../../public/js/backups.js');

test('the chain goes on until the server reports the backup finished', () => {
    assert.equal(weiter({ phase: 'dump', fertig: false }), true);
    assert.equal(weiter({ phase: 'blobs', fertig: false }), true);
    assert.equal(weiter({ phase: 'abschluss', fertig: false }), true);
    assert.equal(weiter({ phase: 'fertig', fertig: true, name: 'backup_20261024_120000.zip' }), false);
    assert.equal(weiter(null), false);
});

test('the status line names the phase and the blob progress', () => {
    assert.equal(statusText({ phase: 'dump', fertig: false }), 'Datenbank wird gesichert …');
    assert.equal(
        statusText({ phase: 'blobs', fertig: false, blob_dateien: 200, blob_gesamt: 450 }),
        'Belegdateien: 200 von 450 gesichert …',
    );
    assert.equal(statusText({ phase: 'blobs', fertig: false, blob_dateien: 0, blob_gesamt: 0 }), 'Belegdateien werden gesucht …');
    assert.equal(statusText({ phase: 'abschluss', fertig: false }), 'Backup wird abgeschlossen …');
    assert.equal(
        statusText({ phase: 'fertig', fertig: true, name: 'backup_20261024_120000.zip' }),
        'Backup backup_20261024_120000.zip erstellt.',
    );
    assert.equal(statusText(null), '');
});

test('more files than counted at the start never show as more than all', () => {
    assert.equal(
        statusText({ phase: 'blobs', fertig: false, blob_dateien: 12, blob_gesamt: 10 }),
        'Belegdateien: 10 von 10 gesichert …',
    );
});
