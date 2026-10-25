// public/js/drucken.js (issue #74): every `data-drucken` button is shown
// (it is rendered hidden, useless without JavaScript) and prints on click.

const test = require('node:test');
const assert = require('node:assert/strict');

const { initDrucken } = require('../../public/js/drucken.js');

function knopf() {
    const hoerer = [];
    return {
        hidden: true,
        hoerer,
        addEventListener(art, fn) { hoerer.push([art, fn]); },
    };
}

test('every print button is shown and prints on click', () => {
    const knoepfe = [knopf(), knopf()];
    let gedruckt = 0;
    const wurzel = {
        querySelectorAll(selektor) {
            assert.equal(selektor, '[data-drucken]');
            return knoepfe;
        },
    };

    assert.equal(initDrucken(wurzel, () => { gedruckt++; }), 2);
    for (const k of knoepfe) {
        assert.equal(k.hidden, false);
        assert.equal(k.hoerer.length, 1);
        assert.equal(k.hoerer[0][0], 'click');
        k.hoerer[0][1]();
    }
    assert.equal(gedruckt, 2);
});

test('a page without a print button is left alone', () => {
    assert.equal(initDrucken({ querySelectorAll: () => [] }, () => assert.fail('printed')), 0);
});
