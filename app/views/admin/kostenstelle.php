<?php

/**
 * Create or change one cost center (M4-1, issue #23, docs/spec/
 * 02-datenmodell.md "Fachdaten").
 *
 * Only components from /admin/designsystem, no JavaScript.
 *
 * @var string $csrf
 * @var \App\Domain\CostCenter|null $kostenstelle null for a new cost center
 * @var string $name
 * @var bool $active
 * @var string|null $fehler
 * @var int $anzahlKonten
 */

$neu = $kostenstelle === null;
$ziel = $neu ? '/admin/kostenstellen' : '/admin/kostenstellen/' . $kostenstelle->id;
?>
<section class="schmal">
    <h2><?= e($neu ? 'Neue Kostenstelle' : 'Kostenstelle „' . $kostenstelle->name . '“') ?></h2>

    <p><a href="/admin/kostenstellen">← Alle Kostenstellen</a></p>

    <?php if ($fehler !== null): ?>
        <p class="hinweis hinweis-fehler" role="alert"><?= e($fehler) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= e($ziel) ?>" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <label for="kostenstelle-name">Name <span class="pflicht" aria-hidden="true">*</span>
            <input type="text" id="kostenstelle-name" name="name" value="<?= e($name) ?>"
                   maxlength="<?= e((string) \App\Service\MasterData\CostCenterService::NAME_MAX) ?>" required>
        </label>

        <label class="feld-ankreuz">
            <input type="checkbox" name="active" value="1" aria-describedby="kostenstelle-aktiv-hilfe" <?= $active ? 'checked' : '' ?>>
            Aktiv
        </label>
        <p class="feld-hilfe" id="kostenstelle-aktiv-hilfe">
            Nur aktive Kostenstellen lassen sich neu zuweisen. Bestehende Zuweisungen und
            Belege bleiben beim Deaktivieren unverändert.
        </p>

        <p class="knopfreihe">
            <button type="submit" class="knopf knopf-primaer">Speichern</button>
            <a class="knopf" href="/admin/kostenstellen">Abbrechen</a>
        </p>
    </form>

    <?php if (!$neu): ?>
        <h3>Kostenstelle löschen</h3>
        <?php if ($anzahlKonten > 0): ?>
            <p class="gedaempft">
                Die Kostenstelle ist <?= e((string) $anzahlKonten) ?> Benutzer<?= $anzahlKonten === 1 ? '' : 'n' ?>
                zugewiesen und lässt sich erst löschen, wenn niemand sie mehr hat. Alternativ oben deaktivieren.
            </p>
        <?php else: ?>
            <form method="post" action="/admin/kostenstellen/<?= e((string) $kostenstelle->id) ?>/loeschen" class="formular">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <p class="knopfreihe">
                    <button type="submit" class="knopf knopf-gefahr">Kostenstelle löschen</button>
                </p>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>
