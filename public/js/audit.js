// Drives the integrity check of the audit log on /app/audit (M3-8, issue
// #21): one request per stretch of the hash chain, repeated until the server
// says it is through or found a break (docs/spec/01-sicherheit.md section 6).
// No single request runs long.
//
// Its own file rather than an inline script: the CSP is script-src 'self'
// without 'unsafe-inline' (CLAUDE.md section 4). The CSRF token comes from a
// data-* attribute on #audit-pruefung, never from an inline block.

/**
 * Where the next request starts, decided purely from the answer of the last
 * one: null when the check is over, the id to continue after otherwise.
 * An answer that checked nothing and is not finished would loop forever, so
 * it ends the chain as well.
 */
function naechsterSchritt(state) {
    if (!state) {
        return 0;
    }
    if (state.fertig || state.geprueft === 0) {
        return null;
    }

    return state.nach_id;
}

/** The line while the check walks the chain. */
function pruefText(geprueft, gesamt) {
    const anzahl = Math.max(gesamt || 0, geprueft || 0);

    return (geprueft || 0) + ' von ' + anzahl + ' Einträgen geprüft …';
}

/**
 * The control value in groups of eight: easier to copy down by hand, and it
 * wraps on a 360 px screen instead of pushing the page sideways.
 */
function kontrollwert(hex) {
    return (hex.match(/.{1,8}/g) || []).join(' ');
}

/**
 * The outcome: `{ ok, text }`. `geprueft` is the running total over all
 * steps, `state` the last answer.
 */
function ergebnis(geprueft, state) {
    if (state.bruch) {
        return {
            ok: false,
            text: 'Die Kette ist bei Eintrag Nr. ' + state.bruch.id + ' unterbrochen: ' + state.bruch.meldung
                + ' ' + geprueft + ' Einträge davor sind unverändert.',
        };
    }
    if (!state.kopf) {
        return { ok: true, text: 'Das Protokoll ist noch leer – nichts zu prüfen.' };
    }

    return {
        ok: true,
        text: 'Alle ' + geprueft + ' Einträge sind unverändert. Kontrollwert (letzter Eintrag Nr. '
            + state.nach_id + '): ' + kontrollwert(state.kopf),
    };
}

function initAudit() {
    const wurzel = document.querySelector('#audit-pruefung');
    if (wurzel === null) {
        return;
    }

    const csrf = wurzel.dataset.csrf || '';
    const knopf = wurzel.querySelector('#audit-pruefen');
    const stand = wurzel.querySelector('#audit-stand');
    const ausgabe = wurzel.querySelector('#audit-ergebnis');

    function zeige(ok, text) {
        ausgabe.textContent = text;
        ausgabe.classList.remove('hinweis-ok', 'hinweis-fehler');
        ausgabe.classList.add(ok ? 'hinweis-ok' : 'hinweis-fehler');
        ausgabe.hidden = false;
    }

    async function schritt(nachId) {
        const antwort = await fetch('/app/audit/pruefen', {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ nach_id: String(nachId) }),
        });
        const daten = await antwort.json();

        if (!antwort.ok || daten.fehler) {
            throw new Error(daten.fehler || 'HTTP ' + antwort.status);
        }

        return daten;
    }

    async function laufe() {
        ausgabe.hidden = true;

        let state = null;
        let geprueft = 0;
        for (;;) {
            const nachId = naechsterSchritt(state);
            if (nachId === null) {
                break;
            }
            state = await schritt(nachId);
            geprueft += state.geprueft;
            stand.textContent = pruefText(geprueft, state.gesamt);
        }

        stand.textContent = '';
        const aus = ergebnis(geprueft, state);
        zeige(aus.ok, aus.text);
    }

    knopf.addEventListener('click', async () => {
        knopf.disabled = true;
        try {
            await laufe();
        } catch (problem) {
            zeige(false, 'Integritätsprüfung abgebrochen: ' + problem.message);
        } finally {
            knopf.disabled = false;
        }
    });
}

if (typeof document !== 'undefined') {
    initAudit();
}

// Node (tests/js) loads the same file for the pure helpers above; browsers
// ignore this block because `module` does not exist there.
if (typeof module === 'object' && module.exports) {
    module.exports = { naechsterSchritt, pruefText, ergebnis, kontrollwert };
}
