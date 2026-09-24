// The session-worker (issue #29/M4-7, docs/spec/06-betrieb.md section 4):
// while a signed-in tab with rights for at least one job type is open, this
// drives `POST /api/jobs/step` - one job step per call - and shows how many
// jobs still wait in the header (#jobs, partials/kopf.php). Paused while the
// tab is in the background (Page Visibility API), just slower, not stopped:
// a step already in flight there should still land instead of piling up the
// moment the tab comes back.
//
// Its own file rather than an inline script: the CSP is script-src 'self'
// without 'unsafe-inline' (CLAUDE.md section 4). The CSRF token comes from a
// data-* attribute on #jobs, never from an inline block.

/** The header text, or '' to hide the badge (App\Service\Job\JobRunner::offen() === 0). */
function kopfText(offen) {
    if (!offen) {
        return '';
    }

    return offen === 1 ? '1 Beleg in Verarbeitung' : offen + ' Belege in Verarbeitung';
}

/**
 * How long to wait before the next step, in milliseconds - or `null` to
 * stop polling this tab altogether (defined background-tab behaviour, issue
 * #29/M4-7's acceptance criteria):
 *
 *   Antwort                    sichtbar   Hintergrund
 *   gearbeitet                 250 ms     30 s
 *   leer, offen > 0            10 s       60 s
 *   leer, offen = 0            30 s       120 s
 *   gesperrt, 401, 403         Stopp      Stopp
 *   Netz-/5xx-Fehler           30 s       120 s
 *
 * `gesperrt` (no unlocked vault) and 401/403 (not logged in / wrong CSRF)
 * both need a reload to recover from, not another request from this loop -
 * the same reasoning `gearbeitet`/`leer` don't apply to them.
 */
function wartezeit(status, offen, sichtbar) {
    if (status === 'gesperrt' || status === 'anmeldung') {
        return null;
    }
    if (status === 'gearbeitet') {
        return sichtbar ? 250 : 30000;
    }
    if (status === 'fehler') {
        return sichtbar ? 30000 : 120000;
    }
    // status === 'leer'
    return offen > 0 ? (sichtbar ? 10000 : 60000) : (sichtbar ? 30000 : 120000);
}

function initJobs() {
    const wurzel = document.querySelector('#jobs');
    if (wurzel === null) {
        return;
    }

    const csrf = wurzel.dataset.csrf || '';
    let offen = parseInt(wurzel.dataset.offen || '0', 10) || 0;
    let timer = null;

    function zeige(anzahl) {
        offen = anzahl;
        wurzel.textContent = kopfText(offen);
        wurzel.hidden = offen === 0;
    }

    function sichtbar() {
        return document.visibilityState !== 'hidden';
    }

    async function schritt() {
        let status;
        try {
            const antwort = await fetch('/api/jobs/step', {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/x-www-form-urlencoded' },
                body: '',
            });

            if (antwort.status === 401 || antwort.status === 403) {
                status = 'anmeldung';
            } else if (!antwort.ok) {
                status = 'fehler';
            } else {
                const daten = await antwort.json();
                status = daten.status;
                if (typeof daten.offen === 'number') {
                    zeige(daten.offen);
                }
            }
        } catch {
            status = 'fehler';
        }

        plane(wartezeit(status, offen, sichtbar()));
    }

    function plane(verzoegerung) {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
        if (verzoegerung === null) {
            return;
        }
        timer = setTimeout(schritt, verzoegerung);
    }

    // Coming back from the background: drop the slow timer and act at once
    // instead of waiting out whatever interval was running before.
    document.addEventListener('visibilitychange', () => {
        if (sichtbar() && timer !== null) {
            clearTimeout(timer);
            timer = null;
            schritt();
        }
    });

    zeige(offen);
    plane(sichtbar() ? 250 : 30000);
}

if (typeof document !== 'undefined') {
    initJobs();
}

// Node (tests/js) loads the same file for the pure helpers above; browsers
// ignore this block because `module` does not exist there.
if (typeof module === 'object' && module.exports) {
    module.exports = { kopfText, wartezeit };
}
