// Drives the backup step chain on /admin/backups (issue #112): one request
// starts the chain, then one request per step until the server reports the
// backup finished (docs/spec/06-betrieb.md section 2). No single request
// runs long.
//
// Its own file rather than an inline script: the CSP is script-src 'self'
// without 'unsafe-inline' (CLAUDE.md section 4). The CSRF token comes from a
// data-* attribute on the form, never from an inline block.

/** Upper bound of steps, so a server that never says "fertig" cannot loop forever. */
const MAX_SCHRITTE = 100000;

/** The status line for a state answered by the server. */
function statusText(stand) {
    if (!stand) {
        return '';
    }
    if (stand.fertig) {
        return 'Backup ' + (stand.name || '') + ' erstellt.';
    }
    switch (stand.phase) {
        case 'dump':
            return 'Datenbank wird gesichert …';
        case 'blobs': {
            const gesamt = stand.blob_gesamt || 0;
            const fertig = Math.min(stand.blob_dateien || 0, gesamt);

            return gesamt === 0
                ? 'Belegdateien werden gesucht …'
                : 'Belegdateien: ' + fertig + ' von ' + gesamt + ' gesichert …';
        }
        case 'abschluss':
            return 'Backup wird abgeschlossen …';
        default:
            return 'Backup wird erstellt …';
    }
}

/** Whether the chain needs another step after this answer. */
function weiter(stand) {
    return !!stand && !stand.fertig;
}

function initBackups() {
    const formular = document.querySelector('#backup-erstellen');
    if (formular === null) {
        return;
    }

    const csrf = formular.dataset.csrf || '';
    const start = formular.querySelector('#backup-start');
    const fortsetzen = formular.querySelector('#backup-fortsetzen');
    const verlauf = document.querySelector('#backup-verlauf');
    const status = document.querySelector('#backup-status');
    const fehler = document.querySelector('#backup-fehler');

    async function senden(pfad, daten) {
        const antwort = await fetch(pfad, {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrf },
            body: daten,
        });
        let inhalt = {};
        try {
            inhalt = await antwort.json();
        } catch (problem) {
            inhalt = {};
        }
        if (!antwort.ok || inhalt.fehler) {
            throw new Error(inhalt.fehler || 'HTTP ' + antwort.status);
        }

        return inhalt;
    }

    async function laufe(ersterStand) {
        let stand = ersterStand;
        let schritte = 0;
        status.textContent = statusText(stand) || 'Backup wird erstellt …';
        while (weiter(stand) && schritte < MAX_SCHRITTE) {
            const daten = new FormData();
            daten.append('_csrf', csrf);
            stand = await senden('/admin/backups/schritt', daten);
            status.textContent = statusText(stand);
            schritte++;
        }
        if (stand && stand.fertig) {
            // The new backup belongs in the list; the server set a flash.
            window.location.assign('/admin/backups');
        }
    }

    async function ausfuehren(arbeit) {
        start.disabled = true;
        fortsetzen.disabled = true;
        verlauf.hidden = false;
        fehler.hidden = true;
        try {
            await arbeit();
        } catch (problem) {
            status.textContent = 'Abgebrochen.';
            fehler.textContent = 'Backup fehlgeschlagen: ' + problem.message
                + ' – „Laufendes Backup fortsetzen“ wiederholt den Schritt.';
            fehler.hidden = false;
            start.hidden = true;
            fortsetzen.hidden = false;
        } finally {
            start.disabled = false;
            fortsetzen.disabled = false;
        }
    }

    formular.addEventListener('submit', (ereignis) => {
        ereignis.preventDefault();
        ausfuehren(async () => {
            const stand = await senden('/admin/backups', new FormData(formular));
            await laufe(stand);
        });
    });

    fortsetzen.addEventListener('click', () => {
        ausfuehren(() => laufe({ phase: '', fertig: false }));
    });
}

if (typeof document !== 'undefined') {
    initBackups();
}

// Node (tests/js) loads the same file for the pure helpers above; browsers
// ignore this block because `module` does not exist there.
if (typeof module === 'object' && module.exports) {
    module.exports = { statusText, weiter };
}
