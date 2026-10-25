<footer class="fuss">
    <span><?= e($appName) ?></span>
    <?php /* Impressum and Datenschutz reachable from every page (issue #80/M13-3).
             On the public pages in a new tab: on /einreichen leaving the
             form would lose the pages already captured. */
    $fussZiel = ($bereich ?? null) === \App\View\Area::Oeffentlich ? ' target="_blank" rel="noopener"' : ''; ?>
    <span class="fuss-links">
        <a href="/impressum"<?= $fussZiel ?>>Impressum</a>
        <a href="/datenschutz"<?= $fussZiel ?>>Datenschutz</a>
    </span>
    <span class="version">Version <?= e($version) ?></span>
</footer>
