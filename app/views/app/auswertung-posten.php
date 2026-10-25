<?php

/**
 * Drilldown of "Wohin fließt das Geld" (issue #73/M11-3): the single lines
 * behind one category or cost center - receipts, and on the booking basis
 * the parts of bookings. A receipt links to the normal receipt page (review
 * page or inbox entry by right), a booking to the booking page with
 * `bank.view`; those pages check right and scope again. Links carry ids
 * only.
 *
 * @var \App\Service\Report\Zeitraumwahl $wahl
 * @var \App\Service\Report\Grundlage $grundlage
 * @var \App\Domain\InvoiceDirection $seite
 * @var array<string, string> $parameter the current filter as query parameters
 * @var bool $entsperrt
 * @var \App\Service\Report\Dimension $dimension
 * @var \App\Service\Report\GeldflussDrilldown|null $drilldown null without an unlocked vault
 * @var string|null $belegPfad
 * @var string|null $buchungPfad
 */

use App\Domain\InvoiceDirection;
use App\Service\Processing\Betrag;
use App\Service\Report\Dimension;

$geld = static fn(int $cent): string => Betrag::format($cent) . ' €';
$seitenwort = $seite === InvoiceDirection::Einnahme ? 'Einnahmen' : 'Ausgaben';
$nach = $dimension === Dimension::Kostenstelle ? 'Kostenstelle' : 'Kategorie';
$zurueck = '/app/auswertung/geldfluss?' . http_build_query($parameter);
?>
<section>
    <p><a href="<?= e($zurueck) ?>">‹ Zurück zur Übersicht</a></p>
    <h2><?= e($drilldown?->titel ?? 'Einzelposten') ?></h2>
    <p class="gedaempft">
        <?= e($seitenwort) ?> · <?= e($nach) ?> · <?= e($wahl->bezeichnung()) ?> · <?= e($grundlage->label()) ?>
    </p>
</section>

<?php if (!$entsperrt || $drilldown === null): ?>
    <p class="hinweis hinweis-info">
        Beträge und Belege sind verschlüsselt und nur mit entsperrtem Tresor lesbar. Melden Sie sich neu an, um die Posten zu sehen.
    </p>
<?php elseif ($drilldown->sichtbar === null): ?>
    <div class="leer">Dieser Zeitraum liegt außerhalb des für Sie freigegebenen Zeitraums.</div>
<?php else: ?>
    <?php if ($drilldown->unlesbar > 0): ?>
        <p class="hinweis hinweis-warnung"><?= e(sprintf('%d %s nicht entschlüsseln und %s nicht mitgezählt.', $drilldown->unlesbar, $drilldown->unlesbar === 1 ? 'Eintrag ließ sich' : 'Einträge ließen sich', $drilldown->unlesbar === 1 ? 'ist' : 'sind')) ?></p>
    <?php endif; ?>
    <?php if ($drilldown->fremdwaehrung > 0): ?>
        <p class="hinweis hinweis-warnung"><?= e(sprintf('%d %s in einer anderen Währung als Euro %s nicht mitgezählt.', $drilldown->fremdwaehrung, $drilldown->fremdwaehrung === 1 ? 'Eintrag' : 'Einträge', $drilldown->fremdwaehrung === 1 ? 'ist' : 'sind')) ?></p>
    <?php endif; ?>
    <?php if ($drilldown->posten === []): ?>
        <div class="leer">Keine Posten in diesem Zeitraum.</div>
    <?php else: ?>
        <div class="tabelle-rahmen">
            <table class="tabelle tabelle-karten geldfluss">
                <caption><?= e(sprintf('%d Posten vom %s bis %s', count($drilldown->posten), $drilldown->sichtbar->von->format('d.m.Y'), $drilldown->sichtbar->bis->format('d.m.Y'))) ?></caption>
                <thead>
                    <tr>
                        <th scope="col">Datum</th>
                        <th scope="col">Beleg / Buchung</th>
                        <th scope="col"><?= e($dimension === Dimension::Kostenstelle ? 'Kategorie' : 'Kostenstelle') ?></th>
                        <th scope="col" class="zahl">Betrag</th>
                        <th scope="col">Öffnen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($drilldown->posten as $p): ?>
                        <tr>
                            <th scope="row"><?= e($p->datum->format('d.m.Y')) ?></th>
                            <td data-label="Beleg / Buchung">
                                <?= e($p->bezeichnung) ?>
                                <?php if ($p->detail !== ''): ?>
                                    <br><span class="klein gedaempft"><?= e($p->detail) ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-label="<?= e($dimension === Dimension::Kostenstelle ? 'Kategorie' : 'Kostenstelle') ?>"><?= e($dimension === Dimension::Kostenstelle ? $p->kategorie : $p->kostenstelle) ?></td>
                            <td class="zahl" data-label="Betrag"><?= e($geld($p->betrag)) ?></td>
                            <?php
                            $links = [];
                            if ($p->dokumentId !== null && $belegPfad !== null) {
                                $links[] = '<a href="' . e($belegPfad . $p->dokumentId) . '">Beleg</a>';
                            }
                            if ($p->buchungId !== null && $buchungPfad !== null) {
                                $links[] = '<a href="' . e($buchungPfad . $p->buchungId) . '">Buchung</a>';
                            }
                            ?>
                            <td data-label="Öffnen"><?= implode(' ', $links) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th scope="row" colspan="3">Summe</th>
                        <td class="zahl" data-label="Betrag"><strong><?= e($geld($drilldown->summe)) ?></strong></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
<?php endif; ?>
