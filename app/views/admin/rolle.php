<?php

/**
 * Create or change one role (M3-6, issue #19, docs/spec/01-sicherheit.md
 * section 4).
 *
 * Only components from /admin/designsystem, no JavaScript: one checkbox per
 * right, and for the rights that can be narrowed to cost centers a select
 * next to it. Admin is shown read-only - it always holds every right.
 *
 * @var string $csrf
 * @var \App\Domain\Role|null $rolle null for a new role
 * @var string $name
 * @var bool $extern
 * @var array<string, \App\Domain\PermissionScope> $rechte
 * @var list<\App\Domain\Permission> $alleRechte
 * @var string|null $fehler
 * @var int $anzahlKonten
 */

$neu = $rolle === null;
$fest = $rolle?->istAdmin() ?? false;
$system = $rolle?->istSystem() ?? false;
$ziel = $neu ? '/admin/rollen' : '/admin/rollen/' . $rolle->id;
$sperre = $fest ? ' disabled' : '';
?>
<section class="schmal">
    <h2><?= e($neu ? 'Neue Rolle' : 'Rolle „' . $rolle->name . '“') ?></h2>

    <p><a href="/admin/rollen">← Alle Rollen</a></p>

    <?php if ($fehler !== null): ?>
        <p class="hinweis hinweis-fehler" role="alert"><?= e($fehler) ?></p>
    <?php endif; ?>

    <?php if ($fest): ?>
        <p class="hinweis hinweis-info">
            „Admin“ hat immer alle Rechte – auch solche, die spätere Versionen
            hinzufügen. So kann sich der Verein nicht aus der eigenen
            Verwaltung aussperren.
        </p>
    <?php elseif ($system): ?>
        <p class="hinweis hinweis-info">
            Mitgelieferte Rolle: Name und Art bleiben fest, die Rechte lassen
            sich anpassen.
        </p>
    <?php endif; ?>

    <form method="post" action="<?= e($ziel) ?>" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <label for="rolle-name">Name <span class="pflicht" aria-hidden="true">*</span>
            <input type="text" id="rolle-name" name="name" value="<?= e($name) ?>"
                   maxlength="<?= e((string) \App\Service\Account\RoleService::NAME_MAX) ?>"
                   required<?= $system ? ' disabled' : '' ?>>
        </label>

        <label class="feld-ankreuz">
            <input type="checkbox" name="extern" value="1"
                   aria-describedby="rolle-extern-hilfe"
                   <?= $extern ? 'checked' : '' ?><?= $system ? ' disabled' : '' ?>> Externe Rolle
        </label>
        <p class="feld-hilfe" id="rolle-extern-hilfe">
            Für Kassenprüfer, Steuerberater und ähnliche Zugänge von außen: nur
            lesende Rechte, Ablaufdatum Pflicht (Standard 60 Tage), zwei Faktoren
            immer Pflicht. Nicht mit internen Rollen kombinierbar.
        </p>

        <fieldset>
            <legend>Rechte</legend>
            <?php foreach ($alleRechte as $recht): ?>
                <?php $scope = $rechte[$recht->value] ?? null; ?>
                <label class="feld-ankreuz">
                    <input type="checkbox" name="recht[<?= e($recht->value) ?>]" value="1"
                           <?= $scope !== null ? 'checked' : '' ?><?= $sperre ?>>
                    <?php /* One element for the text: the checkbox label is a flex
                             row, and separate pieces would each become a column. */ ?>
                    <span>
                        <?= e($recht->bezeichnung()) ?><?= $recht->istLesend() ? '' : ' <span class="gedaempft">(schreibend)</span>' ?>
                        <br><code><?= e($recht->value) ?></code>
                    </span>
                </label>
                <?php if ($recht->kostenstellenFaehig()): ?>
                    <?php $feld = 'rolle-reichweite-' . str_replace('.', '-', $recht->value); ?>
                    <label for="<?= e($feld) ?>">Reichweite „<?= e($recht->bezeichnung()) ?>“
                        <select id="<?= e($feld) ?>" name="reichweite[<?= e($recht->value) ?>]"<?= $sperre ?>>
                            <option value="alle"<?= $scope !== \App\Domain\PermissionScope::Kostenstelle ? ' selected' : '' ?>>Alle Kostenstellen</option>
                            <option value="kostenstelle"<?= $scope === \App\Domain\PermissionScope::Kostenstelle ? ' selected' : '' ?>>Nur eigene Kostenstellen</option>
                        </select>
                    </label>
                <?php endif; ?>
            <?php endforeach; ?>
        </fieldset>

        <?php if (!$fest): ?>
            <p class="knopfreihe">
                <button type="submit" class="knopf knopf-primaer">Speichern</button>
                <a class="knopf" href="/admin/rollen">Abbrechen</a>
            </p>
        <?php endif; ?>
    </form>

    <?php if (!$neu && !$system): ?>
        <h3>Rolle löschen</h3>
        <?php if ($anzahlKonten > 0): ?>
            <p class="gedaempft">
                Die Rolle ist <?= e((string) $anzahlKonten) ?> Benutzer<?= $anzahlKonten === 1 ? '' : 'n' ?>
                zugewiesen und lässt sich erst löschen, wenn niemand sie mehr hat.
            </p>
        <?php else: ?>
            <form method="post" action="/admin/rollen/<?= e((string) $rolle->id) ?>/loeschen" class="formular">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <p class="knopfreihe">
                    <button type="submit" class="knopf knopf-gefahr">Rolle löschen</button>
                </p>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>
