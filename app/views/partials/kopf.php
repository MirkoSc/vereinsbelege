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
 * @var \App\Domain\Berechtigungen|null $berechtigungen rights of the logged-in
 *      account - the navigation lists only what it may open (M3-6)
 */
$eintraege = $bereich->navigation($berechtigungen ?? null);
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
