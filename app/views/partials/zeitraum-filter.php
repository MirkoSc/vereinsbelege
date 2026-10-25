<?php

/**
 * The period filter of a report page (docs/spec/05-auswertung-und-export.md
 * section 1: Kalenderjahr/Quartal/Monat/frei; App\Service\Report\
 * ZeitraumAuswahl reads it back). A plain GET form: works without
 * JavaScript - the chosen "Art" decides which fields count, the others are
 * ignored. Not printed.
 *
 * @var \App\Service\Report\ZeitraumAuswahl $auswahl
 * @var string $aktion the page's path
 * @var int $erstesJahr
 * @var int $letztesJahr
 * @var array<string, string>|null $weitereParameter other filters of the
 *        page (structure only), kept as hidden fields when the period changes
 */

use App\Service\Report\ZeitraumAuswahl;

$gewaehlt = static fn(bool $ja): string => $ja ? ' selected' : '';
$weitereParameter ??= [];
$z = $auswahl->zeitraum;
$jahr = (int) $z->von->format('Y');
$quartal = intdiv((int) $z->von->format('n') + 2, 3);
$monat = (int) $z->von->format('n');
$monate = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
?>
<form method="get" action="<?= e($aktion) ?>" class="formular nicht-drucken" aria-label="Zeitraum wählen">
    <?php foreach ($weitereParameter as $name => $wert): ?>
        <input type="hidden" name="<?= e($name) ?>" value="<?= e($wert) ?>">
    <?php endforeach; ?>
    <label for="zeitraum-art" class="feld-kurz">Zeitraum
        <select id="zeitraum-art" name="art">
            <?php foreach (ZeitraumAuswahl::ARTEN as $wert => $label): ?>
                <option value="<?= e($wert) ?>"<?= $gewaehlt($auswahl->art === $wert) ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label for="zeitraum-jahr" class="feld-kurz">Jahr
        <input type="number" id="zeitraum-jahr" name="jahr" min="<?= e((string) $erstesJahr) ?>" max="<?= e((string) $letztesJahr) ?>" value="<?= e((string) $jahr) ?>">
    </label>
    <label for="zeitraum-quartal" class="feld-kurz">Quartal
        <select id="zeitraum-quartal" name="quartal">
            <?php for ($q = 1; $q <= 4; ++$q): ?>
                <option value="<?= e((string) $q) ?>"<?= $gewaehlt($quartal === $q) ?>><?= e($q . '. Quartal') ?></option>
            <?php endfor; ?>
        </select>
    </label>
    <label for="zeitraum-monat" class="feld-kurz">Monat
        <select id="zeitraum-monat" name="monat">
            <?php foreach ($monate as $i => $name): ?>
                <option value="<?= e((string) ($i + 1)) ?>"<?= $gewaehlt($monat === $i + 1) ?>><?= e($name) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label for="zeitraum-von" class="feld-kurz">Von
        <input type="date" id="zeitraum-von" name="von" value="<?= e($z->von->format('Y-m-d')) ?>">
    </label>
    <label for="zeitraum-bis" class="feld-kurz">Bis
        <input type="date" id="zeitraum-bis" name="bis" value="<?= e($z->bis->format('Y-m-d')) ?>">
    </label>
    <p class="feld-hilfe">Je nach „Zeitraum“ zählen Jahr, Quartal, Monat oder Von/Bis. Geschäftsjahr = Kalenderjahr.</p>
    <p class="knopfreihe">
        <button type="submit" class="knopf">Anzeigen</button>
    </p>
</form>
