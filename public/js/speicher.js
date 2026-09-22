// Drives the storage step chain on /admin/speicher (M2-5, issue #12): one
// request per step, repeated until the server says there is nothing left
// (docs/spec/02-datenmodell.md "Dateien"). No single request runs long.
//
// Its own file rather than an inline script: the CSP is script-src 'self'
// without 'unsafe-inline' (CLAUDE.md section 4). The CSRF token comes from a
// data-* attribute on #speicher, never from an inline block.

/**
 * What to do next during the move, decided purely from the answer of the
 * last step.
 *
 * `verschieben` repeats while blobs are open AND the last request actually
 * moved something. A request that moved nothing although blobs are open
 * means every remaining one failed - repeating it would loop forever, so the
 * chain stops and the page says so.
 */
function naechsterSchritt(state) {
    if (!state) {
        return 'verschieben';
    }
    if (state.offen > 0) {
        return state.verschoben > 0 ? 'verschieben' : null;
    }

    return 'aufraeumen';
}

/** The line under the buttons while the move runs. */
function umzugText(state) {
    if (!state) {
        return '';
    }
    if (state.offen === 0) {
        return 'Alle Dateien liegen im Ziel-Backend.';
    }

    const gesamt = state.gesamt || 0;
    const fertig = Math.max(gesamt - state.offen, 0);

    return fertig + ' von ' + gesamt + ' Dateien verschoben.';
}

/** The line while the integrity check walks the table. */
function pruefText(pruefung) {
    if (!pruefung) {
        return '';
    }

    const gesamt = Math.max(pruefung.gesamt || 0, pruefung.geprueft || 0);
    const beschaedigt = (pruefung.beschaedigt || []).length;
    const stand = (pruefung.geprueft || 0) + ' von ' + gesamt + ' Dateien geprüft';

    if (!pruefung.fertig) {
        return stand + ' …';
    }

    return beschaedigt === 0
        ? stand + ' – ohne Befund.'
        : stand + ' – ' + beschaedigt + ' beschädigt.';
}

/** What the page says about blobs the chain could not move. */
function misslungenText(misslungen) {
    const ids = misslungen || [];
    const satz =
        ids.length === 1
            ? '1 Datei konnte nicht verschoben werden (ID: ' + ids[0] + ') und liegt'
            : ids.length + ' Dateien konnten nicht verschoben werden (IDs: ' + ids.join(', ') + ') und liegen';

    return satz + ' unverändert im alten Backend; der Rest ist umgezogen.';
}

function initSpeicher() {
    const wurzel = document.querySelector('#speicher');
    if (wurzel === null) {
        return;
    }

    const csrf = wurzel.dataset.csrf || '';
    const stand = wurzel.querySelector('#speicher-stand');
    const log = wurzel.querySelector('#speicher-log');
    const fehler = wurzel.querySelector('#speicher-fehler');
    const umzug = wurzel.querySelector('#speicher-umzug');
    const pruefen = wurzel.querySelector('#speicher-pruefen');

    function notiere(text) {
        const eintrag = document.createElement('li');
        eintrag.textContent = text;
        log.appendChild(eintrag);
    }

    function zeigeFehler(text) {
        fehler.textContent = text;
        fehler.hidden = false;
    }

    async function schritt(name) {
        const antwort = await fetch('/admin/speicher/schritt/' + name, {
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

    /**
     * A finished run reloads the page instead of patching the numbers on it:
     * the inventory table, the check card and the counters are rendered by
     * the server, and one source for them is worth more than a second,
     * half-updated one in here. A run that stopped on a problem does NOT
     * reload - its error message and log are what the admin needs to read.
     */
    function neuLaden() {
        window.location.reload();
    }

    async function laufeUmzug() {
        fehler.hidden = true;

        let state = null;
        for (;;) {
            const name = naechsterSchritt(state);
            if (name === null) {
                // Even a run that got stuck tidies up after itself: the
                // blobs that DID move may have left a copy behind.
                notiere((await schritt('aufraeumen')).meldung);
                zeigeFehler(misslungenText(state.misslungen));

                return;
            }

            state = await schritt(name);
            stand.textContent = umzugText(state);
            notiere(state.meldung);

            if (name === 'aufraeumen') {
                neuLaden();

                return;
            }
        }
    }

    async function laufePruefung() {
        fehler.hidden = true;

        let state = await schritt('pruefstart');
        while (!state.pruefung.fertig) {
            state = await schritt('pruefen');
            stand.textContent = pruefText(state.pruefung);
        }

        notiere(state.meldung);
        neuLaden();
    }

    umzug.addEventListener('click', async () => {
        umzug.disabled = true;
        pruefen.disabled = true;
        try {
            await laufeUmzug();
        } catch (problem) {
            zeigeFehler('Umzug abgebrochen: ' + problem.message);
        } finally {
            umzug.disabled = false;
            pruefen.disabled = false;
        }
    });

    pruefen.addEventListener('click', async () => {
        umzug.disabled = true;
        pruefen.disabled = true;
        try {
            await laufePruefung();
        } catch (problem) {
            zeigeFehler('Integritätsprüfung abgebrochen: ' + problem.message);
        } finally {
            umzug.disabled = false;
            pruefen.disabled = false;
        }
    });
}

if (typeof document !== 'undefined') {
    initSpeicher();
}

// Node (tests/js) loads the same file for the pure helpers above; browsers
// ignore this block because `module` does not exist there.
if (typeof module === 'object' && module.exports) {
    module.exports = { naechsterSchritt, umzugText, pruefText, misslungenText };
}
