// Drives /einreichen (docs/spec/03-erfassung-und-ki.md section 1, issue
// #24/M4-2): adding pages, reordering them, validating the form and sending
// it off. Built on public/js/upload.js (the chunk upload) and
// public/js/iban.js (the client-side IBAN check); loaded after both.
//
// Its own file rather than an inline script: the CSP is script-src 'self'
// without 'unsafe-inline', no on*-attributes (CLAUDE.md section 4). The form
// token comes from a data-* attribute on #einreichen, the same pattern
// public/js/speicher.js uses for its CSRF token.

/** Reorders one page; returns the input unchanged if either index is out of range. */
function seitenVerschieben(seiten, vonIndex, nachIndex) {
    if (vonIndex < 0 || vonIndex >= seiten.length || nachIndex < 0 || nachIndex >= seiten.length) {
        return seiten;
    }

    const kopie = seiten.slice();
    const genommen = kopie.splice(vonIndex, 1);
    kopie.splice(nachIndex, 0, genommen[0]);

    return kopie;
}

function seitenEntfernen(seiten, id) {
    return seiten.filter(function (seite) {
        return seite.id !== id;
    });
}

/**
 * HEIC/HEIF cannot be read by the browser's own image decoders reliably
 * enough to convert here (docs/spec/03-erfassung-und-ki.md section 1: "wird
 * im Browser, wenn möglich, konvertiert, sonst verständliche
 * Fehlermeldung") - this app takes the second path and says so clearly,
 * rather than uploading a file the server's magic-byte check would reject
 * anyway.
 */
function istHeic(datei) {
    const typ = (datei && datei.type) || '';
    const name = (datei && datei.name) || '';

    return typ === 'image/heic' || typ === 'image/heif' || /\.hei[cf]$/i.test(name);
}

/** The JSON body of POST /einreichen. */
function einreichenNutzlast(seiten, angaben) {
    return {
        blobs: seiten.map(function (seite) {
            return seite.blobId;
        }),
        name: angaben.name,
        email: angaben.email,
        erstattung: angaben.erstattung,
        iban: angaben.iban,
        kontoinhaber: angaben.kontoinhaber,
        freitext: angaben.freitext,
        kostenstelle: angaben.kostenstelle,
        datenschutz: angaben.datenschutz,
    };
}

/**
 * Uploads one page through the public route. `hochladen` is injectable so
 * tests can drive this without public/js/upload.js being loaded at all; in
 * the browser it defaults to the global dateiHochladen() both files share
 * (classic scripts, not modules - CLAUDE.md section 4).
 */
async function seiteHochladen(datei, optionen) {
    const einstellungen = optionen || {};
    const hochladen = einstellungen.hochladen || (typeof dateiHochladen === 'function' ? dateiHochladen : null);

    if (hochladen === null) {
        throw new Error('Kein Upload verfügbar.');
    }

    return hochladen(datei, {
        csrf: einstellungen.token,
        fetch: einstellungen.fetch,
        basis: '/einreichen/upload',
        onFortschritt: einstellungen.onFortschritt,
    });
}

/**
 * The final submit. Returns the parsed answer either way - a field-error
 * response (422) is not an exception, the caller decides what to show.
 */
async function absenden(seiten, angaben, optionen) {
    const einstellungen = optionen || {};
    const holen = einstellungen.fetch || (typeof fetch === 'function' ? fetch : null);

    if (holen === null) {
        throw new Error('Kein fetch verfügbar.');
    }

    const antwort = await holen('/einreichen', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': einstellungen.token || '' },
        body: JSON.stringify(einreichenNutzlast(seiten, angaben)),
    });
    const daten = await antwort.json();

    return { ok: antwort.ok, status: antwort.status, daten: daten };
}

function initEinreichen() {
    const wurzel = document.querySelector('#einreichen');
    const formular = wurzel === null ? null : wurzel.querySelector('#einreichen-formular');
    if (wurzel === null || formular === null) {
        // No vault yet - the page shows only the notice from the server.
        return;
    }

    const token = wurzel.dataset.token || '';
    const maxSeiten = Number(wurzel.dataset.maxSeiten) || 20;

    const seitenListe = wurzel.querySelector('#einreichen-seiten');
    const uploadFehler = wurzel.querySelector('#einreichen-upload-fehler');
    const ueberweisungBlock = wurzel.querySelector('#einreichen-ueberweisung');
    const ibanFeld = wurzel.querySelector('#einreichen-iban');
    const kontoinhaberFeld = wurzel.querySelector('#einreichen-kontoinhaber');
    const allgemeinerFehler = wurzel.querySelector('#einreichen-allgemein');
    const ladeAnzeige = wurzel.querySelector('#einreichen-lade');
    const absendenKnopf = wurzel.querySelector('#einreichen-absenden');
    const erfolgsBlock = wurzel.querySelector('#einreichen-erfolg');
    const referenzText = wurzel.querySelector('#einreichen-referenz');
    const neuKnopf = wurzel.querySelector('#einreichen-neu');

    let seiten = [];
    let naechsteId = 1;
    let gezogenId = null;

    function zeigeUploadFehler(text) {
        uploadFehler.textContent = text;
        uploadFehler.hidden = text === '';
    }

    function feldFehler(name, text) {
        const ziel = formular.querySelector('[data-fehler-fuer="' + name + '"]');
        if (ziel === null) {
            return;
        }
        ziel.textContent = text || '';
        ziel.hidden = !text;
    }

    function alleFehlerLeeren() {
        formular.querySelectorAll('[data-fehler-fuer]').forEach(function (ziel) {
            ziel.hidden = true;
            ziel.textContent = '';
        });
        allgemeinerFehler.hidden = true;
    }

    function seitenText(seite, index) {
        if (seite.status === 'laedt') {
            return 'Wird hochgeladen …';
        }

        return seite.status === 'fehler' ? seite.fehler || 'Fehlgeschlagen' : 'Seite ' + (index + 1);
    }

    function seitenKarte(seite, index) {
        const li = document.createElement('li');
        li.className = 'einreichen-seite';
        li.draggable = true;
        li.dataset.id = seite.id;

        const vorschau = document.createElement('div');
        vorschau.className = 'einreichen-seite-vorschau';
        if (seite.vorschauUrl !== null) {
            const bild = document.createElement('img');
            bild.src = seite.vorschauUrl;
            bild.alt = '';
            vorschau.appendChild(bild);
        } else {
            vorschau.textContent = 'PDF';
        }
        li.appendChild(vorschau);

        const status = document.createElement('p');
        status.className = 'einreichen-seite-status';
        status.textContent = seitenText(seite, index);
        li.appendChild(status);

        const knopfreihe = document.createElement('p');
        knopfreihe.className = 'einreichen-seite-knopfreihe';

        const hoch = document.createElement('button');
        hoch.type = 'button';
        hoch.className = 'knopf knopf-still';
        hoch.setAttribute('aria-label', 'Seite nach oben');
        hoch.textContent = '↑';
        hoch.disabled = index === 0;
        hoch.addEventListener('click', function () {
            seiten = seitenVerschieben(seiten, index, index - 1);
            renderSeiten();
        });

        const runter = document.createElement('button');
        runter.type = 'button';
        runter.className = 'knopf knopf-still';
        runter.setAttribute('aria-label', 'Seite nach unten');
        runter.textContent = '↓';
        runter.disabled = index === seiten.length - 1;
        runter.addEventListener('click', function () {
            seiten = seitenVerschieben(seiten, index, index + 1);
            renderSeiten();
        });

        const entfernen = document.createElement('button');
        entfernen.type = 'button';
        entfernen.className = 'knopf knopf-still';
        entfernen.setAttribute('aria-label', 'Seite entfernen');
        entfernen.textContent = '✕';
        entfernen.addEventListener('click', function () {
            if (seite.vorschauUrl !== null && typeof URL !== 'undefined' && URL.revokeObjectURL) {
                URL.revokeObjectURL(seite.vorschauUrl);
            }
            seiten = seitenEntfernen(seiten, seite.id);
            renderSeiten();
        });

        knopfreihe.appendChild(hoch);
        knopfreihe.appendChild(runter);
        knopfreihe.appendChild(entfernen);
        li.appendChild(knopfreihe);

        // Pointer-Drag zusätzlich zu den Knöpfen oben (Tastatur, kleine
        // Bildschirme): HTML5 drag and drop, keine on*-Attribute (CSP).
        li.addEventListener('dragstart', function () {
            gezogenId = seite.id;
            li.setAttribute('aria-grabbed', 'true');
        });
        li.addEventListener('dragend', function () {
            gezogenId = null;
            li.removeAttribute('aria-grabbed');
        });
        li.addEventListener('dragover', function (ereignis) {
            ereignis.preventDefault();
        });
        li.addEventListener('drop', function (ereignis) {
            ereignis.preventDefault();
            if (gezogenId === null || gezogenId === seite.id) {
                return;
            }
            const von = seiten.findIndex(function (s) {
                return s.id === gezogenId;
            });
            const nach = seiten.findIndex(function (s) {
                return s.id === seite.id;
            });
            if (von !== -1 && nach !== -1) {
                seiten = seitenVerschieben(seiten, von, nach);
                renderSeiten();
            }
        });

        return li;
    }

    function renderSeiten() {
        seitenListe.innerHTML = '';
        seiten.forEach(function (seite, index) {
            seitenListe.appendChild(seitenKarte(seite, index));
        });
    }

    async function dateiHinzufuegen(datei) {
        if (seiten.length >= maxSeiten) {
            zeigeUploadFehler('Es sind höchstens ' + maxSeiten + ' Seiten je Einreichung möglich.');

            return;
        }
        if (istHeic(datei)) {
            zeigeUploadFehler('HEIC-Bilder werden nicht unterstützt. Bitte als JPEG oder PNG wählen, oder das Foto direkt aufnehmen.');

            return;
        }

        zeigeUploadFehler('');
        const kannVorschau = typeof URL !== 'undefined' && datei.type.indexOf('image/') === 0;
        const seite = {
            id: 'seite-' + naechsteId++,
            status: 'laedt',
            blobId: null,
            fehler: null,
            vorschauUrl: kannVorschau ? URL.createObjectURL(datei) : null,
        };
        seiten = seiten.concat([seite]);
        renderSeiten();

        try {
            const ergebnis = await seiteHochladen(datei, { token: token });
            seite.status = 'fertig';
            seite.blobId = ergebnis.blob_id;
        } catch (problem) {
            seite.status = 'fehler';
            seite.fehler = problem.message;
        }
        renderSeiten();
    }

    function dateienVerarbeiten(dateien) {
        Array.prototype.slice.call(dateien || []).forEach(function (datei) {
            dateiHinzufuegen(datei);
        });
    }

    ['einreichen-foto', 'einreichen-bild', 'einreichen-pdf'].forEach(function (id) {
        const feld = wurzel.querySelector('#' + id);
        if (feld === null) {
            return;
        }
        feld.addEventListener('change', function () {
            dateienVerarbeiten(feld.files);
            feld.value = '';
        });
    });

    formular.querySelectorAll('input[name="erstattung"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            if (!radio.checked) {
                return;
            }
            const pflichtig = radio.value === 'ueberweisung';
            ueberweisungBlock.hidden = !pflichtig;
            ibanFeld.required = pflichtig;
            kontoinhaberFeld.required = pflichtig;
        });
    });

    function angabenAusFormular() {
        const daten = new FormData(formular);
        const email = String(daten.get('email') || '').trim();
        const iban = String(daten.get('iban') || '').trim();
        const kontoinhaber = String(daten.get('kontoinhaber') || '').trim();
        const kostenstelle = String(daten.get('kostenstelle') || '').trim();

        return {
            name: String(daten.get('name') || '').trim(),
            email: email === '' ? null : email,
            erstattung: daten.get('erstattung'),
            iban: iban === '' ? null : ibanNormalisieren(iban),
            kontoinhaber: kontoinhaber === '' ? null : kontoinhaber,
            freitext: String(daten.get('freitext') || '').trim(),
            kostenstelle: kostenstelle === '' ? null : kostenstelle,
            datenschutz: daten.get('datenschutz') === 'on',
        };
    }

    formular.addEventListener('submit', async function (ereignis) {
        ereignis.preventDefault();
        alleFehlerLeeren();

        if (seiten.length === 0) {
            feldFehler('seiten', 'Bitte mindestens eine Seite hinzufügen.');

            return;
        }
        if (seiten.some(function (seite) { return seite.status !== 'fertig'; })) {
            feldFehler('seiten', 'Bitte warten, bis alle Seiten hochgeladen sind, oder fehlgeschlagene Seiten entfernen.');

            return;
        }

        const angaben = angabenAusFormular();
        if (angaben.erstattung === 'ueberweisung' && angaben.iban !== null && !ibanIstGueltig(angaben.iban)) {
            feldFehler('iban', 'Das ist keine gültige IBAN.');

            return;
        }

        absendenKnopf.disabled = true;
        ladeAnzeige.hidden = false;
        try {
            const antwort = await absenden(seiten, angaben, { token: token });
            if (!antwort.ok) {
                const fehler = antwort.daten && antwort.daten.fehler;
                if (fehler !== null && typeof fehler === 'object') {
                    Object.keys(fehler).forEach(function (feld) {
                        feldFehler(feld, fehler[feld]);
                    });
                } else {
                    allgemeinerFehler.textContent = fehler || 'Die Einreichung ist fehlgeschlagen.';
                    allgemeinerFehler.hidden = false;
                }

                return;
            }

            referenzText.textContent = antwort.daten.referenz;
            formular.hidden = true;
            erfolgsBlock.hidden = false;
        } catch (problem) {
            allgemeinerFehler.textContent = 'Die Einreichung ist fehlgeschlagen: ' + problem.message;
            allgemeinerFehler.hidden = false;
        } finally {
            absendenKnopf.disabled = false;
            ladeAnzeige.hidden = true;
        }
    });

    if (neuKnopf !== null) {
        neuKnopf.addEventListener('click', function () {
            window.location.reload();
        });
    }
}

if (typeof document !== 'undefined') {
    initEinreichen();
}

// Node (tests/js) loads the same file for the pure helpers above; browsers
// ignore this block because `module` does not exist there.
if (typeof module === 'object' && module.exports) {
    module.exports = { seitenVerschieben, seitenEntfernen, istHeic, einreichenNutzlast, seiteHochladen, absenden };
}
