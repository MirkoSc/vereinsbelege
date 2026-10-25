// Drives the code integrity check on /admin/integritaet (issue #79/M13-2):
// one request starts the check, then one request per step until the server
// reports it finished (docs/spec/01-sicherheit.md section 8). No single
// request runs long. The result itself is rendered by the page after a
// reload - the step answers carry no paths.
//
// Its own file rather than an inline script: the CSP is script-src 'self'
// without 'unsafe-inline' (CLAUDE.md section 4). The CSRF token comes from a
// data-* attribute on the form, never from an inline block.

/** Upper bound of steps, so a server that never says "fertig" cannot loop forever. */
const MAX_SCHRITTE = 10000;

/** The status line for a state answered by the server. */
function statusText(stand) {
    if (!stand) {
        return '';
    }
    if (stand.fertig) {
        return 'Prüfung abgeschlossen.';
    }
    if (stand.phase === 'zusaetzlich') {
        return 'Suche nach zusätzlichen Dateien …';
    }
    if (stand.phase === 'umgebung') {
        return 'Prüfe Web-Verzeichnis und Konfiguration …';
    }
    const gesamt = stand.gesamt || 0;
    const geprueft = Math.min(stand.geprueft || 0, gesamt);

    return gesamt === 0
        ? 'Prüfung läuft …'
        : 'Dateien geprüft: ' + geprueft + ' von ' + gesamt + ' …';
}

/** Whether the chain needs another step after this answer. */
function weiter(stand) {
    return !!stand && !stand.fertig;
}

function initIntegritaet() {
    const formular = document.querySelector('#integritaet-pruefen');
    if (formular === null) {
        return;
    }

    const csrf = formular.dataset.csrf || '';
    const start = formular.querySelector('#integritaet-start');
    const fortsetzen = formular.querySelector('#integritaet-fortsetzen');
    const verlauf = document.querySelector('#integritaet-verlauf');
    const status = document.querySelector('#integritaet-status');
    const fehler = document.querySelector('#integritaet-fehler');

    async function senden(pfad) {
        const daten = new FormData();
        daten.append('_csrf', csrf);
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
        status.textContent = statusText(stand) || 'Prüfung läuft …';
        while (weiter(stand) && schritte < MAX_SCHRITTE) {
            stand = await senden('/admin/integritaet/schritt');
            status.textContent = statusText(stand);
            schritte++;
        }
        if (stand && stand.fertig) {
            window.location.assign('/admin/integritaet');
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
            fehler.textContent = 'Prüfung fehlgeschlagen: ' + problem.message
                + ' – „Laufende Prüfung fortsetzen“ wiederholt den Schritt.';
            fehler.hidden = false;
            fortsetzen.hidden = false;
        } finally {
            start.disabled = false;
            fortsetzen.disabled = false;
        }
    }

    formular.addEventListener('submit', (ereignis) => {
        ereignis.preventDefault();
        ausfuehren(async () => {
            const stand = await senden('/admin/integritaet');
            await laufe(stand);
        });
    });

    fortsetzen.addEventListener('click', () => {
        ausfuehren(() => laufe({ phase: '', fertig: false }));
    });
}

if (typeof document !== 'undefined') {
    initIntegritaet();
}

// Node (tests/js) loads the same file for the pure helpers above; browsers
// ignore this block because `module` does not exist there.
if (typeof module === 'object' && module.exports) {
    module.exports = { statusText, weiter };
}
