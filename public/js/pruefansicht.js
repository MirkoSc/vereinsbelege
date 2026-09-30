// The review page (issue #37/M6-3, docs/spec/03-erfassung-und-ki.md
// section 6 "Prüfansicht", app/views/app/pruefen.php): flips through the
// pages of the receipt, zooms, and narrows the category and partner choices
// to the chosen direction.
//
// Progressive enhancement only: without this file every page shows one
// below the other and every choice is offered - the server checks direction,
// category and partner again (App\Service\Invoice\Pruefung). Only hidden
// attributes and classes change; the CSP allows no inline styles
// (CLAUDE.md section 4).

/** Zoom steps; .zoom-1 ... .zoom-4 in public/css/app.css. */
const ZOOM_STUFEN = [100, 150, 200, 300];

/** A page index kept inside 0 ... anzahl - 1 (0 when there are none). */
function seiteKlemmen(index, anzahl) {
    if (anzahl <= 0) {
        return 0;
    }

    return Math.min(Math.max(index, 0), anzahl - 1);
}

/** "Seite 2 von 5". */
function seitenText(index, anzahl) {
    return 'Seite ' + (index + 1) + ' von ' + anzahl;
}

/** The zoom step one further in $richtung (+1/-1), kept inside the steps. */
function zoomStufe(stufe, richtung) {
    return Math.min(Math.max(stufe + richtung, 0), ZOOM_STUFEN.length - 1);
}

/** Whether a category group (einnahme/ausgabe/beide) fits the direction. */
function kategoriePasst(richtung, gruppe) {
    return gruppe === 'beide' || gruppe === richtung;
}

/** Whether a partner role (lieferant/zahler/beide) fits the direction. */
function rollePasst(richtung, rolle) {
    const erwartet = richtung === 'einnahme' ? 'zahler' : 'lieferant';

    return rolle === 'beide' || rolle === erwartet;
}

function initBetrachter(betrachter) {
    const liste = betrachter.querySelector('.beleg-seiten');
    const werkzeuge = betrachter.querySelector('.beleg-werkzeuge');
    if (liste === null || werkzeuge === null) {
        return;
    }

    const seiten = Array.from(liste.querySelectorAll('.beleg-seite'));
    const seitenzahl = werkzeuge.querySelector('.beleg-seitenzahl');
    const zoomAnzeige = werkzeuge.querySelector('.beleg-zoom');
    const knopf = (name) => werkzeuge.querySelector('[data-beleg="' + name + '"]');
    let aktuell = 0;
    let stufe = 0;

    function zeige() {
        seiten.forEach((seite, i) => {
            seite.hidden = i !== aktuell;
        });
        seitenzahl.textContent = seitenText(aktuell, seiten.length);
        knopf('zurueck').disabled = aktuell === 0;
        knopf('weiter').disabled = aktuell === seiten.length - 1;

        ZOOM_STUFEN.forEach((_, i) => liste.classList.toggle('zoom-' + (i + 1), i === stufe));
        zoomAnzeige.textContent = ZOOM_STUFEN[stufe] + ' %';
        knopf('kleiner').disabled = stufe === 0;
        knopf('groesser').disabled = stufe === ZOOM_STUFEN.length - 1;
    }

    function blaettern(richtung) {
        aktuell = seiteKlemmen(aktuell + richtung, seiten.length);
        liste.scrollTop = 0;
        zeige();
    }

    knopf('zurueck').addEventListener('click', () => blaettern(-1));
    knopf('weiter').addEventListener('click', () => blaettern(1));
    knopf('kleiner').addEventListener('click', () => {
        stufe = zoomStufe(stufe, -1);
        zeige();
    });
    knopf('groesser').addEventListener('click', () => {
        stufe = zoomStufe(stufe, 1);
        zeige();
    });

    // A single page needs no flipping, only zoom.
    if (seiten.length < 2) {
        [knopf('zurueck'), knopf('weiter'), seitenzahl].forEach((element) => {
            element.hidden = true;
        });
    }
    werkzeuge.hidden = false;
    zeige();
}

/**
 * Hides the option groups that do not fit the direction and clears a
 * choice that is no longer visible. `disabled` as well as `hidden`: some
 * mobile browsers ignore hidden on option groups.
 */
function filtereAuswahl(select, attribut, passt, richtung) {
    if (select === null) {
        return;
    }
    select.querySelectorAll('optgroup').forEach((gruppe) => {
        const sichtbar = passt(richtung, gruppe.getAttribute(attribut));
        gruppe.hidden = !sichtbar;
        gruppe.disabled = !sichtbar;
    });
    const gewaehlt = select.selectedOptions[0];
    if (gewaehlt !== undefined && gewaehlt.parentElement.tagName === 'OPTGROUP' && gewaehlt.parentElement.disabled) {
        select.value = '';
    }
}

function initRichtung(formular) {
    const kategorie = formular.querySelector('#pruefen-kategorie');
    const lieferant = formular.querySelector('#pruefen-lieferant');
    const knoepfe = Array.from(formular.querySelectorAll('input[name="richtung"]'));

    function anwenden() {
        const gewaehlt = knoepfe.find((knopf) => knopf.checked);
        if (gewaehlt === undefined) {
            return;
        }
        filtereAuswahl(kategorie, 'data-richtung', kategoriePasst, gewaehlt.value);
        filtereAuswahl(lieferant, 'data-rolle', rollePasst, gewaehlt.value);
    }

    knoepfe.forEach((knopf) => knopf.addEventListener('change', anwenden));
    anwenden();
}

function initPruefansicht() {
    document.querySelectorAll('.beleg-betrachter').forEach(initBetrachter);
    document.querySelectorAll('.pruefen-formular').forEach(initRichtung);
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPruefansicht);
    } else {
        initPruefansicht();
    }
}

// Node (tests/js) loads the same file for the pure helpers above; browsers
// ignore this block because `module` does not exist there.
if (typeof module === 'object' && module.exports) {
    module.exports = { ZOOM_STUFEN, seiteKlemmen, seitenText, zoomStufe, kategoriePasst, rollePasst };
}
