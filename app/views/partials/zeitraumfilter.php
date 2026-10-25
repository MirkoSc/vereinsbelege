<?php

/**
 * The period fields of a report filter form (issue #73/M11-3, 05 section 1:
 * calendar year, quarter, month, free). Placed inside the page's own GET
 * form; works without JavaScript - the server takes only the fields the
 * chosen kind needs (App\Service\Report\Zeitraumwahl).
 *
 * @var \App\Service\Report\Zeitraumwahl $wahl the current choice
 * @var list<int> $jahre the years offered, newest first
 * @var string $idPrefix unique prefix for the field ids
 */

use App\Service\Report\Zeitraumart;
use App\Service\Report\Zeitraumwahl;

$gewaehlt = static fn(bool $ja): string => $ja ? ' selected' : '';
$zeitraum = $wahl->zeitraum();
?>
<label for="<?= e($idPrefix) ?>-zeitraum">Zeitraum
    <select id="<?= e($idPrefix) ?>-zeitraum" name="zeitraum">
        <?php foreach (Zeitraumart::cases() as $art): ?>
            <option value="<?= e($art->value) ?>"<?= $gewaehlt($wahl->art === $art) ?>><?= e($art->label()) ?></option>
        <?php endforeach; ?>
    </select>
</label>

<label for="<?= e($idPrefix) ?>-jahr" class="feld-kurz">Jahr
    <select id="<?= e($idPrefix) ?>-jahr" name="jahr">
        <?php foreach ($jahre as $jahr): ?>
            <option value="<?= e((string) $jahr) ?>"<?= $gewaehlt($wahl->jahr === $jahr) ?>><?= e((string) $jahr) ?></option>
        <?php endforeach; ?>
    </select>
</label>

<label for="<?= e($idPrefix) ?>-quartal" class="feld-kurz">Quartal
    <select id="<?= e($idPrefix) ?>-quartal" name="quartal">
        <?php for ($q = 1; $q <= 4; ++$q): ?>
            <option value="<?= e((string) $q) ?>"<?= $gewaehlt($wahl->quartal === $q) ?>><?= e($q . '. Quartal') ?></option>
        <?php endfor; ?>
    </select>
</label>

<label for="<?= e($idPrefix) ?>-monat" class="feld-kurz">Monat
    <select id="<?= e($idPrefix) ?>-monat" name="monat">
        <?php foreach (Zeitraumwahl::MONATSNAMEN as $nummer => $name): ?>
            <option value="<?= e((string) $nummer) ?>"<?= $gewaehlt($wahl->monat === $nummer) ?>><?= e($name) ?></option>
        <?php endforeach; ?>
    </select>
</label>

<label for="<?= e($idPrefix) ?>-von" class="feld-kurz">Von
    <input type="date" id="<?= e($idPrefix) ?>-von" name="von" value="<?= e($zeitraum->von->format('Y-m-d')) ?>">
</label>

<label for="<?= e($idPrefix) ?>-bis" class="feld-kurz">Bis
    <input type="date" id="<?= e($idPrefix) ?>-bis" name="bis" value="<?= e($zeitraum->bis->format('Y-m-d')) ?>">
</label>
<p class="feld-hilfe">Jahr gilt für Kalenderjahr, Quartal und Monat; „Von“ und „Bis“ nur für einen freien Zeitraum (höchstens <?= e((string) Zeitraumwahl::MAX_JAHRE_FREI) ?> Jahre).</p>
<?php if ($wahl->korrigiert): ?>
    <p class="hinweis hinweis-warnung">Der gewählte Zeitraum war ungültig; gezeigt wird das Jahr <?= e((string) $wahl->jahr) ?>.</p>
<?php endif; ?>
