// Drives the update step chain on /admin/update: one request per step
// (docs/spec/06-betrieb.md section 1), so no single request runs long.
//
// Its own file rather than an inline script: the CSP is script-src 'self'
// without 'unsafe-inline' (CLAUDE.md section 4). Everything the script needs
// - the CSRF token and the step order - comes from data-* attributes on the
// #update element, never from an inline block.

/**
 * The steps still to run after `abgeschlossen`. An unknown or missing value
 * means "nothing done yet", so the whole chain runs - which is also the
 * repair path: every step is idempotent, and redoing one is always allowed.
 */
function naechsteSchritte(alle, abgeschlossen) {
    const index = alle.indexOf(abgeschlossen);

    return index === -1 ? alle.slice() : alle.slice(index + 1);
}

/**
 * The chain starting AT `schritt`, i.e. what a retry runs: the failed step
 * itself plus everything after it. Every step is idempotent, so repeating
 * the one that failed is always safe.
 */
function abSchritt(alle, schritt) {
    const index = alle.indexOf(schritt);

    return index === -1 ? [] : alle.slice(index);
}

/** The last line of the step log - the admin page shows only that one. */
function letzteMeldung(state) {
    const meldungen = (state && state.meldungen) || [];

    return meldungen.length === 0 ? '' : meldungen[meldungen.length - 1];
}

function initUpdate() {
    const wurzel = document.querySelector('#update');
    if (wurzel === null) {
        return;
    }

    const csrf = wurzel.dataset.csrf || '';
    const alleSchritte = (wurzel.dataset.schritte || '').split(',').filter((s) => s !== '');

    const suchen = wurzel.querySelector('#update-suchen');
    const starten = wurzel.querySelector('#update-starten');
    const verlauf = wurzel.querySelector('#update-verlauf');
    const status = wurzel.querySelector('#update-status');
    const log = wurzel.querySelector('#update-log');
    const fehler = wurzel.querySelector('#update-fehler');
    const aktionen = wurzel.querySelector('#update-aktionen');
    const wiederholen = wurzel.querySelector('#update-wiederholen');
    const rollback = wurzel.querySelector('#update-rollback');

    let offeneSchritte = [];
    let fehlgeschlagenerSchritt = null;

    function notiere(text) {
        const eintrag = document.createElement('li');
        eintrag.textContent = text;
        log.appendChild(eintrag);
    }

    function zeigeFehler(text) {
        fehler.textContent = text;
        fehler.hidden = false;
        aktionen.hidden = false;
    }

    async function schritt(name) {
        const antwort = await fetch('/admin/update/schritt/' + name, {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrf },
        });
        const daten = await antwort.json();

        if (!antwort.ok) {
            throw new Error(daten.fehler || 'HTTP ' + antwort.status);
        }
        if (daten.fehler) {
            throw new Error(daten.fehler);
        }

        return daten;
    }

    async function laufe(schritte) {
        verlauf.hidden = false;
        fehler.hidden = true;
        aktionen.hidden = true;

        for (const name of schritte) {
            status.textContent = 'Schritt „' + name + '“ läuft …';
            try {
                const state = await schritt(name);
                notiere(name + ': ' + letzteMeldung(state));
            } catch (problem) {
                fehlgeschlagenerSchritt = name;
                status.textContent = 'Abgebrochen.';
                zeigeFehler('Schritt „' + name + '“ fehlgeschlagen: ' + problem.message);

                return;
            }
        }

        fehlgeschlagenerSchritt = null;
        status.textContent = 'Update abgeschlossen. Bitte die Seite neu laden.';
    }

    suchen.addEventListener('click', async () => {
        suchen.disabled = true;
        verlauf.hidden = false;
        fehler.hidden = true;
        status.textContent = 'Suche nach Updates …';

        try {
            const state = await schritt('check');
            notiere(letzteMeldung(state));

            if (state.ziel_version && !state.fertig) {
                offeneSchritte = naechsteSchritte(alleSchritte, 'check');
                starten.hidden = false;
                status.textContent = 'Update auf Version ' + state.ziel_version + ' bereit.';
            } else {
                status.textContent = 'Kein Update verfügbar.';
            }
        } catch (problem) {
            status.textContent = 'Abgebrochen.';
            zeigeFehler('Versionscheck fehlgeschlagen: ' + problem.message);
        } finally {
            suchen.disabled = false;
        }
    });

    starten.addEventListener('click', async () => {
        starten.disabled = true;
        await laufe(offeneSchritte);
        starten.disabled = false;
    });

    wiederholen.addEventListener('click', async () => {
        if (fehlgeschlagenerSchritt === null) {
            return;
        }
        await laufe(abSchritt(alleSchritte, fehlgeschlagenerSchritt));
    });

    rollback.addEventListener('click', async () => {
        await laufe(['rollback']);
    });
}

if (typeof document !== 'undefined') {
    initUpdate();
}

// Node (tests/js) loads the same file for the pure helpers above; browsers
// ignore this block because `module` does not exist there.
if (typeof module === 'object' && module.exports) {
    module.exports = { naechsteSchritte, abSchritt, letzteMeldung };
}
