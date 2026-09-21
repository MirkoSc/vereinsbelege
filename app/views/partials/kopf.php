<?php

/**
 * Header with the area navigation. Entries a later milestone brings render
 * as inactive text (App\View\NavItem), so nobody lands on a 404.
 *
 * @var \App\View\Area $bereich
 * @var string $pfad
 */
$eintraege = $bereich->navigation();
?>
<header class="kopf">
    <div class="kopf-zeile">
        <a class="brand" href="<?= e($bereich->startseite()) ?>">
            <?= e($appName) ?><?php if ($bereich === \App\View\Area::Admin): ?>
                <span class="brand-bereich"><?= e($bereich->bezeichnung()) ?></span>
            <?php endif; ?>
        </a>
    </div>

    <?php if ($eintraege !== []): ?>
        <nav class="navi" aria-label="Hauptnavigation">
            <ul>
                <?php foreach ($eintraege as $eintrag): ?>
                    <li>
                        <?php if ($eintrag->verfuegbar()): ?>
                            <a href="<?= e($eintrag->href) ?>"<?= $eintrag->istAktiv($pfad) ? ' aria-current="page"' : '' ?>><?= e($eintrag->label) ?></a>
                        <?php else: ?>
                            <span class="navi-spaeter" aria-disabled="true" title="Kommt mit Meilenstein <?= e($eintrag->meilenstein) ?>"><?= e($eintrag->label) ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
    <?php endif; ?>
</header>

<?php if ($bereich === \App\View\Area::Admin): ?>
    <?php /* Part of the admin chrome, not of a single page: every admin
             page is open to anyone who can reach the site until M3-3
             (App\Admin\UpdateController says the same at length). */ ?>
    <div class="inhalt">
        <p class="hinweis hinweis-warnung">
            <strong>Dieser Bereich ist noch nicht geschützt.</strong> Anmeldung und Rechte
            kommen mit Meilenstein M3. Bis dahin gehört eine öffentlich erreichbare
            Installation zusätzlich hinter einen Passwortschutz des Hosters.
        </p>
    </div>
<?php endif; ?>
