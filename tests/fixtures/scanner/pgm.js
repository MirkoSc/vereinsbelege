// Minimal PGM (P5, binary grayscale) reader/writer for the scanner's
// synthetic test fixtures (erzeuge-referenz.js) - good enough for the fixed
// header this project ever writes (no comments, no P2/ASCII variant, maxval
// always 255), not a general-purpose PGM parser.

const fs = require('node:fs');

function schreibePgm(pfad, breite, hoehe, daten) {
    const header = Buffer.from(`P5\n${breite} ${hoehe}\n255\n`, 'ascii');
    fs.writeFileSync(pfad, Buffer.concat([header, Buffer.from(daten)]));
}

const LEERZEICHEN = new Set([0x20, 0x09, 0x0a, 0x0d]);

/** Returns { breite, hoehe, daten: Uint8ClampedArray }. */
function lesePgm(pfad) {
    const inhalt = fs.readFileSync(pfad);
    let position = 0;

    const naechstesToken = () => {
        while (position < inhalt.length && LEERZEICHEN.has(inhalt[position])) {
            position++;
        }
        const start = position;
        while (position < inhalt.length && !LEERZEICHEN.has(inhalt[position])) {
            position++;
        }
        return inhalt.slice(start, position).toString('ascii');
    };

    const magie = naechstesToken();
    if (magie !== 'P5') {
        throw new Error('Keine PGM-P5-Datei: ' + pfad);
    }
    const breite = Number(naechstesToken());
    const hoehe = Number(naechstesToken());
    naechstesToken(); // maxval - in diesen Fixtures immer 255

    const start = position + 1; // genau ein Whitespace-Byte trennt Header und Rohdaten
    const daten = new Uint8ClampedArray(inhalt.subarray(start, start + breite * hoehe));

    return { breite, hoehe, daten };
}

module.exports = { schreibePgm, lesePgm };
