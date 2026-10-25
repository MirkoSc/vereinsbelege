<?php

/**
 * Impressum or Datenschutzerklärung (issue #80/M13-3): the text the club
 * maintains under /admin/rechtstexte. html() escapes every character of
 * the stored text and writes only its own few tags
 * (App\View\EinfachesMarkdown) - the one place this view prints unescaped.
 *
 * @var string $title
 * @var \App\Service\Rechtstexte\RechtstextInhalt $inhalt
 */
?>
<section class="schmal rechtstext">
    <h2><?= e($title) ?></h2>

    <?php if ($inhalt->unfertig()): ?>
        <p class="hinweis hinweis-warnung">
            Diese Seite zeigt noch eine Vorlage, die der Verein nicht vollständig ausgefüllt hat.
        </p>
    <?php endif; ?>

    <?= $inhalt->html() ?>
</section>
