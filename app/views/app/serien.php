<?php

/**
 * Overview "Wiederkehrende Kosten" (M8-1/M8-2, issues #57/#58,
 * docs/spec/03-erfassung-und-ki.md section 9): the suggestions series
 * detection found, then the confirmed series - next expected date first,
 * with newest receipt, yearly projection, trend and the "überfällig" mark.
 *
 * Only components from /admin/designsystem; the tables scroll sideways on
 * narrow screens like every other table. Names and amounts are vault data:
 * without an unlocked vault there is nothing to show.
 *
 * @var bool $entsperrt
 * @var list<\App\Service\Invoice\SerienAnsicht> $vorschlaege
 * @var list<\App\Service\Invoice\SerienAnsicht> $bestaetigt
 * @var list<array{richtung: \App\Domain\InvoiceDirection, waehrung: string, cent: int, anzahl: int}> $summen
 */

use App\Domain\InvoiceDirection;
use App\Service\Invoice\SerienFaelligkeit;
use App\Service\Processing\Betrag;

$tabelle = static function (array $serien, string $beschriftung): void {
    ?>
    <div class="tabelle-rahmen">
        <table class="tabelle">
            <caption><?= e($beschriftung) ?></caption>
            <thead>
                <tr>
                    <th scope="col">Serie</th>
                    <th scope="col">Rhythmus</th>
                    <th scope="col">Erwartet</th>
                    <th scope="col">Letzter Beleg</th>
                    <th scope="col">Jahreshochrechnung</th>
                    <th scope="col">Trend</th>
                    <th scope="col">Nächste Fälligkeit</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($serien as $serie): ?>
                    <?php
                    $k = $serie->kennzahlen;
                    $letzter = $k?->letzter;
                    $faelligkeit = $k?->faelligkeit ?? SerienFaelligkeit::Keine;
                    ?>
                    <tr>
                        <td>
                            <a href="/app/serien/<?= e((string) $serie->record->id) ?>"><?= e($serie->titel()) ?></a>
                            <?php if ($serie->daten->label !== ''): ?>
                                <br><span class="gedaempft"><?= e($serie->lieferant) ?></span>
                            <?php endif; ?>
                            <br><span class="gedaempft"><?= e(sprintf('%d %s', $serie->anzahl, $serie->anzahl === 1 ? 'Beleg' : 'Belege')) ?></span>
                        </td>
                        <td><?= e($serie->record->period->label()) ?> · <?= e($serie->record->direction->label()) ?></td>
                        <td><?= e(Betrag::format($serie->daten->expectedGross) . ' ' . $serie->daten->currency) ?></td>
                        <td>
                            <?php if ($letzter === null): ?>
                                –
                            <?php else: ?>
                                <?= e(Betrag::format($letzter->brutto) . ' ' . $letzter->waehrung) ?>
                                <br><a href="/app/belege/pruefen/<?= e((string) $letzter->documentId) ?>">Beleg vom <?= e($letzter->datum->format('d.m.Y')) ?></a>
                            <?php endif; ?>
                        </td>
                        <td><?= $k?->jahreshochrechnung === null ? '–' : e(Betrag::format($k->jahreshochrechnung) . ' ' . $serie->daten->currency) ?></td>
                        <td>
                            <?php if ($k?->trendText() === null): ?>
                                –
                            <?php else: ?>
                                <?= e($k->trendText()) ?>
                                <?php if ($k->trendBasis !== null): ?>
                                    <br><span class="gedaempft">seit <?= e($k->trendBasis->datum->format('m/Y')) ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= e($serie->record->nextExpected?->format('d.m.Y') ?? '–') ?>
                            <?php if ($faelligkeit === SerienFaelligkeit::Ueberfaellig): ?>
                                <br><span class="marke marke-fehler">überfällig</span>
                            <?php elseif ($faelligkeit === SerienFaelligkeit::Faellig): ?>
                                <br><span class="marke marke-warnung">fällig</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
};

$ueberfaellig = array_values(array_filter(
    $bestaetigt,
    static fn($s): bool => $s->kennzahlen?->faelligkeit === SerienFaelligkeit::Ueberfaellig,
));

?>
<section>
    <h2>Wiederkehrende Kosten</h2>

    <p class="gedaempft">
        Miete, Versicherungen, Beiträge: Belege, die regelmäßig vom selben Lieferanten kommen, werden als Serie
        erkannt – so fällt auf, wenn einer fehlt. Erkannt wird nach dem Prüfen eines Belegs: mindestens drei
        geprüfte Belege mit ähnlichem Betrag in regelmäßigem Abstand (Monat, Quartal, Halbjahr, Jahr). Jede Serie
        ist zuerst nur ein Vorschlag.
    </p>

    <?php if (!$entsperrt): ?>
        <p class="hinweis hinweis-info">
            Beträge und Lieferanten sind verschlüsselt und nur mit entsperrtem Tresor lesbar.
            Melden Sie sich neu an, um Serien zu sehen und zu bestätigen.
        </p>
    <?php endif; ?>
</section>

<?php if ($entsperrt): ?>
    <section>
        <h3>Bestätigte Serien</h3>
        <?php if ($bestaetigt === []): ?>
            <div class="leer">Noch keine bestätigte Serie.</div>
        <?php else: ?>
            <?php if ($ueberfaellig !== []): ?>
                <p class="hinweis hinweis-fehler">
                    <?= e(sprintf(
                        '%d %s überfällig: Der erwartete Beleg ist auch nach der Toleranz nicht als geprüfter Beleg da. Fehlt die Rechnung, liegt sie noch im Posteingang oder ist der Vertrag beendet?',
                        count($ueberfaellig),
                        count($ueberfaellig) === 1 ? 'Serie ist' : 'Serien sind',
                    )) ?>
                </p>
            <?php endif; ?>
            <?php if ($summen !== []): ?>
                <?php foreach ($summen as $summe): ?>
                    <p>
                        <strong><?= e(sprintf('%s pro Jahr (Hochrechnung):', $summe['richtung'] === InvoiceDirection::Ausgabe ? 'Ausgaben' : 'Einnahmen')) ?></strong>
                        <?= e(Betrag::format($summe['cent']) . ' ' . $summe['waehrung']) ?>
                        <span class="gedaempft"><?= e(sprintf('aus %d %s', $summe['anzahl'], $summe['anzahl'] === 1 ? 'Serie' : 'Serien')) ?></span>
                    </p>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php $tabelle($bestaetigt, sprintf('%d %s, nächste Fälligkeit zuerst', count($bestaetigt), count($bestaetigt) === 1 ? 'Serie' : 'Serien')); ?>
            <p class="gedaempft">
                Letzter Beleg: der neueste geprüfte Beleg der Serie. Jahreshochrechnung: erwarteter Betrag × Belege pro Jahr. Trend: letzter Betrag verglichen mit dem Beleg
                etwa ein Jahr davor (sonst dem ältesten der Serie). „Überfällig“: nächste Fälligkeit plus Toleranz ist
                vorbei, ohne dass ein neuer geprüfter Beleg zur Serie kam.
            </p>
        <?php endif; ?>
    </section>

    <section>
        <h3>Vorschläge</h3>
        <?php if ($vorschlaege === []): ?>
            <div class="leer">Keine neuen Vorschläge.</div>
        <?php else: ?>
            <p class="hinweis hinweis-warnung">
                <?= e(sprintf('%d %s auf Ihre Entscheidung: öffnen, prüfen, dann bestätigen oder verwerfen.', count($vorschlaege), count($vorschlaege) === 1 ? 'Vorschlag wartet' : 'Vorschläge warten')) ?>
            </p>
            <?php $tabelle($vorschlaege, sprintf('%d %s', count($vorschlaege), count($vorschlaege) === 1 ? 'Vorschlag' : 'Vorschläge')); ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
