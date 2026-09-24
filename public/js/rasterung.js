// The `render_pages` browser job (issue #30/M4-8, docs/spec/03-erfassung-
// und-ki.md section 3, docs/spec/06-betrieb.md section 4): while a signed-in
// tab with `document.edit` has the inbox open, this claims a PDF original
// that needs page images, renders it with pdf.js and uploads the pages. Only
// on the inbox pages (App\App\InboxController::rasterungDaten()) - unlike
// public/js/jobs.js, which polls every authenticated page for session jobs.
//
// Its own file rather than an inline script: the CSP is script-src 'self'
// without 'unsafe-inline' (CLAUDE.md section 4). pdf.js itself is loaded by
// dynamic import() from public/js/vendor/pdfjs/ (also 'self') only once a
// task actually needs it, not on every page load.

/** 150 DPI against a PDF's 72-points-per-inch coordinate space. */
const ZIEL_DPI_SKALA = 150 / 72;

/** Matches App\Service\Document\PdfRasterung::MAX_PIXEL. */
const MAX_PIXEL = 2000;

/** Matches App\Service\Document\PdfRasterung::MAX_BYTES. */
const MAX_BYTES = 2 * 1024 * 1024;

/** Retried in order until a page fits MAX_BYTES; a document that does not
 * even at the lowest is treated as broken (abbruch, grund "defekt"). */
const QUALITAETSSTUFEN = [0.85, 0.7, 0.5];

/**
 * The render scale for a page of this size (PDF points, 72/inch): 150 DPI,
 * capped so the longest side never exceeds MAX_PIXEL - a receipt scanned at
 * a high DPI must not turn into a multi-megabyte page image.
 */
function massstab(breitePt, hoehePt) {
    if (!(breitePt > 0) || !(hoehePt > 0)) {
        return ZIEL_DPI_SKALA;
    }

    const laengsteSeite = Math.max(breitePt, hoehePt);

    return Math.min(ZIEL_DPI_SKALA, MAX_PIXEL / laengsteSeite);
}

/**
 * How long to wait before asking for the next task, in ms - or `null` to
 * stop polling this tab altogether. Deliberately coarser than
 * public/js/jobs.js's `wartezeit`: rendering a page takes real time, so
 * there is no "gearbeitet" case to answer near-instantly - every non-final
 * outcome (a page stored, a whole job done, a stale task, a broken file)
 * just means "ask again soon".
 *
 *   Antwort                    sichtbar   Hintergrund
 *   leer                       15 s       60 s
 *   gesperrt, anmeldung        Stopp      Stopp
 *   fehler (Netz/5xx)          30 s       120 s
 *   alles andere               250 ms     30 s
 */
function wartezeitRaster(status, sichtbar) {
    if (status === 'gesperrt' || status === 'anmeldung') {
        return null;
    }
    if (status === 'leer') {
        return sichtbar ? 15000 : 60000;
    }
    if (status === 'fehler') {
        return sichtbar ? 30000 : 120000;
    }

    return sichtbar ? 250 : 30000;
}

/**
 * Renders and uploads every page of every source of one claimed job, in one
 * pass: the server's `ok` answer names where to continue
 * (App\Service\Document\SeiteErgebnis) because the job's lock stays held
 * across pages (App\Repository\JobRepository::fortschritt(), unlike a
 * session job's schrittErledigt()) - a fresh naechste() call mid-job would
 * find nothing reclaimable and stall. Stops the moment the answer is not
 * `ok`: `fertig` (the whole job is done), a stale task (`unerwartet`,
 * `verloren`), or a page that did not fit (`ungueltiger_typ`, `zu_gross`).
 *
 * `umgebung.ladeQuelle(job, lock, quelleIndex)` abstracts pdf.js AND the
 * canvas entirely - the same way public/js/upload.js's `dateiHochladen`
 * takes an injectable `fetch`, so this is testable without either. It
 * resolves to `{ numPages, seite(nummer, qualitaet) }`, where `seite()`
 * resolves to the rendered page's JPEG bytes; called again only when the
 * server names a different source than the one currently open.
 */
async function verarbeiteJob(aufgabe, umgebung) {
    const holen = umgebung.fetch;
    const basis = '/api/rasterung/' + aufgabe.job + '/' + aufgabe.lock;

    let quelle = aufgabe.quelle;
    let seite = aufgabe.seite;
    let dokument;
    try {
        dokument = await umgebung.ladeQuelle(aufgabe.job, aufgabe.lock, quelle);
    } catch (fehler) {
        return await abbrechenWegen(fehler, basis, umgebung);
    }

    for (;;) {
        let bytes = null;
        for (const qualitaet of QUALITAETSSTUFEN) {
            const versuch = await dokument.seite(seite, qualitaet);
            if (versuch.byteLength <= MAX_BYTES) {
                bytes = versuch;
                break;
            }
        }

        if (bytes === null) {
            return await abbrechenWegen(null, basis, umgebung);
        }

        const antwort = await holen(basis + '/seite/' + quelle + '/' + seite + '/' + dokument.numPages, {
            method: 'POST',
            headers: { 'X-CSRF-Token': umgebung.csrf, 'Content-Type': 'application/octet-stream' },
            body: bytes,
        });
        const daten = await antwort.json();
        if (daten.status !== 'ok') {
            return daten;
        }

        if (daten.quelle !== quelle) {
            quelle = daten.quelle;
            try {
                dokument = await umgebung.ladeQuelle(aufgabe.job, aufgabe.lock, quelle);
            } catch (fehler) {
                return await abbrechenWegen(fehler, basis, umgebung);
            }
        }
        seite = daten.seite;
    }
}

/** Reports a broken source (no page fit even at the lowest quality, or
 * pdf.js could not open it at all) and gives up on this job. */
async function abbrechenWegen(fehler, basis, umgebung) {
    const grund = fehler && fehler.name === 'PasswordException' ? 'passwort' : 'defekt';
    await umgebung.fetch(basis + '/abbruch', {
        method: 'POST',
        headers: { 'X-CSRF-Token': umgebung.csrf, 'Content-Type': 'application/json' },
        body: JSON.stringify({ grund: grund }),
    });

    return { status: grund };
}

/** One `/api/rasterung/naechste` call, mapped to the same vocabulary
 * public/js/jobs.js uses for the equivalent cases. */
async function naechsteAufgabe(umgebung) {
    let antwort;
    try {
        antwort = await umgebung.fetch('/api/rasterung/naechste', {
            method: 'POST',
            headers: { 'X-CSRF-Token': umgebung.csrf, 'Content-Type': 'application/x-www-form-urlencoded' },
            body: '',
        });
    } catch {
        return { status: 'fehler' };
    }

    if (antwort.status === 401 || antwort.status === 403) {
        return { status: 'anmeldung' };
    }
    if (!antwort.ok) {
        return { status: 'fehler' };
    }

    return await antwort.json();
}

/** One full cycle: claim a job, and if there is one, drive it to done. */
async function verarbeite(umgebung) {
    const aufgabe = await naechsteAufgabe(umgebung);
    if (aufgabe.status !== 'aufgabe') {
        return aufgabe;
    }

    return await verarbeiteJob(aufgabe, umgebung);
}

function initRasterung() {
    const wurzel = document.querySelector('#rasterung');
    if (wurzel === null) {
        return;
    }

    const csrf = wurzel.dataset.csrf || '';
    const pdfjsSrc = wurzel.dataset.pdfjs || '';
    const pdfjsWorkerSrc = wurzel.dataset.pdfjsWorker || '';
    const pdfjsWasmSrc = wurzel.dataset.pdfjsWasm || '';
    let timer = null;
    let pdfjsLib = null;

    function sichtbar() {
        return document.visibilityState !== 'hidden';
    }

    async function ladeQuelle(job, lock, quelleIndex) {
        if (pdfjsLib === null) {
            pdfjsLib = await import(/* webpackIgnore: true */ pdfjsSrc);
            pdfjsLib.GlobalWorkerOptions.workerSrc = pdfjsWorkerSrc;
        }

        const antwort = await fetch('/api/rasterung/' + job + '/' + lock + '/quelle/' + quelleIndex);
        if (!antwort.ok) {
            throw new Error('PDF konnte nicht geladen werden.');
        }

        const pdf = await pdfjsLib.getDocument({
            data: new Uint8Array(await antwort.arrayBuffer()),
            // No CSP `wasm-unsafe-eval` (docker/web/.htaccess) - pdf.js
            // would only have its request blocked and fall back on its
            // own, but asking outright skips that failed round trip
            // (public/js/vendor/README.md).
            useWasm: false,
            wasmUrl: pdfjsWasmSrc,
            isEvalSupported: false,
            enableScripting: false,
        }).promise;

        return {
            numPages: pdf.numPages,
            seite: async function (nummer, qualitaet) {
                const seite = await pdf.getPage(nummer);
                const basisViewport = seite.getViewport({ scale: 1 });
                const viewport = seite.getViewport({ scale: massstab(basisViewport.width, basisViewport.height) });
                const canvas = document.createElement('canvas');
                canvas.width = Math.round(viewport.width);
                canvas.height = Math.round(viewport.height);
                const context = canvas.getContext('2d');
                // White, not transparent: a JPEG has no alpha channel, and a
                // scanned receipt's background is paper, not black.
                context.fillStyle = '#ffffff';
                context.fillRect(0, 0, canvas.width, canvas.height);
                await seite.render({ canvasContext: context, viewport: viewport }).promise;

                const blob = await new Promise(function (resolve) {
                    canvas.toBlob(resolve, 'image/jpeg', qualitaet);
                });

                return new Uint8Array(await blob.arrayBuffer());
            },
        };
    }

    async function lauf() {
        let ergebnis;
        try {
            ergebnis = await verarbeite({ fetch: fetch, csrf: csrf, ladeQuelle: ladeQuelle });
        } catch {
            ergebnis = { status: 'fehler' };
        }
        plane(wartezeitRaster(ergebnis.status, sichtbar()));
    }

    function plane(verzoegerung) {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
        if (verzoegerung === null) {
            return;
        }
        timer = setTimeout(lauf, verzoegerung);
    }

    // Coming back from the background: drop the slow timer and act at once,
    // the same as public/js/jobs.js.
    document.addEventListener('visibilitychange', function () {
        if (sichtbar() && timer !== null) {
            clearTimeout(timer);
            timer = null;
            lauf();
        }
    });

    plane(sichtbar() ? 250 : 30000);
}

if (typeof document !== 'undefined') {
    initRasterung();
}

// Node (tests/js) loads the same file for the pure/injectable functions
// above; browsers ignore this block because `module` does not exist there.
if (typeof module === 'object' && module.exports) {
    module.exports = {
        massstab,
        wartezeitRaster,
        verarbeiteJob,
        naechsteAufgabe,
        verarbeite,
        QUALITAETSSTUFEN,
        MAX_BYTES,
    };
}
