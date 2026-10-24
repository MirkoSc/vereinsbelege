// Builds the session's full-text index on /app/suche (issue #94): one
// request per step, repeated until the server says the index is up to date
// (docs/spec/03-erfassung-und-ki.md section 10). No single request runs
// long (CLAUDE.md section 1). Afterwards the search form is sent again, so
// the person gets the hits they asked for - the search terms themselves
// never leave the form (POST, no URL).
//
// Its own file rather than an inline script: the CSP is script-src 'self'
// without 'unsafe-inline' (CLAUDE.md section 4). The CSRF token comes from a
// data-* attribute on #suche-index, like the number of documents still open
// and the step size.

/** Extra steps beyond what the open documents need, for receipts that arrive meanwhile. */
const SPIELRAUM = 25;

/**
 * Upper bound of steps for a build that starts with `offen` documents, at
 * `jeSchritt` documents per step (the server's own number, data-schritt).
 */
function maxSchritte(offen, jeSchritt) {
    const groesse = Math.max(Number(jeSchritt) || 1, 1);

    return Math.ceil(Math.max(offen || 0, 0) / groesse) + SPIELRAUM;
}

/**
 * Whether to send another step after `antwort`: not when the index is up to
 * date, and not past the bound - a chain that never ends would mean
 * something is wrong, not that there is more to do.
 */
function weiter(antwort, schritte, grenze) {
    if (!antwort) {
        return true;
    }

    return !antwort.fertig && schritte < grenze;
}

/** The line while the index is built. */
function standText(offen) {
    const anzahl = Math.max(offen || 0, 0);
    if (anzahl === 0) {
        return 'Der Suchindex ist gleich fertig …';
    }

    return 'Der Suchindex dieser Sitzung wird aufgebaut – noch '
        + anzahl.toLocaleString('de-DE') + (anzahl === 1 ? ' Beleg' : ' Belege') + ' …';
}

function initSuche() {
    const wurzel = document.querySelector('#suche-index');
    if (wurzel === null) {
        return;
    }

    const csrf = wurzel.dataset.csrf || '';
    const erneut = wurzel.dataset.erneut === '1';
    const stand = wurzel.querySelector('#suche-stand');
    const fehler = wurzel.querySelector('#suche-fehler');
    const formular = document.querySelector('#suche-formular');

    async function schritt() {
        const antwort = await fetch('/app/suche/index', {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/x-www-form-urlencoded' },
            body: '',
        });
        let daten = null;
        try {
            daten = await antwort.json();
        } catch (problem) {
            // An HTML error page (maintenance, a fatal error) is no answer.
            throw new Error('HTTP ' + antwort.status);
        }

        if (!antwort.ok || daten.fehler) {
            throw new Error(daten.fehler || 'HTTP ' + antwort.status);
        }

        return daten;
    }

    async function laufe() {
        const grenze = maxSchritte(Number(wurzel.dataset.offen || '0'), wurzel.dataset.schritt);
        let antwort = null;
        let schritte = 0;
        while (weiter(antwort, schritte, grenze)) {
            antwort = await schritt();
            schritte += 1;
            stand.textContent = standText(antwort.offen);
        }
        if (!antwort.fertig) {
            throw new Error('Der Suchindex wird nicht fertig – bitte die Seite neu laden.');
        }

        if (erneut && formular !== null) {
            formular.submit();
        } else {
            window.location.assign('/app/suche');
        }
    }

    laufe().catch((problem) => {
        fehler.textContent = 'Suchindex konnte nicht aufgebaut werden: ' + problem.message;
        fehler.hidden = false;
    });
}

if (typeof document !== 'undefined') {
    initSuche();
}

// Node (tests/js) loads the same file for the pure helpers above; browsers
// ignore this block because `module` does not exist there.
if (typeof module === 'object' && module.exports) {
    module.exports = { maxSchritte, weiter, standText };
}
