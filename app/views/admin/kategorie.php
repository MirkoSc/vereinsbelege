<?php

/**
 * Create or change one category (M6-1, issue #35, docs/spec/
 * 02-datenmodell.md "Kategorien").
 *
 * Only components from /admin/designsystem, no JavaScript. The colour is a
 * select over the fixed palette (App\Domain\CategoryColor) - the CSP allows
 * no inline styles, so a free colour picker could not be shown anywhere.
 *
 * @var string $csrf
 * @var \App\Domain\Category|null $kategorie null for a new category
 * @var array{name: string, richtung: string, farbe: string, ki_hinweis: string, active: bool} $felder
 * @var string|null $fehler
 * @var int $verwendungen
 */

use App\Domain\CategoryColor;
use App\Domain\CategoryDirection;
use App\Service\MasterData\CategoryService;

$neu = $kategorie === null;
$ziel = $neu ? '/admin/kategorien' : '/admin/kategorien/' . $kategorie->id;
?>
<section class="schmal">
    <h2><?= e($neu ? 'Neue Kategorie' : 'Kategorie „' . $kategorie->name . '“') ?></h2>

    <p><a href="/admin/kategorien">← Alle Kategorien</a></p>

    <?php if ($fehler !== null): ?>
        <p class="hinweis hinweis-fehler" role="alert"><?= e($fehler) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= e($ziel) ?>" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <label for="kategorie-name">Name <span class="pflicht" aria-hidden="true">*</span>
            <input type="text" id="kategorie-name" name="name" value="<?= e($felder['name']) ?>"
                   maxlength="<?= e((string) CategoryService::NAME_MAX) ?>" required>
        </label>

        <label for="kategorie-richtung">Richtung <span class="pflicht" aria-hidden="true">*</span>
            <select id="kategorie-richtung" name="richtung" required>
                <?php foreach (CategoryDirection::cases() as $richtung): ?>
                    <option value="<?= e($richtung->value) ?>"<?= $felder['richtung'] === $richtung->value ? ' selected' : '' ?>><?= e($richtung->label()) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label for="kategorie-farbe">Farbe
            <select id="kategorie-farbe" name="farbe">
                <option value=""<?= $felder['farbe'] === '' ? ' selected' : '' ?>>Keine</option>
                <?php foreach (CategoryColor::cases() as $farbe): ?>
                    <option value="<?= e($farbe->value) ?>"<?= $felder['farbe'] === $farbe->value ? ' selected' : '' ?>><?= e($farbe->label()) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label for="kategorie-ki-hinweis">KI-Hinweis
            <textarea id="kategorie-ki-hinweis" name="ki_hinweis" rows="3"
                      maxlength="<?= e((string) CategoryService::AI_HINT_MAX) ?>"
                      aria-describedby="kategorie-ki-hinweis-hilfe"><?= e($felder['ki_hinweis']) ?></textarea>
        </label>
        <p class="feld-hilfe" id="kategorie-ki-hinweis-hilfe">
            Typische Beispiele für diese Kategorie, z. B. „Rasendünger, Mäharbeiten, Sand,
            Linierfarbe“. Die KI nutzt sie, um Belege zuzuordnen.
        </p>

        <label class="feld-ankreuz">
            <input type="checkbox" name="active" value="1" aria-describedby="kategorie-aktiv-hilfe" <?= $felder['active'] ? 'checked' : '' ?>>
            Aktiv
        </label>
        <p class="feld-hilfe" id="kategorie-aktiv-hilfe">
            Nur aktive Kategorien werden neuen Belegen und Buchungen angeboten. Bestehende
            Zuordnungen bleiben beim Deaktivieren unverändert.
        </p>

        <p class="knopfreihe">
            <button type="submit" class="knopf knopf-primaer">Speichern</button>
            <a class="knopf" href="/admin/kategorien">Abbrechen</a>
        </p>
    </form>

    <?php if (!$neu): ?>
        <h3>Kategorie löschen</h3>
        <?php if ($verwendungen > 0): ?>
            <p class="gedaempft">
                Die Kategorie wird verwendet und lässt sich deshalb nicht löschen. Alternativ oben
                deaktivieren.
            </p>
        <?php else: ?>
            <form method="post" action="/admin/kategorien/<?= e((string) $kategorie->id) ?>/loeschen" class="formular">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <p class="knopfreihe">
                    <button type="submit" class="knopf knopf-gefahr">Kategorie löschen</button>
                </p>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>
