// IBAN check for the public submission (docs/spec/03-erfassung-und-ki.md
// section 1: "IBAN Pflicht, mit Prüfziffer-Validierung mod 97", issue
// #24/M4-2) - a client-side mirror of App\Domain\Iban so a mistyped IBAN is
// caught before the round trip. The server re-checks independently; this is
// convenience, not the security boundary.
//
// Its own file rather than an inline script: the CSP is script-src 'self'
// without 'unsafe-inline' (CLAUDE.md section 4).

/** @type {Object<string, number>} ISO country code => total IBAN length. */
const IBAN_LAENGE = {
    AD: 24, AT: 20, BE: 16, BG: 22, CH: 21, CY: 28,
    CZ: 24, DE: 22, DK: 18, EE: 20, ES: 24, FI: 18,
    FR: 27, GB: 22, GR: 27, HR: 21, HU: 28, IE: 22,
    IS: 26, IT: 27, LI: 21, LT: 20, LU: 20, LV: 21,
    MC: 27, MT: 31, NL: 18, NO: 15, PL: 28, PT: 25,
    RO: 24, SE: 24, SI: 19, SK: 24, SM: 27,
};

/** Upper case, without spaces - how an IBAN is compared and stored. */
function ibanNormalisieren(wert) {
    return (wert || '').replace(/\s+/g, '').toUpperCase();
}

/**
 * ISO 7064 MOD 97-10, the same algorithm as App\Domain\Iban::mod97(): move
 * the first four characters to the end, turn letters into two-digit numbers
 * (A=10 ... Z=35), reduce mod 97 digit by digit (the number is far longer
 * than a JS number can hold exactly).
 */
function ibanMod97(iban) {
    const umgestellt = iban.slice(4) + iban.slice(0, 4);
    let rest = 0;

    for (const zeichen of umgestellt) {
        const code = zeichen.charCodeAt(0);
        const stueck = code >= 65 && code <= 90 ? String(code - 65 + 10) : zeichen;
        for (const ziffer of stueck) {
            rest = (rest * 10 + Number(ziffer)) % 97;
        }
    }

    return rest;
}

function ibanIstGueltig(wert) {
    const iban = ibanNormalisieren(wert);

    if (!/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/.test(iban)) {
        return false;
    }

    const erwartet = IBAN_LAENGE[iban.slice(0, 2)];
    if (erwartet !== undefined ? iban.length !== erwartet : iban.length < 15 || iban.length > 34) {
        return false;
    }

    return ibanMod97(iban) === 1;
}

/** Grouped in fours for display: "DE89 3704 0044 0532 0130 00". */
function ibanFormatieren(wert) {
    const iban = ibanNormalisieren(wert);

    return (iban.match(/.{1,4}/g) || []).join(' ');
}

// Node (tests/js) loads the same file; browsers ignore this block because
// `module` does not exist there.
if (typeof module === 'object' && module.exports) {
    module.exports = { ibanNormalisieren, ibanIstGueltig, ibanFormatieren };
}
