<?php

/**
 * "Wohin fließt das Geld" (issue #73/M11-3, docs/spec/
 * 05-auswertung-und-export.md section 1, E-51): expenses or income of a
 * period by category and by cost center, bars plus table, each group a link
 * to its single lines. Every figure is text; the bar only repeats it
 * (colour/length is never the only signal). The bar is an SVG rect - a
 * length attribute, not an inline style, which the CSP would drop.
 *
 * @var \App\Service\Report\ZeitraumAuswahl $auswahl
 * @var int $erstesJahr
 * @var int $letztesJahr
 * @var array<string, string> $ansicht basis and side as query parameters
 * @var \App\Service\Report\Grundlage $grundlage
 * @var \App\Domain\InvoiceDirection $seite
 * @var array<string, string> $parameter the current filter as query parameters
 * @var bool $entsperrt
 * @var \App\Service\Report\GeldflussErgebnis|null $ergebnis null without an unlocked vault
 */

use App\Domain\InvoiceDirection;
use App\Service\Processing\Betrag;
use App\Service\Report\Dimension;
use App\Service\Report\Grundlage;

$geld = static fn(int $cent): string => Betrag::format($cent) . ' €';
$prozent = static fn(?int $promille): string => $promille === null ? '–' : intdiv($promille, 10) . ',' . ($promille % 10) . ' %';
$gewaehlt = static fn(bool $ja): string => $ja ? ' selected' : '';
$seitenwort = $seite === InvoiceDirection::Einnahme ? 'Einnahmen' : 'Ausgaben';
$aktiv = '/app/auswertung/geldfluss';
?>
<section>
    <h2>Wohin fließt das Geld</h2>
    <?php require __DIR__ . '/../partials/auswertung-reiter.php'; ?>

    <?php
    // The shared period filter (issue #74) keeps basis and side; the form
    // below keeps the period - either change leaves the other as it is.
    $aktion = '/app/auswertung/geldfluss';
    $weitereParameter = $ansicht;
    require __DIR__ . '/../partials/zeitraum-filter.php';
    ?>
    <form method="get" action="/app/auswertung/geldfluss" class="formular nicht-drucken" aria-label="Ansicht wählen">
        <?php foreach ($auswahl->query() as $name => $wert): ?>
            <input type="hidden" name="<?= e($name) ?>" value="<?= e($wert) ?>">
        <?php endforeach; ?>
        <label for="geldfluss-grundlage">Grundlage
            <select id="geldfluss-grundlage" name="grundlage">
                <?php foreach (Grundlage::cases() as $g): ?>
                    <option value="<?= e($g->value) ?>"<?= $gewaehlt($grundlage === $g) ?>><?= e($g->label()) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label for="geldfluss-seite">Zeigen
            <select id="geldfluss-seite" name="seite">
                <option value="ausgaben"<?= $gewaehlt($seite === InvoiceDirection::Ausgabe) ?>>Ausgaben</option>
                <option value="einnahmen"<?= $gewaehlt($seite === InvoiceDirection::Einnahme) ?>>Einnahmen</option>
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
        <?= e($seitenwort) ?>, Grundlage <?= e($grundlage->label()) ?>,
        vom <?= e($ergebnis->sichtbar->von->format('d.m.Y')) ?> bis <?= e($ergebnis->sichtbar->bis->format('d.m.Y')) ?><?= $grundlage === Grundlage::Buchungen ? ', ohne Umbuchungen zwischen eigenen Konten' : ', nur geprüfte Belege' ?>.
        Gutschriften und Erstattungen mindern ihre Gruppe.
    </p>
    <?php if ($ergebnis->unlesbar > 0): ?>
        <p class="hinweis hinweis-warnung"><?= e(sprintf('%d %s nicht entschlüsseln und %s nicht mitgezählt.', $ergebnis->unlesbar, $ergebnis->unlesbar === 1 ? 'Eintrag ließ sich' : 'Einträge ließen sich', $ergebnis->unlesbar === 1 ? 'ist' : 'sind')) ?></p>
    <?php endif; ?>
    <?php if ($ergebnis->fremdwaehrung > 0): ?>
        <p class="hinweis hinweis-warnung"><?= e(sprintf('%d %s in einer anderen Währung als Euro %s nicht mitgezählt.', $ergebnis->fremdwaehrung, $ergebnis->fremdwaehrung === 1 ? 'Eintrag' : 'Einträge', $ergebnis->fremdwaehrung === 1 ? 'ist' : 'sind')) ?></p>
    <?php endif; ?>

    <?php foreach ($ergebnis->tabellen as $tabelle): ?>
        <?php
        $nach = $tabelle->dimension === Dimension::Kostenstelle ? 'Kostenstelle' : 'Kategorie';
        $ueberschriftId = 'geldfluss-' . $tabelle->dimension->value;
        ?>
        <section aria-labelledby="<?= e($ueberschriftId) ?>">
            <h3 id="<?= e($ueberschriftId) ?>"><?= e($seitenwort . ' nach ' . $nach) ?></h3>
            <?php if ($tabelle->zeilen === []): ?>
                <div class="leer">Keine <?= e($seitenwort) ?> in diesem Zeitraum.</div>
            <?php else: ?>
                <div class="tabelle-rahmen">
                    <table class="tabelle tabelle-karten geldfluss">
                        <thead>
                            <tr>
                                <th scope="col"><?= e($nach) ?></th>
                                <th scope="col" class="geldfluss-balken-spalte"><span class="visuell-versteckt">Anteil als Balken</span></th>
                                <th scope="col" class="zahl">Betrag</th>
                                <th scope="col" class="zahl">Anteil</th>
                                <th scope="col" class="zahl">Posten</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tabelle->zeilen as $zeile): ?>
                                <?php $link = '/app/auswertung/geldfluss/posten?' . http_build_query([...$parameter, 'nach' => $tabelle->dimension->value, 'schluessel' => $zeile->schluesselParameter()]); ?>
                                <tr>
                                    <th scope="row"><a href="<?= e($link) ?>"><?= e($zeile->name) ?></a></th>
                                    <td class="geldfluss-balken-spalte">
                                        <svg class="balken" viewBox="0 0 1000 10" preserveAspectRatio="none" aria-hidden="true" focusable="false"><rect class="balken-wert" width="<?= e((string) $zeile->balkenPromille) ?>" height="10"/></svg>
                                    </td>
                                    <td class="zahl" data-label="Betrag"><?= e($geld($zeile->betrag)) ?></td>
                                    <td class="zahl" data-label="Anteil"><?= e($prozent($zeile->anteilPromille)) ?></td>
                                    <td class="zahl" data-label="Posten"><?= e((string) $zeile->anzahl) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th scope="row" colspan="2">Summe</th>
                                <td class="zahl" data-label="Betrag"><strong><?= e($geld($tabelle->summe)) ?></strong></td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
