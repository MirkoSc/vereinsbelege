// Drives the internal capture /app/belege/neu (docs/spec/03-erfassung-und-ki.md
// section 1, issue #28/M4-6): several receipts in one pass, one card per
// receipt. Every chosen file starts a receipt of its own; "Seite hinzufügen"
// in a card adds pages to that receipt. Built on public/js/upload.js (the
// chunk upload through /api/upload), public/js/iban.js (the IBAN check) and
// the page helpers of public/js/einreichen.js - loaded after all three.
//
// Its own file rather than an inline script: the CSP is script-src 'self'
// without 'unsafe-inline', no on*-attributes (CLAUDE.md section 4). The CSRF
// token and the capture id come from data-* attributes on #erfassen.

// The page helpers of the public submission: globals in the browser (classic
// scripts), a require() in Node (tests/js).
const erfassenHilfen = typeof module === 'object' && module.exports
    ? require('./einreichen.js')
    : { seitenVerschieben: seitenVerschieben, seitenEntfernen: seitenEntfernen, istHeic: istHeic };

/** A new, empty receipt at the end of the list. */
function belegAnlegen(belege, id) {
    return belege.concat([{ id: id, seiten: [] }]);
}

function belegEntfernen(belege, id) {
    return belege.filter(function (beleg) {
        return beleg.id !== id;
    });
}

/** Replaces the page list of one receipt with what `aendern` makes of it. */
function seitenAendern(belege, belegId, aendern) {
    return belege.map(function (beleg) {
        return beleg.id === belegId ? { id: beleg.id, seiten: aendern(beleg.seiten) } : beleg;
    });
}

/**
 * Why the pass cannot be sent yet, or null: every receipt needs at least one
 * page, and every page must have finished uploading.
 */
function belegeBereit(belege) {
    if (belege.length === 0) {
        return 'Bitte mindestens einen Beleg hinzufügen.';
    }

    const unfertig = belege.some(function (beleg) {
        return beleg.seiten.length === 0 || beleg.seiten.some(function (seite) {
            return seite.status !== 'fertig';
        });
    });

    return unfertig
        ? 'Bitte warten, bis alle Seiten hochgeladen sind, oder leere Belege und fehlgeschlagene Seiten entfernen.'
        : null;
}

/**
 * The JSON body of POST /app/belege/neu. `angaben` holds the form values of
 * each receipt, in the same order as `belege`.
 */
function erfassenNutzlast(erfassung, belege, angaben) {
    return {
        erfassung: erfassung,
        belege: belege.map(function (beleg, index) {
            const werte = angaben[index] || {};

            return {
                blobs: beleg.seiten.map(function (seite) {
                    return seite.blobId;
                }),
                freitext: werte.freitext || '',
                kostenstelle: werte.kostenstelle || null,
                erstattung: werte.erstattung || null,
                iban: werte.erstattung === 'ueberweisung' ? werte.iban || null : null,
                kontoinhaber: werte.erstattung === 'ueberweisung' ? werte.kontoinhaber || null : null,
            };
        }),
    };
}

/**
 * Splits the server's error answer: `fehler` is either a sentence, or an
 * object keyed "<receipt index>.<field>" and "belege" for the pass as a
 * whole (App\Service\Submission\InterneErfassung).
 */
function fehlerZuordnen(fehler) {
    const ergebnis = { allgemein: null, jeBeleg: {} };

    if (fehler === null || typeof fehler !== 'object') {
        ergebnis.allgemein = fehler || 'Die Erfassung ist fehlgeschlagen.';

        return ergebnis;
    }

    Object.keys(fehler).forEach(function (schluessel) {
        const treffer = /^(\d+)\.(\w+)$/.exec(schluessel);
        if (treffer === null) {
            ergebnis.allgemein = ergebnis.allgemein === null ? fehler[schluessel] : ergebnis.allgemein + ' ' + fehler[schluessel];

            return;
        }

        const index = Number(treffer[1]);
        ergebnis.jeBeleg[index] = ergebnis.jeBeleg[index] || {};
        ergebnis.jeBeleg[index][treffer[2]] = fehler[schluessel];
    });

    return ergebnis;
}

/**
 * Uploads one page through the session's /api/upload with the capture id
 * in X-Erfassung (App\Api\UploadController records the blob for this page
 * load). `hochladen` and `fetch` are injectable for the tests.
 */
async function erfassenHochladen(datei, optionen) {
    const einstellungen = optionen || {};
    const hochladen = einstellungen.hochladen || (typeof dateiHochladen === 'function' ? dateiHochladen : null);

    if (hochladen === null) {
        throw new Error('Kein Upload verfügbar.');
    }

    return hochladen(datei, {
        csrf: einstellungen.csrf,
        fetch: einstellungen.fetch,
        basis: '/api/upload',
        headers: { 'X-Erfassung': einstellungen.erfassung || '' },
        onFortschritt: einstellungen.onFortschritt,
    });
}

/**
 * The final submit. Returns the parsed answer either way - a field-error
 * response (422) is not an exception, the caller decides what to show.
 */
async function erfassenAbsenden(nutzlast, optionen) {
    const einstellungen = optionen || {};
    const holen = einstellungen.fetch || (typeof fetch === 'function' ? fetch : null);

    if (holen === null) {
        throw new Error('Kein fetch verfügbar.');
    }

    const antwort = await holen('/app/belege/neu', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': einstellungen.csrf || '' },
        body: JSON.stringify(nutzlast),
    });
    const daten = await antwort.json();

    return { ok: antwort.ok, status: antwort.status, daten: daten };
}

function initErfassen() {
    const wurzel = document.querySelector('#erfassen');
    const formular = wurzel === null ? null : wurzel.querySelector('#erfassen-formular');
    const vorlage = wurzel === null ? null : wurzel.querySelector('#erfassen-vorlage');
    if (wurzel === null || formular === null || vorlage === null) {
        // No vault yet - the page shows only the notice from the server.
        return;
    }

    const csrf = wurzel.dataset.csrf || '';
    const erfassung = wurzel.dataset.erfassung || '';
    const maxBelege = Number(wurzel.dataset.maxBelege) || 50;
    const maxSeiten = Number(wurzel.dataset.maxSeiten) || 50;
    const maxDateiMb = Number(wurzel.dataset.maxDateiMb) || 32;
    const maxDateiBytes = maxDateiMb * 1024 * 1024;

    const liste = wurzel.querySelector('#erfassen-belege');
    const leer = wurzel.querySelector('#erfassen-leer');
    const uploadFehler = wurzel.querySelector('#erfassen-upload-fehler');
    const allgemeinerFehler = wurzel.querySelector('#erfassen-allgemein');
    const belegeFehler = formular.querySelector('[data-fehler-fuer="belege"]');
    const ladeAnzeige = wurzel.querySelector('#erfassen-lade');
    const absendenKnopf = wurzel.querySelector('#erfassen-absenden');
    const erfolgsBlock = wurzel.querySelector('#erfassen-erfolg');
    const erfolgsText = wurzel.querySelector('#erfassen-erfolg-text');
    const referenzListe = wurzel.querySelector('#erfassen-referenzen');
    const neuKnopf = wurzel.querySelector('#erfassen-neu');
    const quellen = wurzel.querySelector('#erfassen-quellen');

    let belege = [];
    let naechsteId = 1;
    // The card element of each receipt, kept across renders so what was
    // typed into it survives adding or removing other receipts.
    const karten = {};
    // One upload after the other: fifty files chosen at once must not put
    // fifty uploads on a mobile connection in parallel.
    let warteschlange = Promise.resolve();
    let gezogen = null;

    function zeigeUploadFehler(text) {
        uploadFehler.textContent = text;
        uploadFehler.hidden = text === '';
    }

    function feldFehler(ziel, text) {
        if (ziel === null) {
            return;
        }
        ziel.textContent = text || '';
        ziel.hidden = !text;
    }

    function alleFehlerLeeren() {
        formular.querySelectorAll('[data-fehler-fuer]').forEach(function (ziel) {
            feldFehler(ziel, '');
        });
        allgemeinerFehler.hidden = true;
    }

    function finde(belegId) {
        return belege.find(function (beleg) {
            return beleg.id === belegId;
        });
    }

    function seitenText(seite, index) {
        if (seite.status === 'laedt') {
            return 'Wird hochgeladen …';
        }

        return seite.status === 'fehler' ? seite.fehler || 'Fehlgeschlagen' : 'Seite ' + (index + 1);
    }

    function knopf(text, bezeichnung, deaktiviert, aktion) {
        const element = document.createElement('button');
        element.type = 'button';
        element.className = 'knopf knopf-still';
        element.setAttribute('aria-label', bezeichnung);
        element.textContent = text;
        element.disabled = deaktiviert;
        element.addEventListener('click', aktion);

        return element;
    }

    function vorschauFreigeben(seite) {
        if (seite.vorschauUrl !== null && typeof URL !== 'undefined' && URL.revokeObjectURL) {
            URL.revokeObjectURL(seite.vorschauUrl);
        }
    }

    function seitenKarte(belegId, seite, index, anzahl) {
        const li = document.createElement('li');
        li.className = 'einreichen-seite';
        li.draggable = true;

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
        knopfreihe.appendChild(knopf('↑', 'Seite nach oben', index === 0, function () {
            belege = seitenAendern(belege, belegId, function (seiten) {
                return erfassenHilfen.seitenVerschieben(seiten, index, index - 1);
            });
            renderSeiten(belegId);
        }));
        knopfreihe.appendChild(knopf('↓', 'Seite nach unten', index === anzahl - 1, function () {
            belege = seitenAendern(belege, belegId, function (seiten) {
                return erfassenHilfen.seitenVerschieben(seiten, index, index + 1);
            });
            renderSeiten(belegId);
        }));
        knopfreihe.appendChild(knopf('✕', 'Seite entfernen', false, function () {
            vorschauFreigeben(seite);
            belege = seitenAendern(belege, belegId, function (seiten) {
                return erfassenHilfen.seitenEntfernen(seiten, seite.id);
            });
            renderSeiten(belegId);
        }));
        li.appendChild(knopfreihe);

        // Pointer drag within one receipt, in addition to the buttons above
        // (keyboard, small screens): HTML5 drag and drop, no on*-attributes.
        li.addEventListener('dragstart', function () {
            gezogen = { belegId: belegId, seitenId: seite.id };
            li.setAttribute('aria-grabbed', 'true');
        });
        li.addEventListener('dragend', function () {
            gezogen = null;
            li.removeAttribute('aria-grabbed');
        });
        li.addEventListener('dragover', function (ereignis) {
            ereignis.preventDefault();
        });
        li.addEventListener('drop', function (ereignis) {
            ereignis.preventDefault();
            if (gezogen === null || gezogen.belegId !== belegId || gezogen.seitenId === seite.id) {
                return;
            }
            const seitenId = gezogen.seitenId;
            belege = seitenAendern(belege, belegId, function (seiten) {
                const von = seiten.findIndex(function (s) {
                    return s.id === seitenId;
                });
                const nach = seiten.findIndex(function (s) {
                    return s.id === seite.id;
                });

                return von === -1 || nach === -1 ? seiten : erfassenHilfen.seitenVerschieben(seiten, von, nach);
            });
            renderSeiten(belegId);
        });

        return li;
    }

    function renderSeiten(belegId) {
        const beleg = finde(belegId);
        const karte = karten[belegId];
        if (beleg === undefined || karte === undefined) {
            return;
        }

        const seitenListe = karte.querySelector('[data-rolle="seiten"]');
        seitenListe.innerHTML = '';
        beleg.seiten.forEach(function (seite, index) {
            seitenListe.appendChild(seitenKarte(belegId, seite, index, beleg.seiten.length));
        });
    }

    /** Order and numbering of the cards; the cards themselves are kept. */
    function renderBelege() {
        belege.forEach(function (beleg, index) {
            const karte = karten[beleg.id];
            karte.querySelector('.erfassen-beleg-titel').textContent = 'Beleg ' + (index + 1);
            liste.appendChild(karte);
        });
        leer.hidden = belege.length > 0;
    }

    function karteAnlegen(belegId) {
        const karte = vorlage.content.firstElementChild.cloneNode(true);
        karte.dataset.beleg = belegId;

        // Radio groups need a name of their own per card.
        karte.querySelectorAll('input[data-feld="erstattung"]').forEach(function (radio) {
            radio.name = 'erstattung-' + belegId;
            radio.addEventListener('change', function () {
                if (radio.checked) {
                    karte.querySelector('[data-rolle="ueberweisung"]').hidden = radio.value !== 'ueberweisung';
                }
            });
        });

        karte.querySelector('[data-aktion="beleg-entfernen"]').addEventListener('click', function () {
            const beleg = finde(belegId);
            if (beleg !== undefined) {
                beleg.seiten.forEach(vorschauFreigeben);
            }
            belege = belegEntfernen(belege, belegId);
            karte.remove();
            delete karten[belegId];
            renderBelege();
        });

        const seiteFeld = karte.querySelector('[data-rolle="seite-hinzufuegen"]');
        seiteFeld.addEventListener('change', function () {
            Array.prototype.slice.call(seiteFeld.files || []).forEach(function (datei) {
                seiteHinzufuegen(belegId, datei);
            });
            seiteFeld.value = '';
        });

        karten[belegId] = karte;

        return karte;
    }

    function dateiPruefen(datei) {
        if (erfassenHilfen.istHeic(datei)) {
            return 'HEIC-Bilder werden nicht unterstützt. Bitte als JPEG oder PNG wählen, oder das Foto direkt aufnehmen.';
        }
        if (datei.size > maxDateiBytes) {
            return 'Die Datei ist zu groß (höchstens ' + maxDateiMb + ' MB je Datei).';
        }

        return null;
    }

    function seiteHinzufuegen(belegId, datei) {
        const beleg = finde(belegId);
        if (beleg === undefined) {
            return;
        }
        if (beleg.seiten.length >= maxSeiten) {
            zeigeUploadFehler('Es sind höchstens ' + maxSeiten + ' Seiten je Beleg möglich.');

            return;
        }
        const problem = dateiPruefen(datei);
        if (problem !== null) {
            zeigeUploadFehler(problem);

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
        belege = seitenAendern(belege, belegId, function (seiten) {
            return seiten.concat([seite]);
        });
        renderSeiten(belegId);

        warteschlange = warteschlange.then(async function () {
            try {
                const ergebnis = await erfassenHochladen(datei, { csrf: csrf, erfassung: erfassung });
                seite.status = 'fertig';
                seite.blobId = ergebnis.blob_id;
            } catch (fehler) {
                seite.status = 'fehler';
                seite.fehler = fehler.message;
            }
            renderSeiten(belegId);
        });
    }

    /** Every chosen file starts a receipt of its own. */
    function belegeAusDateien(dateien) {
        Array.prototype.slice.call(dateien || []).forEach(function (datei) {
            if (belege.length >= maxBelege) {
                zeigeUploadFehler('Es sind höchstens ' + maxBelege + ' Belege auf einmal möglich.');

                return;
            }
            const problem = dateiPruefen(datei);
            if (problem !== null) {
                zeigeUploadFehler(problem);

                return;
            }

            const belegId = 'beleg-' + naechsteId++;
            belege = belegAnlegen(belege, belegId);
            karteAnlegen(belegId);
            renderBelege();
            seiteHinzufuegen(belegId, datei);
        });
    }

    ['erfassen-foto', 'erfassen-bild', 'erfassen-pdf'].forEach(function (id) {
        const feld = wurzel.querySelector('#' + id);
        if (feld === null) {
            return;
        }
        feld.addEventListener('change', function () {
            belegeAusDateien(feld.files);
            feld.value = '';
        });
    });

    function wert(karte, feld) {
        const element = karte.querySelector('[data-feld="' + feld + '"]');

        return element === null ? '' : String(element.value || '').trim();
    }

    function angabenAusKarte(karte) {
        const gewaehlt = karte.querySelector('input[data-feld="erstattung"]:checked');
        const iban = wert(karte, 'iban');

        return {
            freitext: wert(karte, 'freitext'),
            kostenstelle: wert(karte, 'kostenstelle'),
            erstattung: gewaehlt === null ? '' : gewaehlt.value,
            iban: iban === '' ? '' : ibanNormalisieren(iban),
            kontoinhaber: wert(karte, 'kontoinhaber'),
        };
    }

    function kartenFehler(index, felder) {
        const beleg = belege[index];
        const karte = beleg === undefined ? undefined : karten[beleg.id];
        if (karte === undefined) {
            return;
        }
        Object.keys(felder).forEach(function (feld) {
            feldFehler(karte.querySelector('[data-fehler-fuer="' + feld + '"]'), felder[feld]);
            if (feld === 'erstattung' || feld === 'iban' || feld === 'kontoinhaber') {
                karte.querySelector('details').open = true;
            }
        });
    }

    formular.addEventListener('submit', async function (ereignis) {
        ereignis.preventDefault();
        alleFehlerLeeren();

        const nichtBereit = belegeBereit(belege);
        if (nichtBereit !== null) {
            feldFehler(belegeFehler, nichtBereit);

            return;
        }

        const angaben = belege.map(function (beleg) {
            return angabenAusKarte(karten[beleg.id]);
        });
        let ibanFalsch = false;
        angaben.forEach(function (werte, index) {
            if (werte.erstattung === 'ueberweisung' && werte.iban !== '' && !ibanIstGueltig(werte.iban)) {
                kartenFehler(index, { iban: 'Das ist keine gültige IBAN.' });
                ibanFalsch = true;
            }
        });
        if (ibanFalsch) {
            return;
        }

        absendenKnopf.disabled = true;
        ladeAnzeige.hidden = false;
        try {
            const antwort = await erfassenAbsenden(erfassenNutzlast(erfassung, belege, angaben), { csrf: csrf });
            if (!antwort.ok) {
                const zuordnung = fehlerZuordnen(antwort.daten && antwort.daten.fehler);
                Object.keys(zuordnung.jeBeleg).forEach(function (index) {
                    kartenFehler(Number(index), zuordnung.jeBeleg[index]);
                });
                if (zuordnung.allgemein !== null) {
                    allgemeinerFehler.textContent = zuordnung.allgemein;
                    allgemeinerFehler.hidden = false;
                }

                return;
            }

            const referenzen = antwort.daten.referenzen || [];
            erfolgsText.textContent = referenzen.length === 1
                ? '1 Beleg erfasst. Er liegt jetzt im Posteingang.'
                : referenzen.length + ' Belege erfasst. Sie liegen jetzt im Posteingang.';
            referenzListe.innerHTML = '';
            referenzen.forEach(function (referenz) {
                const eintrag = document.createElement('li');
                eintrag.textContent = referenz;
                referenzListe.appendChild(eintrag);
            });
            // Done: nothing more may be added to this pass - the next one
            // starts with a fresh capture id ("Weitere Belege erfassen").
            formular.hidden = true;
            quellen.hidden = true;
            erfolgsBlock.hidden = false;
        } catch (fehler) {
            allgemeinerFehler.textContent = 'Die Erfassung ist fehlgeschlagen: ' + fehler.message;
            allgemeinerFehler.hidden = false;
        } finally {
            absendenKnopf.disabled = false;
            ladeAnzeige.hidden = true;
        }
    });

    if (neuKnopf !== null) {
        // A reload hands out a fresh capture id - the next pass starts clean.
        neuKnopf.addEventListener('click', function () {
            window.location.reload();
        });
    }
}

if (typeof document !== 'undefined') {
    initErfassen();
}

// Node (tests/js) loads the same file for the pure helpers above; browsers
// ignore this block because `module` does not exist there.
if (typeof module === 'object' && module.exports) {
    module.exports = {
        belegAnlegen,
        belegEntfernen,
        seitenAendern,
        belegeBereit,
        erfassenNutzlast,
        fehlerZuordnen,
        erfassenHochladen,
        erfassenAbsenden,
    };
}
