// The "copy" button on /admin/systemcheck (M3-10, issue #107): puts the JSON
// result on the clipboard so a finding fits into a GitHub issue.
//
// A real click handler in its own file and not an inline onclick: the CSP
// (script-src 'self' without 'unsafe-inline', CLAUDE.md section 4) would
// silently drop the latter. The button is hidden in the markup and shown
// only here, so without JavaScript the page offers the selectable textarea
// and nothing that does not work.

/**
 * Copies `text`: the Clipboard API where the page may use it, otherwise the
 * old way through the selected textarea. Resolves to whether it worked.
 *
 * @param {string} text
 * @param {{clipboard?: {writeText: (t: string) => Promise<void>}}} [navigatorLike]
 * @param {() => boolean} [auswaehlenUndKopieren] the fallback
 * @returns {Promise<boolean>}
 */
async function kopiere(text, navigatorLike, auswaehlenUndKopieren) {
    if (navigatorLike && navigatorLike.clipboard && typeof navigatorLike.clipboard.writeText === 'function') {
        try {
            await navigatorLike.clipboard.writeText(text);

            return true;
        } catch (e) {
            // Denied (permission, insecure context): fall through to the fallback.
        }
    }

    return typeof auswaehlenUndKopieren === 'function' ? auswaehlenUndKopieren() === true : false;
}

function kopiertText(erfolg) {
    return erfolg ? 'Kopiert.' : 'Kopieren nicht möglich – bitte den Text markieren und von Hand kopieren.';
}

function initSystemcheck() {
    const feld = document.querySelector('#systemcheck-json');
    const knopf = document.querySelector('#systemcheck-kopieren');
    const meldung = document.querySelector('#systemcheck-kopiert');
    if (feld === null || knopf === null) {
        return;
    }

    knopf.hidden = false;
    knopf.addEventListener('click', async () => {
        const erfolg = await kopiere(feld.value, navigator, () => {
            feld.focus();
            feld.select();

            return document.execCommand('copy');
        });
        if (meldung !== null) {
            meldung.textContent = kopiertText(erfolg);
        }
    });
}

if (typeof document !== 'undefined') {
    initSystemcheck();
}

// Node (tests/js) loads the same file for the pure helpers above; browsers
// ignore this block because `module` does not exist there.
if (typeof module === 'object' && module.exports) {
    module.exports = { kopiere, kopiertText };
}
