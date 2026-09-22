<?php

/**
 * Header with the area navigation. Entries a later milestone brings render
 * as inactive text (App\View\NavItem), so nobody lands on a 404.
 *
 * @var \App\View\Area $bereich
 * @var string $pfad
 * @var string|null $angemeldet display name of the logged-in user, set by
 *      App\Http\LoginGuard through App\View\View - null on every public page
 * @var string|null $csrfToken
 */
$eintraege = $bereich->navigation();
// The logout is a POST with a CSRF token, not a link: a GET that ends a
// session can be triggered by any image tag on any page.
$zeigeAbmelden = ($angemeldet ?? null) !== null && ($csrfToken ?? null) !== null;
?>
<header class="kopf">
    <div class="kopf-zeile">
        <a class="brand" href="<?= e($bereich->startseite()) ?>">
            <?= e($appName) ?><?php if ($bereich === \App\View\Area::Admin): ?>
                <span class="brand-bereich"><?= e($bereich->bezeichnung()) ?></span>
            <?php endif; ?>
        </a>

        <?php if ($zeigeAbmelden): ?>
            <div class="kopf-konto">
                <span class="kopf-benutzer"><?= e((string) $angemeldet) ?></span>
                <form method="post" action="/abmelden">
                    <input type="hidden" name="_csrf" value="<?= e((string) $csrfToken) ?>">
                    <button type="submit" class="knopf knopf-still">Abmelden</button>
                </form>
            </div>
        <?php endif; ?>
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
    <?php /* Part of the admin chrome, not of a single page: since M3-3 every
             admin route needs a login (app/src/routes.php), but WHICH
             logged-in user may do what is still open - roles and the
             Permission enum are M3-6. Until then anybody with an account is
             an administrator here, and the chrome says so. */ ?>
    <div class="inhalt">
        <p class="hinweis hinweis-warnung">
            <strong>Rollen und Rechte fehlen noch.</strong> Diese Seiten verlangen seit
            Meilenstein M3-3 eine Anmeldung, unterscheiden aber noch nicht zwischen
            Rollen – jeder angemeldete Zugang darf hier alles. Die Rechteprüfung kommt
            mit M3-6.
        </p>
    </div>
<?php endif; ?>
