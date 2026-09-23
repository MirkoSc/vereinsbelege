// Uploads a file in 2 MiB chunks (docs/spec/03-erfassung-und-ki.md section 4):
// one short request per chunk, so nothing runs into the hoster's request time
// limit and the upload limit per request stops mattering (CLAUDE.md section 1).
//
// Its own file rather than an inline script: the CSP is script-src 'self'
// without 'unsafe-inline' (CLAUDE.md section 4), and all requests go to the
// same origin, which connect-src 'self' also requires.
//
// Wired up by public/js/einreichen.js (issue #24/M4-2, the public
// submission) and public/js/erfassen.js (issue #28/M4-6, the internal
// capture); the scanner (M5) reuses the same library.

/** Chunk size of the server; /api/upload answers with the one that counts. */
const CHUNK_BYTES = 2 * 1024 * 1024;

/** How many chunks a file of this size is cut into. */
function chunkAnzahl(groesse, chunkBytes) {
    if (!(groesse > 0) || !(chunkBytes > 0)) {
        return 0;
    }

    return Math.ceil(groesse / chunkBytes);
}

/**
 * The pieces of the file, in order: index and the byte range for
 * File.slice(). The last piece is shorter, all others are full.
 */
function chunkGrenzen(groesse, chunkBytes) {
    const grenzen = [];
    const anzahl = chunkAnzahl(groesse, chunkBytes);

    for (let index = 0; index < anzahl; index++) {
        const start = index * chunkBytes;
        grenzen.push({ index: index, start: start, ende: Math.min(start + chunkBytes, groesse) });
    }

    return grenzen;
}

/** Progress in whole percent, never above 100, 100 for an empty file. */
function fortschrittProzent(gesendet, gesamt) {
    if (!(gesamt > 0)) {
        return 100;
    }

    return Math.min(100, Math.floor((gesendet / gesamt) * 100));
}

/**
 * What went wrong, in the server's words when it said anything - it answers
 * German sentences meant for the person in front of the browser.
 */
function fehlertext(antwort, daten) {
    if (daten && daten.fehler) {
        return daten.fehler;
    }

    return 'HTTP ' + (antwort ? antwort.status : '?');
}

/**
 * Uploads one file and returns the server's answer
 * ({ blob_id, groesse, typ }).
 *
 * `fetch` is a parameter so that the tests can drive the whole sequence
 * without a network and without a DOM; in the browser the default is the real
 * one. `datei` is a File or Blob: .size, .name and .slice() are all that is
 * used of it. `basis` is the route prefix - '/api/upload' by default (the
 * internal capture, M3-6), '/einreichen/upload' for the public submission
 * (issue #24/M4-2, App\Api\EinreichungUploadController): same four requests,
 * same CSRF-token header, different credential behind it. `headers` adds
 * further headers to every one of those requests - the public submission's
 * proof-of-work solution (issue #25/M4-3, `X-Pow-Loesung`,
 * public/js/einreichen.js), which only its opening request checks but every
 * request may as well carry.
 */
async function dateiHochladen(datei, optionen) {
    const einstellungen = optionen || {};
    const csrf = einstellungen.csrf || '';
    const basis = einstellungen.basis || '/api/upload';
    const holen = einstellungen.fetch || (typeof fetch === 'function' ? fetch : null);
    const melden = einstellungen.onFortschritt || function () {};
    const zusatzHeader = einstellungen.headers || {};

    if (holen === null) {
        throw new Error('Kein fetch verfügbar.');
    }

    const senden = async function (pfad, optionenDerAnfrage) {
        const anfrage = Object.assign({ method: 'POST' }, optionenDerAnfrage);
        anfrage.headers = Object.assign({ 'X-CSRF-Token': csrf }, zusatzHeader, anfrage.headers || {});

        const antwort = await holen(pfad, anfrage);
        const daten = await antwort.json();
        if (!antwort.ok || daten.fehler) {
            throw new Error(fehlertext(antwort, daten));
        }

        return daten;
    };

    const eroeffnet = await senden(basis, {
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ groesse: datei.size }),
    });

    try {
        // Strictly one after the other: the chunks may arrive in any order,
        // but sending them in parallel would put several megabytes on a
        // mobile connection at once and make the progress meaningless.
        let gesendet = 0;
        const grenzen = chunkGrenzen(datei.size, eroeffnet.chunk_bytes || CHUNK_BYTES);
        for (const grenze of grenzen) {
            await senden(basis + '/' + eroeffnet.id + '/chunk/' + grenze.index, {
                headers: { 'Content-Type': 'application/octet-stream' },
                body: datei.slice(grenze.start, grenze.ende),
            });

            gesendet += grenze.ende - grenze.start;
            melden(fortschrittProzent(gesendet, datei.size));
        }

        return await senden(basis + '/' + eroeffnet.id + '/finish', {
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name: datei.name || '' }),
        });
    } catch (problem) {
        // Give the chunks back right away instead of leaving them for the
        // cron - the person may pick another file straight away.
        try {
            await senden(basis + '/' + eroeffnet.id + '/abort', {});
        } catch (ignoriert) {
            // The upload is lost either way; the original error is the one
            // worth reporting.
        }

        throw problem;
    }
}

// Node (tests/js) loads the same file; browsers ignore this block because
// `module` does not exist there.
if (typeof module === 'object' && module.exports) {
    module.exports = { CHUNK_BYTES, chunkAnzahl, chunkGrenzen, fortschrittProzent, fehlertext, dateiHochladen };
}
