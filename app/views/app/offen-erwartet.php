<?php

/**
 * Expected receipts of recurring series (M10-5, issue #69, docs/spec/
 * 04-bank-und-abgleich.md section 5 "Übersichten"): the confirmed series
 * whose next receipt is due or overdue (E-33) - from
 * App\Service\Invoice\Serien, the same figures as /app/serien.
 *
 * @var bool $entsperrt
 * @var list<\App\Service\Invoice\SerienAnsicht> $serien
 */

use App\Service\Invoice\SerienFaelligkeit;
use App\Service\Processing\Betrag;

?>
<section>
    <h2>Erwartete Belege</h2>
    <?php require __DIR__ . '/../partials/offen-reiter.php'; ?>
    <p class="gedaempft">
        Bestätigte <a href="/app/serien">wiederkehrende Rechnungen</a>, deren nächster Beleg fällig ist, aber noch nicht als
        geprüfter Beleg vorliegt. Überfällige zuerst.
    </p>
</section>

<?php if ($entsperrt): ?>
    <section>
        <?php if ($serien === []): ?>
            <div class="leer">Kein erwarteter Beleg fehlt.</div>
        <?php else: ?>
            <div class="tabelle-rahmen">
                <table class="tabelle">
                    <caption><?= e(sprintf('%d %s', count($serien), count($serien) === 1 ? 'Serie' : 'Serien')) ?></caption>
                    <thead>
                        <tr>
                            <th scope="col">Erwartet am</th>
                            <th scope="col">Serie</th>
                            <th scope="col" class="zahl">Erwarteter Betrag</th>
                            <th scope="col">Letzter Beleg</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($serien as $serie): ?>
                            <?php $letzter = $serie->kennzahlen?->letzter; ?>
                            <tr>
                                <td>
                                    <?= e($serie->record->nextExpected?->format('d.m.Y') ?? '–') ?>
                                    <?php if ($serie->kennzahlen?->faelligkeit === SerienFaelligkeit::Ueberfaellig): ?>
                                        <br><span class="marke marke-fehler">überfällig</span>
                                    <?php else: ?>
                                        <br><span class="marke marke-warnung">fällig</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="/app/serien/<?= e((string) $serie->record->id) ?>"><?= e($serie->titel()) ?></a>
                                    <br><span class="gedaempft"><?= e($serie->record->period->label() . ' · ' . $serie->record->direction->label()) ?></span>
                                </td>
                                <td class="zahl"><?= e(Betrag::format($serie->daten->expectedGross) . ' ' . $serie->daten->currency) ?></td>
                                <td>
                                    <?php if ($letzter === null): ?>
                                        –
                                    <?php else: ?>
                                        <a href="/app/belege/pruefen/<?= e((string) $letzter->documentId) ?>">vom <?= e($letzter->datum->format('d.m.Y')) ?></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="gedaempft">
                „Überfällig“: die Toleranz der Serie ist vorbei. Liegt der Beleg schon im Posteingang, zählt er erst nach dem Prüfen.
            </p>
        <?php endif; ?>
    </section>
<?php endif; ?>
