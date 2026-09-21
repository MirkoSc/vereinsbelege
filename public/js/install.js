// Drives the backup restore of /install: one request per block of SQL
// statements (docs/spec/06-betrieb.md section 2), so no single request runs
// long (CLAUDE.md section 1).
//
// Its own file rather than an inline script: the CSP is script-src 'self'
// without 'unsafe-inline' (CLAUDE.md section 4). The CSRF token comes from a
// data-* attribute on #wiederherstellung.

/** Progress in whole percent, never above 100, 100 for an empty dump. */
function fortschrittProzent(offset, gesamt) {
    if (!(gesamt > 0)) {
        return 100;
    }

    return Math.min(100, Math.floor((offset / gesamt) * 100));
}

/** Status line for the page. */
function statusText(antwort) {
    if (antwort && antwort.fertig) {
        return 'Fertig. Das Backup ist eingespielt.';
    }
    if (!antwort || !(antwort.gesamt > 0)) {
        return 'Starte …';
    }

    return 'Anweisung ' + antwort.offset + ' von ' + antwort.gesamt + ' …';
}

async function restoreStep(csrf) {
    const antwort = await fetch('/install/wiederherstellen', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrf },
    });
    const daten = await antwort.json();

    if (!antwort.ok || daten.fehler) {
        throw new Error(daten.fehler || 'HTTP ' + antwort.status);
    }

    return daten;
}

async function initRestore() {
    const wurzel = document.querySelector('#wiederherstellung');
    if (wurzel === null) {
        return;
    }

    const csrf = wurzel.dataset.csrf || '';
    const status = wurzel.querySelector('#wiederherstellung-status');
    const balken = wurzel.querySelector('#wiederherstellung-fortschritt');
    const fehler = wurzel.querySelector('#wiederherstellung-fehler');
    const fertig = wurzel.querySelector('#wiederherstellung-fertig');

    try {
        let antwort = { fertig: false };
        // The server keeps the offset in the session, so a repeated call
        // after a lost response simply continues where it stopped.
        while (!antwort.fertig) {
            antwort = await restoreStep(csrf);
            balken.value = fortschrittProzent(antwort.offset, antwort.gesamt);
            status.textContent = statusText(antwort);
        }
        balken.value = 100;
        fertig.hidden = false;
    } catch (problem) {
        status.textContent = 'Abgebrochen.';
        fehler.textContent = 'Wiederherstellung fehlgeschlagen: ' + problem.message;
        fehler.hidden = false;
    }
}

if (typeof document !== 'undefined') {
    initRestore();
}

// Node (tests/js) loads the same file for the pure helpers above; browsers
// ignore this block because `module` does not exist there.
if (typeof module === 'object' && module.exports) {
    module.exports = { fortschrittProzent, statusText };
}
