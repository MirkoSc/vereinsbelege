// Drives the re-sealing step chain on /admin/tresor/rotation (issue #90,
// docs/spec/01-sicherheit.md section 2 "Schlüsselrotation"): one short
// request per step, repeated until the server says nothing is left
// (CLAUDE.md section 1). Start, confirmation and finish are plain forms.
//
// Its own file rather than an inline script: the CSP is script-src 'self'
// without 'unsafe-inline' (CLAUDE.md section 4). The CSRF token comes from a
// data-* attribute on #rotation.

/** Steps in a row without progress before the chain gives up. */
const OHNE_FORTSCHRITT_MAX = 3;

/**
 * Whether to send another step, from the last answer and how many steps in
 * a row made no progress. Stops when everything is re-sealed, and when the
 * count has not moved for a while - repeating then would only loop.
 */
function weiter(stand, ohneFortschritt) {
    if (!stand) {
        return true;
    }
    if (stand.umgeschluesselt) {
        return false;
    }

    return ohneFortschritt < OHNE_FORTSCHRITT_MAX;
}

/** The line under the heading while the chain runs. */
function standText(stand) {
    if (!stand) {
        return '';
    }
    if (stand.umgeschluesselt) {
        return stand.defekt > 0
            ? 'Umgeschlüsselt. ' + stand.defekt + ' Datensätze waren schon vorher nicht lesbar.'
            : 'Alle Datenschlüssel sind umgestellt.';
    }

    return 'Noch ' + stand.offen + ' Datensätze mit dem alten Schlüssel …';
}

function initRotation() {
    const drucken = document.querySelector('#rotation-drucken');
    if (drucken !== null) {
        drucken.addEventListener('click', () => window.print());
    }

    const wurzel = document.querySelector('#rotation');
    if (wurzel === null) {
        return;
    }
    const csrf = wurzel.dataset.csrf || '';
    const anzeige = wurzel.querySelector('#rotation-stand');
    const fehler = wurzel.querySelector('#rotation-fehler');
    const start = wurzel.querySelector('#rotation-start');

    async function schritt() {
        const antwort = await fetch('/admin/tresor/rotation/schritt', {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrf },
        });
        const daten = await antwort.json();
        if (!antwort.ok || daten.fehler) {
            throw new Error(daten.fehler || 'HTTP ' + antwort.status);
        }

        return daten;
    }

    start.addEventListener('click', async () => {
        start.disabled = true;
        fehler.hidden = true;
        let stand = null;
        let ohneFortschritt = 0;
        try {
            while (weiter(stand, ohneFortschritt)) {
                const vorher = stand === null ? null : stand.offen;
                stand = await schritt();
                ohneFortschritt = vorher !== null && stand.offen >= vorher ? ohneFortschritt + 1 : 0;
                anzeige.textContent = standText(stand);
            }
            if (!stand.umgeschluesselt) {
                throw new Error('Es geht nicht weiter – bitte die Seite neu laden und erneut starten.');
            }
            // The finish button and the counters are rendered by the server.
            window.location.reload();
        } catch (problem) {
            fehler.textContent = 'Umschlüsseln unterbrochen: ' + problem.message;
            fehler.hidden = false;
            start.disabled = false;
        }
    });
}

if (typeof document !== 'undefined') {
    initRotation();
}

// Node (tests/js) loads the same file for the pure helpers above.
if (typeof module === 'object' && module.exports) {
    module.exports = { weiter, standText, OHNE_FORTSCHRITT_MAX };
}
