const test = require('node:test');
const assert = require('node:assert/strict');
const { ibanNormalisieren, ibanIstGueltig, ibanFormatieren } = require('../../public/js/iban.js');

test('a known-good IBAN is valid, with or without spaces and case', () => {
    assert.equal(ibanIstGueltig('DE89370400440532013000'), true);
    assert.equal(ibanIstGueltig('DE89 3704 0044 0532 0130 00'), true);
    assert.equal(ibanIstGueltig('de89 3704 0044 0532 0130 00'), true);
});

test('a wrong check digit is rejected', () => {
    assert.equal(ibanIstGueltig('DE89370400440532013001'), false);
});

test('a length that does not match the country is rejected', () => {
    assert.equal(ibanIstGueltig('DE8937040044053201300'), false, 'one digit short');
    assert.equal(ibanIstGueltig('DE893704004405320130000'), false, 'one digit long');
});

test('a country outside the known table still gets the checksum check', () => {
    // XK (Kosovo) is not in the length table; check digits computed so the
    // checksum itself is genuinely valid.
    assert.equal(ibanIstGueltig('XK221212012345678'), true);
    assert.equal(ibanIstGueltig('XK231212012345678'), false, 'wrong check digits');
});

test('garbage is rejected without throwing', () => {
    assert.equal(ibanIstGueltig(''), false);
    assert.equal(ibanIstGueltig('not an iban'), false);
    assert.equal(ibanIstGueltig('DE'), false);
});

test('normalising strips whitespace and upper-cases', () => {
    assert.equal(ibanNormalisieren(' de89 3704 0044 0532 0130 00 '), 'DE89370400440532013000');
});

test('formatting groups the normalised value in fours', () => {
    assert.equal(ibanFormatieren('de89370400440532013000'), 'DE89 3704 0044 0532 0130 00');
});
