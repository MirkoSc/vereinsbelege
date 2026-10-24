<?php

/**
 * Tab row of the work lists /app/offen/... (M10-5, issue #69): only the
 * lists the account may open; the current one marked.
 *
 * @var list<array{href: string, label: string, aktiv: bool}> $reiter
 * @var bool $entsperrt
 */
?>
<nav aria-label="Offene Punkte">
    <p class="knopfreihe">
        <?php foreach ($reiter as $r): ?>
            <a class="knopf <?= $r['aktiv'] ? 'knopf-primaer' : 'knopf-still' ?>" href="<?= e($r['href']) ?>"<?= $r['aktiv'] ? ' aria-current="page"' : '' ?>><?= e($r['label']) ?></a>
        <?php endforeach; ?>
    </p>
</nav>
<?php if (!$entsperrt): ?>
    <p class="hinweis hinweis-info">
        Beträge, Lieferanten und Buchungen sind verschlüsselt und nur mit entsperrtem Tresor lesbar.
        Melden Sie sich neu an, um diese Liste zu sehen.
    </p>
<?php endif; ?>
