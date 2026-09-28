// Live camera for the capture pages (docs/spec/03-erfassung-und-ki.md
// section 1: "Live-Kamera (getUserMedia, Rückkamera) mit Rahmen-Overlay;
// Fallback <input type=file accept=image/* capture=environment>", issue
// #34/M5-4). The frame is a guide for holding the phone, nothing is cropped
// here: the whole camera frame becomes the original (decision E-10), the
// corner editor (public/js/scanner/scanner.js) crops afterwards.
//
// The stream goes into the <video> through `srcObject` - no URL is fetched,
// so the CSP (no media-src, CLAUDE.md section 4) needs nothing new. Every
// track is stopped whenever the dialog closes, so the camera light never
// stays on.
//
// Where getUserMedia is missing or refused, the page keeps the native
// camera: the dialog itself carries an <input capture> ("Kamera-App
// verwenden"), and without getUserMedia the page's own capture input is
// used directly.

/** JPEG quality of the captured frame - it is the original, so higher than the scan's. */
const KAMERA_QUALITAET = 0.92;

/** A4 portrait, width / height - the shape of the guide frame. */
const KAMERA_RAHMEN_VERHAELTNIS = 210 / 297;

/**
 * Whether a live camera can be tried at all: getUserMedia exists only in a
 * secure context (HTTPS, or localhost in development).
 */
function kameraVerfuegbar(umgebung) {
    if (!umgebung || umgebung.isSecureContext === false) {
        return false;
    }
    const geraete = umgebung.navigator && umgebung.navigator.mediaDevices;

    return Boolean(geraete) && typeof geraete.getUserMedia === 'function';
}

/**
 * The getUserMedia constraints: the rear camera if there is one ("ideal",
 * so a laptop's only camera still works), as many pixels as it offers up to
 * about 5 MP - small print on a receipt needs them.
 */
function kameraEinschraenkungen() {
    return {
        audio: false,
        video: {
            facingMode: { ideal: 'environment' },
            width: { ideal: 2560 },
            height: { ideal: 1920 },
        },
    };
}

/**
 * The guide frame inside a displayed video of `breite` x `hoehe` pixels: an
 * A4 portrait rectangle, as large as fits with `rand` (share of the shorter
 * side) left free all around, centred. Pixels, for CSSOM left/top/width/
 * height - no inline style attribute (CSP).
 */
function rahmenGeometrie(breite, hoehe, rand) {
    const abstand = Math.round(Math.min(breite, hoehe) * (rand === undefined ? 0.06 : rand));
    const platzB = Math.max(0, breite - 2 * abstand);
    const platzH = Math.max(0, hoehe - 2 * abstand);

    let rahmenB = platzB;
    let rahmenH = rahmenB / KAMERA_RAHMEN_VERHAELTNIS;
    if (rahmenH > platzH) {
        rahmenH = platzH;
        rahmenB = rahmenH * KAMERA_RAHMEN_VERHAELTNIS;
    }

    return {
        x: Math.round((breite - rahmenB) / 2),
        y: Math.round((hoehe - rahmenH) / 2),
        breite: Math.round(rahmenB),
        hoehe: Math.round(rahmenH),
    };
}

/** A German sentence for a failed getUserMedia(), from the DOMException's name. */
function kameraFehlertext(fehler) {
    const name = (fehler && fehler.name) || '';

    switch (name) {
        case 'NotAllowedError':
        case 'SecurityError':
            return 'Der Zugriff auf die Kamera wurde nicht erlaubt. Bitte „Kamera-App verwenden“ oder ein Bild wählen.';
        case 'NotFoundError':
        case 'OverconstrainedError':
            return 'Es wurde keine passende Kamera gefunden. Bitte „Kamera-App verwenden“ oder ein Bild wählen.';
        case 'NotReadableError':
        case 'AbortError':
            return 'Die Kamera wird gerade von einer anderen App verwendet.';
        default:
            return 'Die Kamera konnte nicht gestartet werden. Bitte „Kamera-App verwenden“ oder ein Bild wählen.';
    }
}

/** File name of a captured frame; the name is encrypted like every other. */
function aufnahmeName(datum) {
    const zweistellig = function (zahl) {
        return String(zahl).padStart(2, '0');
    };

    return 'kamera-' + datum.getFullYear() + zweistellig(datum.getMonth() + 1) + zweistellig(datum.getDate())
        + '-' + zweistellig(datum.getHours()) + zweistellig(datum.getMinutes()) + zweistellig(datum.getSeconds()) + '.jpg';
}

// --- Browser only from here on --------------------------------------------

/**
 * Opens the camera dialog (app/views/partials/kamera-dialog.php). Resolves
 * the captured photo as a File, the file picked through "Kamera-App
 * verwenden", or null when the dialog was cancelled.
 */
function kameraOeffnen(dialog) {
    const video = dialog.querySelector('.kamera-video');
    const rahmen = dialog.querySelector('.kamera-rahmen');
    const ausloesen = dialog.querySelector('[data-kamera="ausloesen"]');
    const abbrechen = dialog.querySelector('[data-kamera="abbrechen"]');
    const dateiFeld = dialog.querySelector('[data-kamera="datei"]');
    const fehlerText = dialog.querySelector('[data-kamera="fehler"]');

    return new Promise(function (erfuellen) {
        let strom = null;
        let erledigt = false;

        function rahmenSetzen() {
            const feld = rahmenGeometrie(video.clientWidth, video.clientHeight);
            rahmen.style.left = feld.x + 'px';
            rahmen.style.top = feld.y + 'px';
            rahmen.style.width = feld.breite + 'px';
            rahmen.style.height = feld.hoehe + 'px';
            rahmen.hidden = feld.breite === 0;
        }

        function beenden(ergebnis) {
            if (erledigt) {
                return;
            }
            erledigt = true;
            ausloesen.removeEventListener('click', beiAusloesen);
            abbrechen.removeEventListener('click', beiAbbrechen);
            dateiFeld.removeEventListener('change', beiDatei);
            dialog.removeEventListener('cancel', beiEscape);
            video.removeEventListener('loadedmetadata', rahmenSetzen);
            window.removeEventListener('resize', rahmenSetzen);
            if (strom !== null) {
                strom.getTracks().forEach(function (spur) {
                    spur.stop();
                });
            }
            video.srcObject = null;
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

        function beiDatei() {
            const datei = dateiFeld.files && dateiFeld.files[0];
            dateiFeld.value = '';
            if (datei) {
                beenden(datei);
            }
        }

        function beiAusloesen() {
            if (strom === null || video.videoWidth === 0) {
                return;
            }
            const canvas = document.createElement('canvas');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            canvas.getContext('2d').drawImage(video, 0, 0);
            ausloesen.disabled = true;
            canvas.toBlob(function (blob) {
                ausloesen.disabled = false;
                if (blob === null) {
                    fehlerText.textContent = 'Das Foto konnte nicht gespeichert werden. Bitte erneut auslösen.';
                    fehlerText.hidden = false;

                    return;
                }
                beenden(new File([blob], aufnahmeName(new Date()), { type: 'image/jpeg' }));
            }, 'image/jpeg', KAMERA_QUALITAET);
        }

        ausloesen.addEventListener('click', beiAusloesen);
        abbrechen.addEventListener('click', beiAbbrechen);
        dateiFeld.addEventListener('change', beiDatei);
        dialog.addEventListener('cancel', beiEscape);
        video.addEventListener('loadedmetadata', rahmenSetzen);
        window.addEventListener('resize', rahmenSetzen);

        fehlerText.hidden = true;
        rahmen.hidden = true;
        ausloesen.disabled = true;
        dialog.showModal();

        navigator.mediaDevices.getUserMedia(kameraEinschraenkungen()).then(function (erhalten) {
            if (erledigt) {
                // Cancelled while the permission prompt was still open.
                erhalten.getTracks().forEach(function (spur) {
                    spur.stop();
                });

                return;
            }
            strom = erhalten;
            video.srcObject = erhalten;
            ausloesen.disabled = false;

            return video.play();
        }).catch(function (fehler) {
            if (erledigt) {
                return;
            }
            fehlerText.textContent = kameraFehlertext(fehler);
            fehlerText.hidden = false;
            ausloesen.disabled = true;
        });
    });
}

// Node (tests/js) loads the same file for the pure helpers above; browsers
// ignore this block because `module` does not exist there.
if (typeof module === 'object' && module.exports) {
    module.exports = {
        KAMERA_QUALITAET,
        KAMERA_RAHMEN_VERHAELTNIS,
        kameraVerfuegbar,
        kameraEinschraenkungen,
        rahmenGeometrie,
        kameraFehlertext,
        aufnahmeName,
    };
}
