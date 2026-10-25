<?php

/**
 * Tab row of the report views under /app/auswertung (issue #73/M11-3) -
 * every view needs `report.view` like its route, so the row needs no
 * rights check. A new view adds one line.
 *
 * @var string $aktiv the path of the current view
 */

$auswertungen = [
    '/app/auswertung/geldfluss' => 'Wohin fließt das Geld',
    '/app/auswertung/kassenpruefung' => 'Kassenprüfung',
];
?>
<nav aria-label="Auswertungen">
    <p class="knopfreihe">
        <?php foreach ($auswertungen as $pfad => $label): ?>
            <a class="knopf <?= $pfad === $aktiv ? 'knopf-primaer' : 'knopf-still' ?>" href="<?= e($pfad) ?>"<?= $pfad === $aktiv ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
    </p>
</nav>
