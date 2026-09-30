const test = require('node:test');
const assert = require('node:assert/strict');
const { ZOOM_STUFEN, seiteKlemmen, seitenText, zoomStufe, kategoriePasst, rollePasst } = require('../../public/js/pruefansicht.js');

// The review page (issue #37/M6-3): page flipping, zoom and the direction
// filter of public/js/pruefansicht.js.

test('flipping stays inside the pages', () => {
    assert.equal(seiteKlemmen(0, 3), 0);
    assert.equal(seiteKlemmen(2, 3), 2);
    assert.equal(seiteKlemmen(3, 3), 2, 'past the last page');
    assert.equal(seiteKlemmen(-1, 3), 0, 'before the first page');
    assert.equal(seiteKlemmen(5, 0), 0, 'no pages at all');
    assert.equal(seiteKlemmen(1, 1), 0, 'a single page');
});

test('the page counter is 1-based', () => {
    assert.equal(seitenText(0, 3), 'Seite 1 von 3');
    assert.equal(seitenText(2, 3), 'Seite 3 von 3');
});

test('zoom steps up and down and stops at both ends', () => {
    assert.deepEqual(ZOOM_STUFEN, [100, 150, 200, 300]);
    assert.equal(zoomStufe(0, 1), 1);
    assert.equal(zoomStufe(1, -1), 0);
    assert.equal(zoomStufe(0, -1), 0, 'not below 100 %');
    assert.equal(zoomStufe(ZOOM_STUFEN.length - 1, 1), ZOOM_STUFEN.length - 1, 'not above the last step');
});

test('categories fit their direction, "beide" fits both', () => {
    assert.equal(kategoriePasst('ausgabe', 'ausgabe'), true);
    assert.equal(kategoriePasst('ausgabe', 'einnahme'), false);
    assert.equal(kategoriePasst('einnahme', 'einnahme'), true);
    assert.equal(kategoriePasst('einnahme', 'ausgabe'), false);
    assert.equal(kategoriePasst('ausgabe', 'beide'), true);
    assert.equal(kategoriePasst('einnahme', 'beide'), true);
});

test('an expense takes suppliers, income takes payers, "beide" fits both', () => {
    assert.equal(rollePasst('ausgabe', 'lieferant'), true);
    assert.equal(rollePasst('ausgabe', 'zahler'), false);
    assert.equal(rollePasst('einnahme', 'zahler'), true);
    assert.equal(rollePasst('einnahme', 'lieferant'), false);
    assert.equal(rollePasst('ausgabe', 'beide'), true);
    assert.equal(rollePasst('einnahme', 'beide'), true);
});
