// The backup-codes page's "drucken" button (issue #17/M3-4) - a real click
// handler and not an inline onclick, which the CSP (script-src 'self'
// without 'unsafe-inline', CLAUDE.md section 4) would silently drop. Same
// pattern as the recovery key page (public/js/install.js).

function initSicherheitCodesDrucken() {
    const knopf = document.querySelector('#sicherheit-codes-drucken');
    if (knopf !== null) {
        knopf.addEventListener('click', () => window.print());
    }
}

if (typeof document !== 'undefined') {
    initSicherheitCodesDrucken();
}
