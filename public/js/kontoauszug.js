// Drives the step chain of a confirmed statement import on
// /app/konten/import/{id} (M9-4, issue #62, docs/spec/
// 04-bank-und-abgleich.md section 4): one `POST …/schritt` at a time, each
// writing a handful of bookings, until the server says `fertig`. No single
// request runs long (CLAUDE.md section 1). A closed tab simply stops; the
// page offers to continue where the import stands.
//
// Its own file rather than an inline script: the CSP is script-src 'self'
// without 'unsafe-inline' (CLAUDE.md section 4). The CSRF token and the
// counts come from data-* attributes on #kontoauszug.

/**
 * What to do after a step, decided purely from its answer and the count
 * before it: 'fertig' (reload to show the result), 'weiter' (next step) or
 * 'stopp' (show the error and offer to continue).
 *
 * A step that moved nothing although the import still runs stops the chain
 * instead of repeating it forever.
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

/** The progress line under the summary. */
function fortschrittText(verarbeitet, gesamt) {
    if (gesamt > 0 && verarbeitet >= gesamt) {
        return 'Alle ' + gesamt + ' Buchungen geprüft – das Ergebnis wird geladen …';
    }

    return 'Die Buchungen werden übernommen: ' + Math.min(verarbeitet, gesamt) + ' von ' + gesamt + '.';
}

function initKontoauszug() {
    const wurzel = document.querySelector('#kontoauszug');
    if (wurzel === null) {
        return;
    }

    const csrf = wurzel.dataset.csrf || '';
    const id = wurzel.dataset.id || '';
    const gesamt = parseInt(wurzel.dataset.gesamt || '0', 10) || 0;
    let verarbeitet = parseInt(wurzel.dataset.verarbeitet || '0', 10) || 0;
    const stand = wurzel.querySelector('#kontoauszug-stand');
    const fehler = wurzel.querySelector('#kontoauszug-fehler');
    const weiter = wurzel.querySelector('#kontoauszug-weiter');

    async function schritt() {
        const antwort = await fetch('/app/konten/import/' + encodeURIComponent(id) + '/schritt', {
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
                stand.textContent = fortschrittText(gesamt, gesamt);
                window.location.reload();

                return;
            }
            if (aktion === 'stopp') {
                fehler.textContent = 'Übernehmen unterbrochen: ' + (daten.fehler || 'kein Fortschritt.') +
                    ' Bereits übernommene Buchungen bleiben; „Fortsetzen“ macht dort weiter.';
                fehler.hidden = false;
                weiter.hidden = false;

                return;
            }

            verarbeitet = daten.verarbeitet;
            stand.textContent = fortschrittText(verarbeitet, gesamt);
        }
    }

    weiter.addEventListener('click', () => {
        laufe();
    });

    laufe();
}

if (typeof document !== 'undefined') {
    initKontoauszug();
}

// Node (tests/js) loads the same file for the pure helpers above; browsers
// ignore this block because `module` does not exist there.
if (typeof module === 'object' && module.exports) {
    module.exports = { naechsteAktion, fortschrittText };
}
