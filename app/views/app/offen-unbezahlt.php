<?php

/**
 * Unpaid receipts (M10-5, issue #69, docs/spec/04-bank-und-abgleich.md
 * section 5 "Übersichten", E-37): Zahlungsweg "Konto", open or partly
 * paid, due date first. Receipts captured before the matching existed
 * (Migration 037) are a list of their own, not a flood of "offen".
 *
 * @var bool $entsperrt
 * @var list<\App\Service\Bank\OffenerPosten> $posten
 * @var list<array{richtung: \App\Domain\InvoiceDirection, waehrung: string, cent: int, anzahl: int}> $summen
 * @var bool $altbestand
 * @var int $altbestandAnzahl
 * @var int $unlesbar
 * @var string|null $belegPfad
 */

use App\Domain\InvoiceDirection;
use App\Domain\PaymentStatus;
use App\Service\Bank\BelegFaelligkeit;
use App\Service\Processing\Betrag;

$ueberfaellig = count(array_filter($posten, static fn($p): bool => $p->faelligkeit === BelegFaelligkeit::Ueberfaellig));
?>
<section>
    <h2>Unbezahlte Rechnungen</h2>
    <?php require __DIR__ . '/../partials/offen-reiter.php'; ?>
    <p class="gedaempft">
        Belege mit Zahlungsweg „über Konto/Kasse“, denen noch keine oder nicht genug Buchungen zugeordnet sind –
        Ausgaben, die der Verein zahlen muss, und Einnahmen, auf die er wartet. Fällige zuerst.
    </p>
</section>

<?php if ($entsperrt): ?>
    <section>
        <?php if ($altbestand): ?>
            <p class="hinweis hinweis-info">
                Diese Belege wurden erfasst, bevor es den Abgleich gab. Sie stehen deshalb alle auf „offen“, auch wenn sie
                längst bezahlt sind (z. B. Kassenbelege aus dem Archiv). Stellen Sie in der Prüfansicht den Zahlungsweg richtig
                oder ordnen Sie die Buchung zu. <a href="/app/offen/rechnungen">Zurück zu den neueren Belegen</a>
            </p>
        <?php elseif ($altbestandAnzahl > 0): ?>
            <p class="hinweis hinweis-info">
                <?= e(sprintf(
                    '%d %s vor Einführung des Abgleichs erfasst und hier nicht aufgeführt.',
                    $altbestandAnzahl,
                    $altbestandAnzahl === 1 ? 'Beleg wurde' : 'Belege wurden',
                )) ?>
                <a href="/app/offen/rechnungen?altbestand=1">Diese Belege anzeigen</a>
            </p>
        <?php endif; ?>
        <?php if ($unlesbar > 0): ?>
            <p class="hinweis hinweis-warnung"><?= e(sprintf('%d %s nicht entschlüsseln.', $unlesbar, $unlesbar === 1 ? 'Beleg ließ sich' : 'Belege ließen sich')) ?></p>
        <?php endif; ?>

        <?php if ($posten === []): ?>
            <div class="leer">Keine unbezahlten Rechnungen.</div>
        <?php else: ?>
            <?php if ($ueberfaellig > 0): ?>
                <p class="hinweis hinweis-fehler"><?= e(sprintf('%d %s überfällig.', $ueberfaellig, $ueberfaellig === 1 ? 'Rechnung ist' : 'Rechnungen sind')) ?></p>
            <?php endif; ?>
            <?php foreach ($summen as $summe): ?>
                <p>
                    <strong><?= e($summe['richtung'] === InvoiceDirection::Ausgabe ? 'Noch zu zahlen:' : 'Noch zu erhalten:') ?></strong>
                    <?= e(Betrag::format($summe['cent']) . ' ' . $summe['waehrung']) ?>
                    <span class="gedaempft"><?= e(sprintf('aus %d %s', $summe['anzahl'], $summe['anzahl'] === 1 ? 'Beleg' : 'Belegen')) ?></span>
                </p>
            <?php endforeach; ?>
            <div class="tabelle-rahmen">
                <table class="tabelle">
                    <caption><?= e(sprintf('%d %s', count($posten), count($posten) === 1 ? 'Beleg' : 'Belege')) ?></caption>
                    <thead>
                        <tr>
                            <th scope="col">Fällig</th>
                            <th scope="col">Beleg</th>
                            <th scope="col">Richtung</th>
                            <th scope="col" class="zahl">Brutto</th>
                            <th scope="col" class="zahl">Offen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($posten as $p): ?>
                            <tr>
                                <td>
                                    <?= e($p->record->dueDate?->format('d.m.Y') ?? '–') ?>
                                    <?php if ($p->faelligkeit === BelegFaelligkeit::Ueberfaellig): ?>
                                        <br><span class="marke marke-fehler">überfällig</span>
                                    <?php elseif ($p->faelligkeit === BelegFaelligkeit::Faellig): ?>
                                        <br><span class="marke marke-warnung">fällig</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($belegPfad !== null): ?>
                                        <a href="<?= e($belegPfad . $p->record->documentId) ?>"><?= e($p->lieferant !== '' ? $p->lieferant : 'ohne Lieferant') ?></a>
                                    <?php else: ?>
                                        <?= e($p->lieferant !== '' ? $p->lieferant : 'ohne Lieferant') ?>
                                    <?php endif; ?>
                                    <br><span class="gedaempft"><?= e(trim(($p->nummer !== '' ? $p->nummer . ' · ' : '') . 'vom ' . $p->record->invoiceDate->format('d.m.Y'))) ?></span>
                                    <?php if ($p->zweck !== ''): ?>
                                        <br><span class="gedaempft"><?= e($p->zweck) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= e($p->record->direction->label()) ?>
                                    <?php if ($p->record->paymentStatus === PaymentStatus::Teilbezahlt): ?>
                                        <br><span class="marke marke-warnung"><?= e(PaymentStatus::Teilbezahlt->label()) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="zahl"><?= e(Betrag::format($p->brutto) . ' ' . $p->waehrung) ?></td>
                                <td class="zahl"><?= e(Betrag::format($p->offen) . ' ' . $p->waehrung) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="gedaempft">
                „Fällig“: Fälligkeit heute oder in den nächsten <?= e((string) BelegFaelligkeit::BALD_TAGE) ?> Tagen. Belege ohne
                Fälligkeitsdatum stehen am Ende. Bezahlt ist ein Beleg, sobald ihm Buchungen über den ganzen Betrag zugeordnet sind.
            </p>
        <?php endif; ?>
    </section>
<?php endif; ?>
