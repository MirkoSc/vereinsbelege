// The Nextcloud filing of an export run (issue #95, docs/spec/
// 05-auswertung-und-export.md section 2 "Nextcloud-Ablage"): on the run page
// /app/export/lauf/{id} it drives the step chain - one
// `POST …/nextcloud/schritt` at a time, each filing a handful of files,
// until the server says `fertig`. No single request runs long (CLAUDE.md
// section 1). A closed tab simply stops; the page offers to go on.
//
// Its own file rather than an inline script: the CSP is script-src 'self'
// without 'unsafe-inline' (CLAUDE.md section 4). Token and counts come from
// data-* attributes on #nextcloud-ablage.

/**
 * What to do after a step, decided purely from its answer and the count
 * before it: 'fertig' (reload to show the result), 'weiter' (next step) or
 * 'stopp' (show the error and offer to go on). A step that moved nothing
 * although the chain still runs stops instead of repeating forever.
 */
function naechsteAktion(antwort, vorher) {
    if (!antwort || antwort.fehler) {
        return 'stopp';
    }
    if (antwort.status === 'fertig') {
        return 'fertig';
    }
    if (antwort.status !== 'laeuft') {
        return 'stopp';
    }

    return antwort.verarbeitet > vorher ? 'weiter' : 'stopp';
}

/** The progress line of the running filing. */
function fortschrittText(verarbeitet, gesamt) {
    if (gesamt > 0 && verarbeitet >= gesamt) {
        return 'Alle ' + gesamt + ' Dateien abgelegt – das Ergebnis wird geladen …';
    }

    return 'Wird abgelegt: ' + Math.min(verarbeitet, gesamt) + ' von ' + gesamt + ' Dateien.';
}

function initAblage(wurzel) {
    const csrf = wurzel.dataset.csrf || '';
    const lauf = wurzel.dataset.lauf || '';
    let gesamt = parseInt(wurzel.dataset.gesamt || '0', 10) || 0;
    let verarbeitet = parseInt(wurzel.dataset.verarbeitet || '0', 10) || 0;
    const stand = wurzel.querySelector('#nextcloud-stand');
    const balken = wurzel.querySelector('#nextcloud-fortschritt');
    const fehler = wurzel.querySelector('#nextcloud-fehler');
    const weiter = wurzel.querySelector('#nextcloud-weiter');

    async function schritt() {
        const antwort = await fetch('/app/export/lauf/' + encodeURIComponent(lauf) + '/nextcloud/schritt', {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrf },
        });
        let daten;
        try {
            daten = await antwort.json();
        } catch {
            daten = { fehler: 'Der Server hat nicht geantwortet (HTTP ' + antwort.status + ').' };
        }

        return antwort.ok || daten.fehler ? daten : { fehler: 'HTTP ' + antwort.status };
    }

    async function laufe() {
        fehler.hidden = true;
        weiter.hidden = true;

        for (;;) {
            let daten;
            try {
                daten = await schritt();
            } catch {
                daten = { fehler: 'Keine Verbindung zum Server.' };
            }

            const aktion = naechsteAktion(daten, verarbeitet);
            if (aktion === 'fertig') {
                stand.textContent = fortschrittText(daten.gesamt || gesamt, daten.gesamt || gesamt);
                window.location.reload();

                return;
            }
            if (aktion === 'stopp') {
                fehler.textContent = (daten && daten.fehler) || 'Die Ablage kam nicht weiter.';
                fehler.hidden = false;
                weiter.hidden = false;

                return;
            }

            verarbeitet = daten.verarbeitet;
            gesamt = daten.gesamt || gesamt;
            stand.textContent = fortschrittText(verarbeitet, gesamt);
            if (balken) {
                balken.max = Math.max(1, gesamt);
                balken.value = verarbeitet;
            }
        }
    }

    weiter.addEventListener('click', () => {
        laufe();
    });

    laufe();
}

if (typeof document !== 'undefined') {
    const wurzel = document.querySelector('#nextcloud-ablage');
    if (wurzel !== null && wurzel.dataset.status === 'laeuft') {
        initAblage(wurzel);
    }
}

// Node (tests/js) loads the same file for the pure helpers above; browsers
// ignore this block because `module` does not exist there.
if (typeof module === 'object' && module.exports) {
    module.exports = { naechsteAktion, fortschrittText };
}
