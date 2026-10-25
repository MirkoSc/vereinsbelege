<?php

/**
 * Income, expenses and balance per tax sphere (issue #84, E-57, docs/spec/
 * 05-auswertung-und-export.md section 1) - only reachable with
 * `sphaeren_aktiv` on. One table, every figure as text; a line without its
 * own sphere takes its category's default, the rest shows as "ohne Sphäre".
 * No tax advice: the page says so.
 *
 * @var \App\Service\Report\ZeitraumAuswahl $auswahl
 * @var int $erstesJahr
 * @var int $letztesJahr
 * @var array<string, string> $ansicht the basis as query parameter
 * @var \App\Service\Report\Grundlage $grundlage
 * @var bool $entsperrt
 * @var bool $sphaerenAktiv always true here (for the tab row)
 * @var \App\Service\Report\SphaerenErgebnis|null $ergebnis null without an unlocked vault
 */

use App\Service\Processing\Betrag;
use App\Service\Report\Grundlage;

$geld = static fn(int $cent): string => Betrag::format($cent) . ' €';
$gewaehlt = static fn(bool $ja): string => $ja ? ' selected' : '';
$aktiv = '/app/auswertung/sphaeren';
?>
<section>
    <h2>Steuerliche Sphären</h2>
    <?php require __DIR__ . '/../partials/auswertung-reiter.php'; ?>

    <p class="hinweis hinweis-info">
        Die Übersicht zeigt, wie die Belege und Buchungen den Sphären zugeordnet sind – sie ist
        keine Steuerberatung. Ob die Zuordnung steuerlich richtig ist, bitte mit der
        Steuerberatung abstimmen.
    </p>

    <?php
    $aktion = '/app/auswertung/sphaeren';
    $weitereParameter = $ansicht;
    require __DIR__ . '/../partials/zeitraum-filter.php';
    ?>
    <form method="get" action="/app/auswertung/sphaeren" class="formular nicht-drucken" aria-label="Ansicht wählen">
        <?php foreach ($auswahl->query() as $name => $wert): ?>
            <input type="hidden" name="<?= e($name) ?>" value="<?= e($wert) ?>">
        <?php endforeach; ?>
        <label for="sphaeren-grundlage">Grundlage
            <select id="sphaeren-grundlage" name="grundlage">
                <?php foreach (Grundlage::cases() as $g): ?>
                    <option value="<?= e($g->value) ?>"<?= $gewaehlt($grundlage === $g) ?>><?= e($g->label()) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <p class="knopfreihe">
            <button type="submit" class="knopf">Ansicht wechseln</button>
        </p>
    </form>
</section>

<?php if (!$entsperrt): ?>
    <p class="hinweis hinweis-info">
        Beträge sind verschlüsselt und nur mit entsperrtem Tresor lesbar. Melden Sie sich neu an, um die Auswertung zu sehen.
    </p>
<?php elseif ($ergebnis !== null && $ergebnis->sichtbar === null): ?>
    <div class="leer">Dieser Zeitraum liegt außerhalb des für Sie freigegebenen Zeitraums.</div>
<?php elseif ($ergebnis !== null && $ergebnis->sichtbar !== null): ?>
    <p class="gedaempft">
        Grundlage <?= e($grundlage->label()) ?>,
        vom <?= e($ergebnis->sichtbar->von->format('d.m.Y')) ?> bis <?= e($ergebnis->sichtbar->bis->format('d.m.Y')) ?><?= $grundlage === Grundlage::Buchungen ? ', ohne Umbuchungen zwischen eigenen Konten' : ', nur geprüfte Belege' ?>.
        Gutschriften und Erstattungen mindern ihre Seite.
    </p>
    <?php if ($ergebnis->unlesbar > 0): ?>
        <p class="hinweis hinweis-warnung"><?= e(sprintf('%d %s nicht entschlüsseln und %s nicht mitgezählt.', $ergebnis->unlesbar, $ergebnis->unlesbar === 1 ? 'Eintrag ließ sich' : 'Einträge ließen sich', $ergebnis->unlesbar === 1 ? 'ist' : 'sind')) ?></p>
    <?php endif; ?>
    <?php if ($ergebnis->fremdwaehrung > 0): ?>
        <p class="hinweis hinweis-warnung"><?= e(sprintf('%d %s in einer anderen Währung als Euro %s nicht mitgezählt.', $ergebnis->fremdwaehrung, $ergebnis->fremdwaehrung === 1 ? 'Eintrag' : 'Einträge', $ergebnis->fremdwaehrung === 1 ? 'ist' : 'sind')) ?></p>
    <?php endif; ?>

    <section aria-labelledby="sphaeren-tabelle">
        <h3 id="sphaeren-tabelle">Einnahmen und Ausgaben je Sphäre</h3>
        <div class="tabelle-rahmen">
            <table class="tabelle tabelle-karten">
                <thead>
                    <tr>
                        <th scope="col">Sphäre</th>
                        <th scope="col" class="zahl">Einnahmen</th>
                        <th scope="col" class="zahl">Ausgaben</th>
                        <th scope="col" class="zahl">Saldo</th>
                        <th scope="col" class="zahl">Posten</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ergebnis->zeilen as $zeile): ?>
                        <tr>
                            <th scope="row"><?= e($zeile->name()) ?></th>
                            <td class="zahl" data-label="Einnahmen"><?= e($geld($zeile->summen->einnahmen)) ?></td>
                            <td class="zahl" data-label="Ausgaben"><?= e($geld($zeile->summen->ausgaben)) ?></td>
                            <td class="zahl" data-label="Saldo"><?= e($geld($zeile->summen->saldo())) ?></td>
                            <td class="zahl" data-label="Posten"><?= e((string) $zeile->summen->anzahl) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th scope="row">Summe</th>
                        <td class="zahl" data-label="Einnahmen"><strong><?= e($geld($ergebnis->summe->einnahmen)) ?></strong></td>
                        <td class="zahl" data-label="Ausgaben"><strong><?= e($geld($ergebnis->summe->ausgaben)) ?></strong></td>
                        <td class="zahl" data-label="Saldo"><strong><?= e($geld($ergebnis->summe->saldo())) ?></strong></td>
                        <td class="zahl" data-label="Posten"><?= e((string) $ergebnis->summe->anzahl) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php if ($ergebnis->ausKategorie() > 0): ?>
            <p class="gedaempft"><?= e(sprintf('%d Posten ohne eigene Sphäre %s die Vorgabe ihrer Kategorie.', $ergebnis->ausKategorie(), $ergebnis->ausKategorie() === 1 ? 'übernimmt' : 'übernehmen')) ?></p>
        <?php endif; ?>
        <?php if ($ergebnis->ohneSphaere() > 0): ?>
            <p class="hinweis hinweis-warnung">Posten „ohne Sphäre“ haben weder eine eigene Sphäre noch eine Vorgabe ihrer Kategorie – Vorgaben lassen sich unter Admin → Kategorien setzen.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>
