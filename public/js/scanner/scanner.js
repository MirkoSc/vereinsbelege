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
 * Shown when a HEIC photo cannot be decoded here. The page is refused before
 * any upload: the server cannot read HEIC, so a HEIC original is only taken
 * together with the JPEG made in the browser (docs/spec/03 section 1).
 */
const HEIC_HINWEIS = 'HEIC-Bilder werden von diesem Browser nicht unterstützt. Bitte als JPEG oder PNG wählen, oder das Foto direkt aufnehmen. iPhone-Tipp: Einstellungen › Kamera › Formate › „Maximale Kompatibilität“.';

/** JPEG quality of a converted HEIC photo - it is the scanner's input, not the end product. */
const HEIC_QUALITAET = 0.95;

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

/**
 * HEIC/HEIF, the iPhone default (docs/spec/03-erfassung-und-ki.md section 1).
 * By MIME type or extension - a file picked from the gallery often has no
 * type. The server decides by magic bytes (App\Service\Upload\MagicBytes).
 */
function istHeicDatei(datei) {
    const typ = (datei && datei.type) || '';
    const name = (datei && datei.name) || '';

    return typ === 'image/heic' || typ === 'image/heif' || /\.hei[cf]$/i.test(name);
}

/** File name of the JPEG converted from a HEIC file. */
function heicJpegName(name) {
    const basis = String(name || '').replace(/\.[^.\/\\]*$/, '');

    return (basis === '' ? 'foto' : basis) + '.jpg';
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
 *   heicWandeln  - optional, (datei) => Promise<File|null>: HEIC to JPEG
 *
 * Resolves `{ abgebrochen: true }` on a cancel, otherwise `{ blobId,
 * aufbereitetId, aufbereitet, serverFallback, scanDatei }`:
 * `aufbereitet` is the processed File (for the preview) or null,
 * `serverFallback` says the image was not processed here and the server will
 * do it, `scanDatei` the JPEG converted from a HEIC original
 * (the input of "Zuschneiden") or null. A HEIC file the browser cannot
 * decode rejects with an Error (`heic: true`, message HEIC_HINWEIS) before
 * anything is uploaded.
 */
async function seiteVerarbeiten(datei, optionen) {
    // A HEIC photo is converted first where the browser can decode it; the
    // JPEG then runs through the scanner like any other image. The HEIC file
    // stays the original (E-10), the JPEG is its processed version.
    let quelle = datei;
    if (istHeicDatei(datei)) {
        quelle = null;
        if (typeof optionen.heicWandeln === 'function') {
            try {
                quelle = await optionen.heicWandeln(datei);
            } catch (problem) {
                quelle = null;
            }
        }
        if (quelle === null) {
            // Not decodable here: refused before anything is uploaded.
            const abgelehnt = new Error(HEIC_HINWEIS);
            abgelehnt.heic = true;

            throw abgelehnt;
        }
    }

    const konvertiert = quelle !== datei;
    const scanbar = istScanbar(quelle);
    let aufbereitet = null;
    let serverFallback = scanbar && !konvertiert;

    if (scanbar && optionen.kann) {
        try {
            aufbereitet = await optionen.aufbereiten(quelle);
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
    if (aufbereitet === null && konvertiert) {
        // The server cannot read HEIC: the converted JPEG is the processed
        // version even if the scanner did not run.
        aufbereitet = quelle;
    }

    const original = await optionen.hochladen(datei);
    const verarbeitet = aufbereitet === null ? null : await optionen.hochladen(aufbereitet);

    return {
        abgebrochen: false,
        blobId: original.blob_id,
        aufbereitetId: verarbeitet === null ? null : verarbeitet.blob_id,
        aufbereitet: aufbereitet,
        serverFallback: serverFallback,
        scanDatei: konvertiert ? quelle : null,
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
 * HEIC to JPEG with the browser's own decoder (Safari reads HEIC in
 * createImageBitmap; Chrome/Firefox reject it - then this throws and the
 * caller shows the hint). No library: a WASM decoder would need
 * 'wasm-unsafe-eval' in the CSP (CLAUDE.md section 4).
 */
async function heicZuJpeg(datei) {
    const bitmap = await createImageBitmap(datei, { imageOrientation: 'from-image' });
    try {
        const canvas = document.createElement('canvas');
        canvas.width = bitmap.width;
        canvas.height = bitmap.height;
        canvas.getContext('2d').drawImage(bitmap, 0, 0);

        return await new Promise(function (erfuellen, ablehnen) {
            canvas.toBlob(function (blob) {
                if (blob === null) {
                    ablehnen(new Error('Das Bild konnte nicht umgewandelt werden.'));

                    return;
                }
                erfuellen(new File([blob], heicJpegName(datei.name), { type: 'image/jpeg' }));
            }, 'image/jpeg', HEIC_QUALITAET);
        });
    } finally {
        bitmap.close();
    }
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
        HEIC_QUALITAET,
        HEIC_HINWEIS,
        kannScannen,
        istScanbar,
        istHeicDatei,
        heicJpegName,
        aufbereitetName,
        startEcken,
        seiteVerarbeiten,
    };
}
