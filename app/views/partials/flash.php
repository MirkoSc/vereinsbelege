<?php

/**
 * The flash message of the previous request, if the controller passed one.
 *
 * aria-live is on a wrapper that is always in the DOM: an element that only
 * appears together with its text is announced unreliably, and after an htmx
 * swap not at all.
 *
 * @var \App\View\Flash|null $flash
 */
$meldung = ($flash ?? null) instanceof \App\View\Flash ? $flash : null;
?>
<div class="flash" role="<?= e($meldung?->art->ariaRolle() ?? 'status') ?>" aria-live="polite">
    <?php if ($meldung !== null): ?>
        <p class="hinweis <?= e($meldung->art->cssKlasse()) ?>"><?= e($meldung->text) ?></p>
    <?php endif; ?>
</div>
