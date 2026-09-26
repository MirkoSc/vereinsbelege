// Demo glue for the "Eck-Editor (Scanner)" section of /admin/designsystem
// (docs/spec/03-erfassung-und-ki.md section 2, issue #33/M5-3): loads a
// locally chosen test image, runs edge detection (M5-2) on it and binds the
// corner editor (public/js/scanner/eckeditor.js). Only exercised on this
// page - the chosen file never leaves the browser. Wiring the editor into
// /einreichen and the internal capture is M5-4.
//
// Its own file rather than an inline script: the CSP is script-src 'self'
// without 'unsafe-inline' (CLAUDE.md section 4). Loaded after every
// public/js/scanner/ file, so kantenErkennen(), standardRahmen(),
// eckEditorBinden() and ergebnisErzeugen() are already global functions
// here (classic scripts share one scope, like the rest of public/js/).

/** The chosen file's pixels as the { width, height, data } shape the scanner module functions expect. */
function bildAusBitmap(bitmap) {
    const canvas = document.createElement('canvas');
    canvas.width = bitmap.width;
    canvas.height = bitmap.height;
    const context = canvas.getContext('2d');
    context.drawImage(bitmap, 0, 0);

    return context.getImageData(0, 0, bitmap.width, bitmap.height);
}

function initDesignsystemEckEditor() {
    const dateiEingabe = document.getElementById('eck-editor-datei');
    const demo = document.getElementById('eck-editor-demo');
    const entzerrenKnopf = document.getElementById('eck-editor-entzerren');
    const ergebnisBereich = document.getElementById('eck-editor-ergebnis');
    if (dateiEingabe === null || demo === null || entzerrenKnopf === null || ergebnisBereich === null) {
        return;
    }

    let editor = null;
    let quelle = null;

    dateiEingabe.addEventListener('change', async () => {
        const datei = dateiEingabe.files[0];
        if (datei === undefined) {
            return;
        }

        quelle = await createImageBitmap(datei);
        const bild = bildAusBitmap(quelle);
        const gefunden = kantenErkennen(bild);
        const ecken = gefunden !== null ? gefunden : standardRahmen(quelle.width, quelle.height);

        ergebnisBereich.textContent = '';
        demo.hidden = false;
        entzerrenKnopf.disabled = false;
        editor = eckEditorBinden(demo.querySelector('.eck-editor'), quelle, ecken);
    });

    entzerrenKnopf.addEventListener('click', () => {
        if (editor === null || quelle === null) {
            return;
        }

        const bild = bildAusBitmap(quelle);
        const ergebnis = ergebnisErzeugen(bild, editor.ecken(), editor.farbmodus());

        const ausgabeCanvas = document.createElement('canvas');
        ausgabeCanvas.width = ergebnis.width;
        ausgabeCanvas.height = ergebnis.height;
        ausgabeCanvas.getContext('2d').putImageData(
            new ImageData(ergebnis.data, ergebnis.width, ergebnis.height),
            0,
            0,
        );

        const ausgabeBild = document.createElement('img');
        ausgabeBild.alt = 'Entzerrtes Ergebnis';
        ausgabeBild.src = ausgabeCanvas.toDataURL('image/jpeg', 0.85);

        ergebnisBereich.textContent = '';
        ergebnisBereich.appendChild(ausgabeBild);
    });
}

if (typeof document !== 'undefined') {
    initDesignsystemEckEditor();
}
