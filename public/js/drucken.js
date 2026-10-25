// "Drucken" buttons of printable pages (e.g. the Kassenprüfung, issue #74):
// a real click handler and not an inline onclick, which the CSP
// (script-src 'self' without 'unsafe-inline', CLAUDE.md section 4) would
// silently drop. Every element with `data-drucken` prints the page.

function initDrucken(wurzel, drucken) {
    const knoepfe = wurzel.querySelectorAll('[data-drucken]');
    knoepfe.forEach((knopf) => {
        knopf.hidden = false;
        knopf.addEventListener('click', () => drucken());
    });

    return knoepfe.length;
}

if (typeof document !== 'undefined') {
    initDrucken(document, () => window.print());
}

if (typeof module !== 'undefined') {
    module.exports = { initDrucken };
}
