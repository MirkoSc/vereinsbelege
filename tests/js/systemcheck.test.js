// Logic of public/js/systemcheck.js (CLAUDE.md section 8: JS logic is
// tested with node --test). The DOM wiring is not covered - the file guards
// its init behind `typeof document`, so requiring it loads the helpers only.

const test = require('node:test');
const assert = require('node:assert/strict');

const { kopiere, kopiertText } = require('../../public/js/systemcheck.js');

test('the clipboard API gets the text when the page may use it', async () => {
    const geschrieben = [];
    const navigatorLike = { clipboard: { writeText: async (t) => { geschrieben.push(t); } } };

    assert.equal(await kopiere('{"status":"ok"}', navigatorLike, () => false), true);
    assert.deepEqual(geschrieben, ['{"status":"ok"}']);
});

test('a denied clipboard falls back to selecting and copying', async () => {
    const navigatorLike = { clipboard: { writeText: async () => { throw new Error('denied'); } } };
    let fallbackBenutzt = false;

    const erfolg = await kopiere('x', navigatorLike, () => { fallbackBenutzt = true; return true; });

    assert.equal(erfolg, true);
    assert.equal(fallbackBenutzt, true);
});

test('without a clipboard API the fallback decides', async () => {
    assert.equal(await kopiere('x', {}, () => true), true);
    assert.equal(await kopiere('x', {}, () => false), false);
    assert.equal(await kopiere('x', undefined, undefined), false);
});

test('the message says whether it worked and what to do otherwise', () => {
    assert.equal(kopiertText(true), 'Kopiert.');
    assert.match(kopiertText(false), /von Hand kopieren/);
});
