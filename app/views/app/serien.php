<?php

/**
 * Recurring invoices (M8-1, issue #57, docs/spec/03-erfassung-und-ki.md
 * section 9): the suggestions series detection found, then the confirmed
 * series - next expected date first.
 *
 * Only components from /admin/designsystem; the tables scroll sideways on
 * narrow screens like every other table. Names and amounts are vault data:
 * without an unlocked vault there is nothing to show.
 *
 * @var bool $entsperrt
 * @var list<\App\Service\Invoice\SerienAnsicht> $vorschlaege
 * @var list<\App\Service\Invoice\SerienAnsicht> $bestaetigt
 */

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
                    <th scope="col">Betrag</th>
                    <th scope="col">Belege</th>
                    <th scope="col">Nächste Fälligkeit</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($serien as $serie): ?>
                    <tr>
                        <td>
                            <a href="/app/serien/<?= e((string) $serie->record->id) ?>"><?= e($serie->titel()) ?></a>
                            <?php if ($serie->daten->label !== ''): ?>
                                <br><span class="gedaempft"><?= e($serie->lieferant) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($serie->record->period->label()) ?> · <?= e($serie->record->direction->label()) ?></td>
                        <td><?= e(Betrag::format($serie->daten->expectedGross) . ' ' . $serie->daten->currency) ?></td>
                        <td><?= e((string) $serie->anzahl) ?></td>
                        <td><?= e($serie->record->nextExpected?->format('d.m.Y') ?? '–') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
};

?>
<section>
    <h2>Wiederkehrende Rechnungen</h2>

    <p class="gedaempft">
        Miete, Versicherungen, Beiträge: Belege, die regelmäßig vom selben Lieferanten kommen, werden als Serie
        erkannt – so fällt später auf, wenn einer fehlt. Erkannt wird nach dem Prüfen eines Belegs: mindestens drei
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

    <section>
        <h3>Bestätigte Serien</h3>
        <?php if ($bestaetigt === []): ?>
            <div class="leer">Noch keine bestätigte Serie.</div>
        <?php else: ?>
            <?php $tabelle($bestaetigt, sprintf('%d %s, nächste Fälligkeit zuerst', count($bestaetigt), count($bestaetigt) === 1 ? 'Serie' : 'Serien')); ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
