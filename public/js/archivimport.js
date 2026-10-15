// The archive import /app/archiv-import (issue #77/M12-3, docs/spec/
// 05-auswertung-und-export.md section 3), two jobs:
//
// - On the start page it uploads the ZIP through the chunk upload
//   (dateiHochladen() from public/js/upload.js, loaded before this file)
//   under /app/archiv-import/upload and then opens the preview the server
//   names.
// - On a running import it drives the step chain: one `POST …/schritt` at a
//   time, each importing a handful of files, until the server says
//   `fertig`. No single request runs long (CLAUDE.md section 1). A closed
//   tab simply stops; the page offers to continue where the import stands.
//
// Its own file rather than an inline script: the CSP is script-src 'self'
// without 'unsafe-inline' (CLAUDE.md section 4). Tokens and counts come from
// data-* attributes.

/** Is the chosen file one the server takes at all? null when fine, else the German reason. */
function dateiPruefen(datei, maxBytes) {
    if (!datei) {
        return 'Bitte eine ZIP-Datei wählen.';
    }
    if (!/\.zip$/i.test(datei.name || '')) {
        return 'Bitte eine ZIP-Datei wählen (Endung .zip).';
    }
    if (!(datei.size > 0)) {
        return 'Die Datei ist leer.';
    }
    if (maxBytes > 0 && datei.size > maxBytes) {
        return 'Die Datei ist zu groß – bitte das Archiv in mehrere ZIPs aufteilen (z. B. je Jahr).';
    }

    return null;
}

/**
 * What to do after a step, decided purely from its answer and the count
 * before it: 'fertig' (reload to show the result), 'weiter' (next step) or
 * 'stopp' (show the error and offer to continue). A step that moved nothing
 * although the import still runs stops the chain instead of repeating it
 * forever; an import cut off on the server (`abgebrochen`) reloads too, the
 * page then explains why.
 */
function naechsteAktion(antwort, vorher) {
    if (!antwort || antwort.fehler) {
        return 'stopp';
    }
    if (antwort.status === 'fertig' || antwort.status === 'abgebrochen') {
        return 'fertig';
    }
    if (antwort.status !== 'laeuft') {
        return 'stopp';
    }

    return antwort.verarbeitet > vorher ? 'weiter' : 'stopp';
}

/** The progress line of the running import. */
function fortschrittText(verarbeitet, gesamt, belege) {
    if (gesamt > 0 && verarbeitet >= gesamt) {
        return 'Alle ' + gesamt + ' Einträge verarbeitet – das Ergebnis wird geladen …';
    }

    return 'Das Archiv wird übernommen: ' + Math.min(verarbeitet, gesamt) + ' von ' + gesamt + ' Einträgen, ' +
        belege + (belege === 1 ? ' Beleg' : ' Belege') + ' angelegt.';
}

function initUpload(formular) {
    const csrf = formular.dataset.csrf || '';
    const maxBytes = parseInt(formular.dataset.max || '0', 10) || 0;
    const eingabe = formular.querySelector('#archivimport-datei');
    const stand = formular.querySelector('#archivimport-stand');
    const fehler = formular.querySelector('#archivimport-fehler');
    const knopf = formular.querySelector('#archivimport-senden');

    formular.addEventListener('submit', async (ereignis) => {
        ereignis.preventDefault();
        fehler.hidden = true;

        const datei = eingabe.files && eingabe.files[0];
        const problem = dateiPruefen(datei, maxBytes);
        if (problem !== null) {
            fehler.textContent = problem;
            fehler.hidden = false;

            return;
        }

        knopf.disabled = true;
        stand.hidden = false;
        stand.textContent = 'Wird hochgeladen: 0 %';
        try {
            const antwort = await dateiHochladen(datei, {
                csrf: csrf,
                basis: '/app/archiv-import/upload',
                onFortschritt: (prozent) => {
                    stand.textContent = prozent < 100 ? 'Wird hochgeladen: ' + prozent + ' %' : 'Hochgeladen – das ZIP wird geprüft …';
                },
            });
            window.location.assign(antwort.weiter);
        } catch (problem) {
            stand.hidden = true;
            fehler.textContent = problem && problem.message ? problem.message : 'Der Upload ist fehlgeschlagen.';
            fehler.hidden = false;
            knopf.disabled = false;
        }
    });
}

function initSchritte(wurzel) {
    const csrf = wurzel.dataset.csrf || '';
    const id = wurzel.dataset.id || '';
    const gesamt = parseInt(wurzel.dataset.gesamt || '0', 10) || 0;
    let verarbeitet = parseInt(wurzel.dataset.verarbeitet || '0', 10) || 0;
    const stand = wurzel.querySelector('#archivimport-stand');
    const balken = wurzel.querySelector('#archivimport-fortschritt');
    const fehler = wurzel.querySelector('#archivimport-fehler');
    const weiter = wurzel.querySelector('#archivimport-weiter');

    async function schritt() {
        const antwort = await fetch('/app/archiv-import/' + encodeURIComponent(id) + '/schritt', {
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
                stand.textContent = fortschrittText(gesamt, gesamt, daten.belege || 0);
                window.location.reload();

                return;
            }
            if (aktion === 'stopp') {
                fehler.textContent = 'Import unterbrochen: ' + (daten.fehler || 'kein Fortschritt.') +
                    ' Bereits übernommene Belege bleiben; „Fortsetzen“ macht dort weiter.';
                fehler.hidden = false;
                weiter.hidden = false;

                return;
            }

            verarbeitet = daten.verarbeitet;
            stand.textContent = fortschrittText(verarbeitet, gesamt, daten.belege || 0);
            if (balken) {
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
    const formular = document.querySelector('#archivimport-upload');
    if (formular !== null) {
        initUpload(formular);
    }
    const wurzel = document.querySelector('#archivimport');
    if (wurzel !== null) {
        initSchritte(wurzel);
    }
}

// Node (tests/js) loads the same file for the pure helpers above; browsers
// ignore this block because `module` does not exist there.
if (typeof module === 'object' && module.exports) {
    module.exports = { dateiPruefen, naechsteAktion, fortschrittText };
}
