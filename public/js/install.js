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

/**
 * Status line for the page. The restore runs in two phases: the statements
 * of dump.sql first, then the encrypted files of the blob storage.
 */
function statusText(antwort) {
    if (antwort && antwort.fertig && zielNachAbschluss(antwort) !== null) {
        return 'Fertig. Das Backup ist eingespielt – weiter zum Zugang …';
    }
    if (antwort && antwort.fertig) {
        return 'Fertig. Das Backup ist eingespielt.';
    }
    if (!antwort || !(antwort.gesamt > 0)) {
        return 'Starte …';
    }
    if (antwort.phase === 'blobs') {
        return 'Datei ' + antwort.offset + ' von ' + antwort.gesamt + ' …';
    }

    // The dump phase counts bytes of dump.sql (issue #120): the total number
    // of statements is not known without reading the whole file, so the line
    // names the statements run so far and the share of the file.
    return 'Anweisung ' + (antwort.anweisungen || 0) + ' eingespielt ('
        + fortschrittProzent(antwort.offset, antwort.gesamt) + ' %) …';
}

/**
 * Where the page goes once the restore is through: the server names a
 * `weiter` path when config.php is not written yet because the backup had no
 * server key and the access step follows (docs/spec/06-betrieb.md section
 * 2, issue #154); null means the restore is complete.
 */
function zielNachAbschluss(antwort) {
    if (!antwort || !antwort.fertig || typeof antwort.weiter !== 'string' || antwort.weiter === '') {
        return null;
    }
    // Only a path on this host - never a destination the response could
    // point elsewhere.
    return antwort.weiter.startsWith('/') && !antwort.weiter.startsWith('//') ? antwort.weiter : null;
}

/**
 * The cron URL a finished restore answers with (issue #244): config.php got
 * a new cron token, so the hoster's cronjob needs the new address. Only an
 * http(s) URL is shown; anything else means there is none to show.
 */
function cronUrlAusAntwort(antwort) {
    if (!antwort || !antwort.fertig || typeof antwort.cron_url !== 'string') {
        return null;
    }

    return /^https?:\/\/[^\s]+$/.test(antwort.cron_url) ? antwort.cron_url : null;
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
        const ziel = zielNachAbschluss(antwort);
        if (ziel !== null) {
            const weiter = wurzel.querySelector('#wiederherstellung-weiter');
            if (weiter !== null) {
                weiter.href = ziel;
                weiter.textContent = 'Weiter: Zugang einrichten';
            }
            fertig.hidden = false;
            window.location.assign(ziel);
            return;
        }
        const cronUrl = cronUrlAusAntwort(antwort);
        const cron = wurzel.querySelector('#wiederherstellung-cron');
        const cronFeld = wurzel.querySelector('#wiederherstellung-cron-url');
        if (cronUrl !== null && cron !== null && cronFeld !== null) {
            cronFeld.value = cronUrl;
            cron.hidden = false;
        }
        fertig.hidden = false;
    } catch (problem) {
        status.textContent = 'Abgebrochen.';
        fehler.textContent = 'Wiederherstellung fehlgeschlagen: ' + problem.message;
        fehler.hidden = false;
    }
}

// Which of the two optional fieldsets applies for a chosen `modus`
// ("frisch"/"restore", the /install radio group): the other one is both
// hidden and disabled, so a browser's native `required` validation never
// blocks submitting the form over fields that do not apply, and nothing in
// the hidden fieldset is even sent (docs/spec/06-betrieb.md section 1).
function feldgruppenFuer(modus) {
    return {
        ersterZugangVerdeckt: modus !== 'frisch',
        backupVerdeckt: modus !== 'restore',
    };
}

function schalteFeldgruppe(element, verdeckt) {
    if (element === null) {
        return;
    }
    element.hidden = verdeckt;
    element.querySelectorAll('input, select').forEach((feld) => {
        feld.disabled = verdeckt;
    });
}

function wendeModusAn(modus) {
    const stand = feldgruppenFuer(modus);
    schalteFeldgruppe(document.querySelector('#erster-zugang'), stand.ersterZugangVerdeckt);
    schalteFeldgruppe(document.querySelector('#backup-upload'), stand.backupVerdeckt);
}

function initModusUmschalter() {
    const radios = document.querySelectorAll('input[name="modus"]');
    if (radios.length === 0) {
        return;
    }
    // The server already rendered the right hidden/disabled state for the
    // preselected radio (progressive enhancement); this only keeps it in
    // sync when the admin switches.
    radios.forEach((radio) => {
        radio.addEventListener('change', () => wendeModusAn(radio.value));
    });
}

/**
 * The recovery key page's "drucken" button - a real click handler and not
 * an inline onclick, which the CSP (script-src 'self' without
 * 'unsafe-inline', CLAUDE.md section 4) would silently drop.
 */
function initSchluesselDrucken() {
    const knopf = document.querySelector('#schluessel-drucken');
    if (knopf !== null) {
        knopf.addEventListener('click', () => window.print());
    }
}

if (typeof document !== 'undefined') {
    initRestore();
    initModusUmschalter();
    initSchluesselDrucken();
}

// Node (tests/js) loads the same file for the pure helpers above; browsers
// ignore this block because `module` does not exist there.
if (typeof module === 'object' && module.exports) {
    module.exports = { fortschrittProzent, statusText, feldgruppenFuer, zielNachAbschluss, cronUrlAusAntwort };
}
