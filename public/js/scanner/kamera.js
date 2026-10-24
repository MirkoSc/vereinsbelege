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
//
// Since issue #146 the dialog also runs the edge detection live on a small
// copy of the frame and draws the found quad over the video; see the
// "Live edge detection" block below.

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

// --- Live edge detection (issue #146) --------------------------------------
//
// While the camera runs, `kantenErkennen()` (kanten.js) looks at a small
// copy of the video frame now and then and the dialog draws the quad it
// found over the video. A quad that stays put for a few detections counts as
// "stabil": the hint "Beleg erkannt" appears, its corners become the corner
// editor's start corners, and - only if the person switched it on - the
// photo is taken by itself. The whole camera frame stays the original
// (E-10); nothing here crops.

/** Earliest start of the next detection after the previous one started, ms. */
const LIVE_INTERVALL_MS = 400;

/** Width of the downscaled frame the live detection runs on, px. */
const LIVE_BREITE = 320;

/** Consecutive detections that must agree before the quad counts as stable. */
const LIVE_STABIL_ANZAHL = 3;

/** Largest corner movement between those detections, share of the frame diagonal. */
const LIVE_STABIL_TOLERANZ = 0.02;

/**
 * Size of the downscaled detection frame for a `videoBreite` x `videoHoehe`
 * video: `zielBreite` wide (never upscaled), height by the video's aspect.
 * `faktor` maps a point of the small frame back to video pixels.
 */
function vorschauGroesse(videoBreite, videoHoehe, zielBreite) {
    if (!(videoBreite > 0) || !(videoHoehe > 0)) {
        return null;
    }
    const breite = Math.max(1, Math.min(Math.round(zielBreite || LIVE_BREITE), videoBreite));
    const hoehe = Math.max(1, Math.round((videoHoehe * breite) / videoBreite));

    return { breite: breite, hoehe: hoehe, faktor: videoBreite / breite };
}

/**
 * Delay until the next detection, after one that took `dauer` ms: at most
 * one start per `intervall`, and afterwards at least as much idle time as
 * the detection itself took - on a slow phone the video then still gets
 * half of the main thread instead of stuttering.
 */
function naechsteErkennungIn(dauer, intervall) {
    const takt = intervall === undefined ? LIVE_INTERVALL_MS : intervall;
    const gebraucht = Math.max(0, Number(dauer) || 0);

    return Math.round(Math.max(takt - gebraucht, gebraucht));
}

/**
 * Largest distance between corresponding corners of two quads, as a share of
 * the diagonal of a `breite` x `hoehe` frame. Infinity when one is missing.
 */
function eckenAbweichung(a, b, breite, hoehe) {
    if (!Array.isArray(a) || !Array.isArray(b) || a.length !== 4 || b.length !== 4) {
        return Infinity;
    }
    const diagonale = Math.hypot(breite, hoehe);
    if (!(diagonale > 0)) {
        return Infinity;
    }

    let groesste = 0;
    for (let i = 0; i < 4; i++) {
        groesste = Math.max(groesste, Math.hypot(a[i].x - b[i].x, a[i].y - b[i].y) / diagonale);
    }

    return groesste;
}

/** The empty stability state - before the first detection, or after the quad was lost. */
function liveStart() {
    return { verlauf: [], stabil: false };
}

/**
 * The next stability state after one detection (`ecken` from
 * `kantenErkennen()` on the small frame, or null). Stable once the last
 * `anzahl` detections all found a quad and no two of them differ by more
 * than `toleranz` (`eckenAbweichung`) - pairwise, so a slow drift across the
 * window does not count as standing still. A miss starts over. Pure: the
 * old state is left as it was.
 */
function liveFortschreiben(zustand, ecken, breite, hoehe, optionen) {
    const einstellungen = optionen || {};
    const anzahl = einstellungen.anzahl || LIVE_STABIL_ANZAHL;
    const toleranz = einstellungen.toleranz !== undefined ? einstellungen.toleranz : LIVE_STABIL_TOLERANZ;

    if (!Array.isArray(ecken) || ecken.length !== 4) {
        return liveStart();
    }

    const verlauf = (zustand && Array.isArray(zustand.verlauf) ? zustand.verlauf : [])
        .concat([ecken.map(function (p) {
            return { x: p.x, y: p.y };
        })])
        .slice(-anzahl);

    let stabil = verlauf.length === anzahl;
    for (let i = 0; stabil && i < verlauf.length; i++) {
        for (let j = i + 1; stabil && j < verlauf.length; j++) {
            stabil = eckenAbweichung(verlauf[i], verlauf[j], breite, hoehe) <= toleranz;
        }
    }

    return { verlauf: verlauf, stabil: stabil };
}

/** The newest detected quad of a state, or null. */
function liveEcken(zustand) {
    const verlauf = zustand && zustand.verlauf;

    return Array.isArray(verlauf) && verlauf.length > 0 ? verlauf[verlauf.length - 1] : null;
}

/**
 * The start corners for the corner editor in video pixels: the newest quad
 * scaled up by `faktor` (vorschauGroesse) and clamped to the frame - but
 * only from a stable state. A quad seen once may be a glimpse of the table
 * edge; the editor then detects on the full photo itself, as before.
 */
function startEckenAusLive(zustand, faktor, videoBreite, videoHoehe) {
    if (!zustand || !zustand.stabil) {
        return null;
    }
    const ecken = liveEcken(zustand);
    if (ecken === null || !(faktor > 0)) {
        return null;
    }

    return ecken.map(function (p) {
        return {
            x: Math.min(Math.max(p.x * faktor, 0), videoBreite),
            y: Math.min(Math.max(p.y * faktor, 0), videoHoehe),
        };
    });
}

/**
 * Where a `videoBreite` x `videoHoehe` video lands inside a displayed
 * element of `anzeigeBreite` x `anzeigeHoehe` with `object-fit: contain`
 * (.kamera-video): one scale, centred, black bars on two sides.
 */
function anzeigeAbbildung(videoBreite, videoHoehe, anzeigeBreite, anzeigeHoehe) {
    if (!(videoBreite > 0) || !(videoHoehe > 0) || !(anzeigeBreite > 0) || !(anzeigeHoehe > 0)) {
        return null;
    }
    const skala = Math.min(anzeigeBreite / videoBreite, anzeigeHoehe / videoHoehe);

    return {
        skala: skala,
        x: (anzeigeBreite - videoBreite * skala) / 2,
        y: (anzeigeHoehe - videoHoehe * skala) / 2,
    };
}

/**
 * The SVG `points` attribute for a quad given in small-frame pixels, drawn
 * over the displayed video: small frame -> video pixels (`faktor`) -> the
 * element's pixels (`abbildung`). An attribute, not a style - fine for the
 * CSP (CLAUDE.md section 4).
 */
function overlayPunkte(ecken, faktor, abbildung) {
    if (!Array.isArray(ecken) || ecken.length !== 4 || abbildung === null || !(faktor > 0)) {
        return '';
    }

    return ecken.map(function (p) {
        const x = abbildung.x + p.x * faktor * abbildung.skala;
        const y = abbildung.y + p.y * faktor * abbildung.skala;

        return x.toFixed(1) + ',' + y.toFixed(1);
    }).join(' ');
}

/** The status line under the video; empty while no stable quad is seen. */
function liveHinweis(stabil, automatisch) {
    if (!stabil) {
        return '';
    }

    return automatisch ? 'Beleg erkannt – das Foto wird aufgenommen.' : 'Beleg erkannt.';
}

// --- Browser only from here on --------------------------------------------

/**
 * One live detection: the current video frame drawn into the small
 * `canvas` (vorschauGroesse), its pixels through `kantenErkennen()`. Null
 * when nothing was found or the frame could not be read (a detection error
 * must never break the camera - manual capture always stays).
 */
function liveErkennen(video, canvas, groesse) {
    if (canvas.width !== groesse.breite || canvas.height !== groesse.hoehe) {
        canvas.width = groesse.breite;
        canvas.height = groesse.hoehe;
    }
    try {
        const context = canvas.getContext('2d', { willReadFrequently: true });
        context.drawImage(video, 0, 0, groesse.breite, groesse.hoehe);

        return kantenErkennen(context.getImageData(0, 0, groesse.breite, groesse.hoehe));
    } catch (problem) {
        return null;
    }
}

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
    const overlay = dialog.querySelector('.kamera-overlay');
    const umriss = overlay === null ? null : overlay.querySelector('polygon');
    const statusText = dialog.querySelector('[data-kamera="status"]');
    const automatisch = dialog.querySelector('[data-kamera="automatisch"]');
    // Without the overlay markup (or kanten.js) the dialog is the plain
    // M5-4 camera: guide frame and manual capture only.
    const liveMoeglich = overlay !== null && umriss !== null && statusText !== null
        && typeof kantenErkennen === 'function';

    return new Promise(function (erfuellen) {
        let strom = null;
        let erledigt = false;
        let liveZeitgeber = null;
        let liveGestartet = false;
        let liveZustand = liveStart();
        let liveGroesse = null;
        const liveCanvas = liveMoeglich ? document.createElement('canvas') : null;

        function overlayZeichnen() {
            if (!liveMoeglich) {
                return;
            }
            const ecken = liveEcken(liveZustand);
            const abbildung = liveGroesse === null ? null
                : anzeigeAbbildung(liveGroesse.videoBreite, liveGroesse.videoHoehe, video.clientWidth, video.clientHeight);
            const punkte = liveGroesse === null ? '' : overlayPunkte(ecken, liveGroesse.faktor, abbildung);

            // An SVG element has no `hidden` property (HTMLElement only), so
            // the attribute is set directly.
            if (punkte === '') {
                overlay.setAttribute('hidden', '');
            } else {
                overlay.setAttribute('viewBox', '0 0 ' + video.clientWidth + ' ' + video.clientHeight);
                umriss.setAttribute('points', punkte);
                overlay.classList.toggle('kamera-overlay-stabil', liveZustand.stabil);
                overlay.removeAttribute('hidden');
            }

            const hinweis = liveHinweis(liveZustand.stabil, automatisch !== null && automatisch.checked);
            if (statusText.textContent !== hinweis) {
                statusText.textContent = hinweis;
            }
        }

        function livePlanen(verzoegerung) {
            liveZeitgeber = window.setTimeout(liveSchritt, verzoegerung);
        }

        function liveSchritt() {
            liveZeitgeber = null;
            if (erledigt) {
                return;
            }
            // Nothing to look at: a hidden tab, a frame not decoded yet, a
            // capture in progress. Check again later without detecting.
            if (document.hidden || video.readyState < 2 || ausloesen.disabled) {
                livePlanen(LIVE_INTERVALL_MS);

                return;
            }

            const groesse = vorschauGroesse(video.videoWidth, video.videoHeight, LIVE_BREITE);
            if (groesse === null) {
                livePlanen(LIVE_INTERVALL_MS);

                return;
            }
            groesse.videoBreite = video.videoWidth;
            groesse.videoHoehe = video.videoHeight;
            if (liveGroesse === null || liveGroesse.breite !== groesse.breite || liveGroesse.hoehe !== groesse.hoehe
                || liveGroesse.videoBreite !== groesse.videoBreite || liveGroesse.videoHoehe !== groesse.videoHoehe) {
                // A new frame size (e.g. the phone was turned): older quads
                // are in other coordinates.
                liveZustand = liveStart();
            }
            liveGroesse = groesse;

            const beginn = performance.now();
            const ecken = liveErkennen(video, liveCanvas, groesse);
            const dauer = performance.now() - beginn;

            liveZustand = liveFortschreiben(liveZustand, ecken, groesse.breite, groesse.hoehe);
            overlayZeichnen();

            if (liveZustand.stabil && automatisch !== null && automatisch.checked) {
                beiAusloesen();
            }
            if (!erledigt) {
                livePlanen(naechsteErkennungIn(dauer, LIVE_INTERVALL_MS));
            }
        }

        function liveStarten() {
            if (!liveMoeglich || liveGestartet || erledigt) {
                return;
            }
            liveGestartet = true;
            livePlanen(LIVE_INTERVALL_MS);
        }

        function anzeigeAnpassen() {
            rahmenSetzen();
            overlayZeichnen();
        }

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
            video.removeEventListener('loadeddata', liveStarten);
            if (automatisch !== null) {
                automatisch.removeEventListener('change', overlayZeichnen);
            }
            window.removeEventListener('resize', anzeigeAnpassen);
            if (liveZeitgeber !== null) {
                window.clearTimeout(liveZeitgeber);
                liveZeitgeber = null;
            }
            if (liveMoeglich) {
                overlay.setAttribute('hidden', '');
                statusText.textContent = '';
            }
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
            // The quad seen at this moment, in the photo's own pixels - only
            // when the live detection ran on this very frame size.
            const liveStartEcken = liveGroesse !== null && liveGroesse.videoBreite === canvas.width
                && liveGroesse.videoHoehe === canvas.height
                ? startEckenAusLive(liveZustand, liveGroesse.faktor, canvas.width, canvas.height)
                : null;
            ausloesen.disabled = true;
            canvas.toBlob(function (blob) {
                ausloesen.disabled = false;
                if (blob === null) {
                    fehlerText.textContent = 'Das Foto konnte nicht gespeichert werden. Bitte erneut auslösen.';
                    fehlerText.hidden = false;

                    return;
                }
                const foto = new File([blob], aufnahmeName(new Date()), { type: 'image/jpeg' });
                if (liveStartEcken !== null && typeof eckenVormerken === 'function') {
                    eckenVormerken(foto, liveStartEcken);
                }
                beenden(foto);
            }, 'image/jpeg', KAMERA_QUALITAET);
        }

        ausloesen.addEventListener('click', beiAusloesen);
        abbrechen.addEventListener('click', beiAbbrechen);
        dateiFeld.addEventListener('change', beiDatei);
        dialog.addEventListener('cancel', beiEscape);
        video.addEventListener('loadedmetadata', rahmenSetzen);
        video.addEventListener('loadeddata', liveStarten);
        window.addEventListener('resize', anzeigeAnpassen);
        if (automatisch !== null) {
            automatisch.addEventListener('change', overlayZeichnen);
        }

        fehlerText.hidden = true;
        rahmen.hidden = true;
        if (liveMoeglich) {
            overlay.setAttribute('hidden', '');
            statusText.textContent = '';
        }
        if (automatisch !== null && automatisch.closest('label') !== null) {
            automatisch.closest('label').hidden = !liveMoeglich;
        }
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
        LIVE_INTERVALL_MS,
        LIVE_BREITE,
        LIVE_STABIL_ANZAHL,
        LIVE_STABIL_TOLERANZ,
        vorschauGroesse,
        naechsteErkennungIn,
        eckenAbweichung,
        liveStart,
        liveFortschreiben,
        liveEcken,
        startEckenAusLive,
        anzeigeAbbildung,
        overlayPunkte,
        liveHinweis,
    };
}
