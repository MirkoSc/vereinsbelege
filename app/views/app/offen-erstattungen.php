<?php

/**
 * Open reimbursements per submitter (M10-5, issue #69, docs/spec/
 * 04-bank-und-abgleich.md section 5 "Übersichten"): receipts with
 * Zahlungsweg "Erstattung" not (fully) paid out, grouped by the name the
 * submission gave, with the sum still open.
 *
 * @var bool $entsperrt
 * @var list<\App\Service\Bank\Erstattungsempfaenger> $empfaenger
 * @var int $unlesbar
 * @var string|null $belegPfad
 */

use App\Service\Processing\Betrag;

?>
<section>
    <h2>Offene Erstattungen</h2>
    <?php require __DIR__ . '/../partials/offen-reiter.php'; ?>
    <p class="gedaempft">
        Belege, die jemand für den Verein ausgelegt hat und die noch nicht (ganz) erstattet sind – je Einreicher mit der
        offenen Summe. Erstattet ist ein Beleg, sobald ihm die Überweisung an den Einreicher zugeordnet ist.
    </p>
</section>

<?php if ($entsperrt): ?>
    <?php if ($unlesbar > 0): ?>
        <p class="hinweis hinweis-warnung"><?= e(sprintf('%d %s nicht entschlüsseln.', $unlesbar, $unlesbar === 1 ? 'Beleg ließ sich' : 'Belege ließen sich')) ?></p>
    <?php endif; ?>
    <?php if ($empfaenger === []): ?>
        <div class="leer">Keine offenen Erstattungen.</div>
    <?php endif; ?>
    <?php foreach ($empfaenger as $g): ?>
        <section class="karte">
            <h3><?= e($g->name !== '' ? $g->name : 'Ohne Angabe des Einreichers') ?></h3>
            <p>
                <strong>Offen:</strong>
                <?= e(implode(' + ', array_map(static fn(string $w, int $c): string => Betrag::format($c) . ' ' . $w, array_keys($g->summen), $g->summen))) ?>
                <?php if ($g->iban !== ''): ?>
                    <br><span class="gedaempft">IBAN laut Einreichung: <?= e($g->iban) ?></span>
                <?php endif; ?>
            </p>
            <div class="tabelle-rahmen">
                <table class="tabelle">
                    <caption><?= e(sprintf('%d %s', count($g->posten), count($g->posten) === 1 ? 'Beleg' : 'Belege')) ?></caption>
                    <thead>
                        <tr>
                            <th scope="col">Beleg</th>
                            <th scope="col" class="zahl">Brutto</th>
                            <th scope="col" class="zahl">Offen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($g->posten as $p): ?>
                            <tr>
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
                                <td class="zahl"><?= e(Betrag::format($p->brutto) . ' ' . $p->waehrung) ?></td>
                                <td class="zahl"><?= e(Betrag::format($p->offen) . ' ' . $p->waehrung) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
