// The scanner in the capture pages (docs/spec/03-erfassung-und-ki.md
// section 2, issue #34/M5-4): the glue between a chosen or photographed
// image and the two uploads a page then consists of - the untouched
// original (decision E-10) and the processed JPEG (cropped, dewarped,
// colour mode applied). Used by /einreichen (public/js/einreichen.js,
// interactive: the corner editor opens for every image) and by
// /app/belege/neu (public/js/erfassen.js, automatic: detected corners, the
// editor only on demand), and by the designsystem demo for bildAusBitmap().
//
// Loaded after the other public/js/scanner/ files (kantenErkennen(),
// standardRahmen(), ganzesBild(), ergebnisErzeugen(), eckEditorBinden() are
// globals of those classic scripts, CLAUDE.md section 4) and before the
// page scripts.
//
// When the browser cannot do the processing (no createImageBitmap, no
// canvas.toBlob, no <dialog>) or it fails on a particular image, only the
// original is uploaded and the server applies its GD fallback in
// `pdf_erzeugen` (App\Service\Processing\SchwarzweissFallback).

const scannerHilfen = typeof module === 'object' && module.exports
    ? require('./eckeditor.js')
    : { standardRahmen: standardRahmen, ganzesBild: ganzesBild };

/** JPEG quality of the processed page (spec: "Qualität ~0.85"). */
const SCAN_QUALITAET = 0.85;

/**
 * Whether this browser can process pages itself. `umgebung` is `window` in
 * the browser; tests pass a plain object.
 */
function kannScannen(umgebung) {
    if (!umgebung) {
        return false;
    }
    const canvas = umgebung.HTMLCanvasElement;
    const dialog = umgebung.HTMLDialogElement;

    return typeof umgebung.createImageBitmap === 'function'
        && typeof umgebung.ImageData === 'function'
        && typeof canvas === 'function' && typeof canvas.prototype.toBlob === 'function'
        && typeof dialog === 'function' && typeof dialog.prototype.showModal === 'function';
}

/**
 * JPEG and PNG are what the scanner reads (and what the upload accepts);
 * a PDF is never processed, it keeps its own pages.
 */
function istScanbar(datei) {
    const typ = (datei && datei.type) || '';
    if (typ !== '') {
        return typ === 'image/jpeg' || typ === 'image/png';
    }

    return /\.(jpe?g|png)$/i.test((datei && datei.name) || '');
}

/** File name of the processed version - encrypted like every name, never shown publicly. */
function aufbereitetName(name) {
    const basis = String(name || '').replace(/\.[^.\/\\]*$/, '');

    return (basis === '' ? 'seite' : basis) + '-aufbereitet.jpg';
}

/**
 * Starting corners: what edge detection found, else a guess. Interactively
 * the inset default frame of the corner editor (M5-3) invites a correction;
 * automatically the whole image - cropping blind would cut the receipt.
 */
function startEcken(gefunden, breite, hoehe, interaktiv) {
    if (gefunden !== null && gefunden !== undefined) {
        return gefunden;
    }

    return interaktiv ? scannerHilfen.standardRahmen(breite, hoehe) : scannerHilfen.ganzesBild(breite, hoehe);
}

/**
 * Processes and uploads one page: the processed version first (so a cancel
 * in the editor uploads nothing), then the original, then the processed
 * version. Every step is injected so tests/js can drive it without a DOM:
 *
 *   kann         - kannScannen() of this browser
 *   aufbereiten  - (datei) => Promise<File|null>; null = cancelled
 *   hochladen    - (datei) => Promise<{blob_id}>
 *
 * Resolves `{ abgebrochen: true }` on a cancel, otherwise `{ blobId,
 * aufbereitetId, aufbereitet, serverFallback }`: `aufbereitet` is the
 * processed File (for the preview) or null, `serverFallback` says the
 * image was not processed here and the server will do it.
 */
async function seiteVerarbeiten(datei, optionen) {
    const scanbar = istScanbar(datei);
    let aufbereitet = null;
    let serverFallback = scanbar;

    if (scanbar && optionen.kann) {
        try {
            aufbereitet = await optionen.aufbereiten(datei);
            if (aufbereitet === null) {
                return { abgebrochen: true };
            }
            serverFallback = false;
        } catch (problem) {
            // An image the browser cannot decode or has no memory for - the
            // original still goes up, the server takes over (GD fallback).
            aufbereitet = null;
        }
    }

    const original = await optionen.hochladen(datei);
    const verarbeitet = aufbereitet === null ? null : await optionen.hochladen(aufbereitet);

    return {
        abgebrochen: false,
        blobId: original.blob_id,
        aufbereitetId: verarbeitet === null ? null : verarbeitet.blob_id,
        aufbereitet: aufbereitet,
        serverFallback: serverFallback,
    };
}

// --- Browser only from here on --------------------------------------------

/** An ImageBitmap's pixels as the { width, height, data } shape of the scanner functions. */
function bildAusBitmap(bitmap) {
    const canvas = document.createElement('canvas');
    canvas.width = bitmap.width;
    canvas.height = bitmap.height;
    const context = canvas.getContext('2d');
    context.drawImage(bitmap, 0, 0);

    return context.getImageData(0, 0, bitmap.width, bitmap.height);
}

/** `ergebnisErzeugen()`'s image as a JPEG File. */
function alsJpegDatei(bild, name) {
    const canvas = document.createElement('canvas');
    canvas.width = bild.width;
    canvas.height = bild.height;
    canvas.getContext('2d').putImageData(new ImageData(bild.data, bild.width, bild.height), 0, 0);

    return new Promise(function (erfuellen, ablehnen) {
        canvas.toBlob(function (blob) {
            if (blob === null) {
                ablehnen(new Error('Das Bild konnte nicht erzeugt werden.'));

                return;
            }
            erfuellen(new File([blob], aufbereitetName(name), { type: 'image/jpeg' }));
        }, 'image/jpeg', SCAN_QUALITAET);
    });
}

/**
 * The image upright (createImageBitmap applies the EXIF orientation of a
 * phone photo, "from-image" is the default) plus its pixels.
 */
async function scanQuelle(datei) {
    const bitmap = await createImageBitmap(datei, { imageOrientation: 'from-image' });

    return { bitmap: bitmap, bild: bildAusBitmap(bitmap) };
}

/** Automatic processing: detected corners (or the whole image), black and white. */
async function automatischAufbereiten(datei) {
    const quelle = await scanQuelle(datei);
    try {
        const ecken = startEcken(kantenErkennen(quelle.bild), quelle.bitmap.width, quelle.bitmap.height, false);

        return await alsJpegDatei(ergebnisErzeugen(quelle.bild, ecken, 'sw'), datei.name);
    } finally {
        quelle.bitmap.close();
    }
}

/**
 * Opens the corner editor in `dialog` (app/views/partials/scanner-dialog.php)
 * for one image. Resolves the processed File on "Übernehmen", null on
 * "Abbrechen"/Escape. One dialog per page, rebound for every image - the
 * editor's colour-mode radios share one name (M5-3), so two editors on one
 * page would share one radio group.
 */
async function scannerOeffnen(dialog, datei) {
    const quelle = await scanQuelle(datei);
    const uebernehmen = dialog.querySelector('[data-scanner="uebernehmen"]');
    const abbrechen = dialog.querySelector('[data-scanner="abbrechen"]');
    const status = dialog.querySelector('[data-scanner="status"]');

    const wurzel = dialog.querySelector('.eck-editor');

    return new Promise(function (erfuellen) {
        let erledigt = false;
        let editor = null;

        function beenden(ergebnis) {
            if (erledigt) {
                return;
            }
            erledigt = true;
            uebernehmen.removeEventListener('click', beiUebernehmen);
            abbrechen.removeEventListener('click', beiAbbrechen);
            dialog.removeEventListener('cancel', beiEscape);
            // The editor keeps a resize listener (M5-3); with the dialog
            // closed and the bitmap released it must not run any more.
            if (wurzel.eckEditorResizeHandler) {
                window.removeEventListener('resize', wurzel.eckEditorResizeHandler);
                wurzel.eckEditorResizeHandler = null;
            }
            quelle.bitmap.close();
            if (dialog.open) {
                dialog.close();
            }
            erfuellen(ergebnis);
        }

        function beiAbbrechen() {
            beenden(null);
        }

        function beiEscape(ereignis) {
            ereignis.preventDefault();
            beenden(null);
        }

        async function beiUebernehmen() {
            uebernehmen.disabled = true;
            status.hidden = false;
            try {
                const bild = ergebnisErzeugen(quelle.bild, editor.ecken(), editor.farbmodus());
                beenden(await alsJpegDatei(bild, datei.name));
            } catch (problem) {
                // A degenerate quad cannot be dewarped - the editor prevents
                // one, so this is the memory/encoder case: keep the page,
                // leave the processing to the server.
                beenden(problem instanceof Error ? problem : new Error(String(problem)));
            } finally {
                uebernehmen.disabled = false;
                status.hidden = true;
            }
        }

        uebernehmen.addEventListener('click', beiUebernehmen);
        abbrechen.addEventListener('click', beiAbbrechen);
        dialog.addEventListener('cancel', beiEscape);

        try {
            dialog.showModal();
            // Bound after showModal(): the editor sizes itself from the
            // dialog's laid-out width.
            const ecken = startEcken(kantenErkennen(quelle.bild), quelle.bitmap.width, quelle.bitmap.height, true);
            editor = eckEditorBinden(wurzel, quelle.bitmap, ecken);
        } catch (problem) {
            beenden(problem instanceof Error ? problem : new Error(String(problem)));
        }
    }).then(function (ergebnis) {
        if (ergebnis instanceof Error) {
            throw ergebnis;
        }

        return ergebnis;
    });
}

// Node (tests/js) loads the same file for the pure helpers above; browsers
// ignore this block because `module` does not exist there.
if (typeof module === 'object' && module.exports) {
    module.exports = {
        SCAN_QUALITAET,
        kannScannen,
        istScanbar,
        aufbereitetName,
        startEcken,
        seiteVerarbeiten,
    };
}
