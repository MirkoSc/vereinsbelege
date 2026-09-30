<?php

/**
 * The review queue (issue #37/M6-3): every document waiting to be captured
 * as a receipt, oldest first. Only plaintext columns - reference, date,
 * status, cost center - so the list needs no vault; the review page itself
 * does.
 *
 * @var list<\App\Domain\InboxItem> $eintraege
 * @var bool $abgeschnitten
 * @var array<int, string> $kostenstellen every cost center by id
 * @var bool $entsperrt
 */

$heute = new \DateTimeImmutable('today');
?>
<section>
    <h2>Belege prüfen</h2>

    <p class="gedaempft">
        Angenommene Einreichungen und intern erfasste Belege, die noch fachlich erfasst werden müssen –
        älteste zuerst. Datum, Beträge, Lieferant bzw. Zahler, Kategorie und Kostenstelle eintragen, dann
        „Geprüft, nächster“.
    </p>

    <?php if (!$entsperrt): ?>
        <p class="hinweis hinweis-info">
            Belegbilder und Angaben sind verschlüsselt und nur mit entsperrtem Tresor lesbar.
            Melden Sie sich neu an, um Belege zu prüfen.
        </p>
    <?php endif; ?>

    <?php if ($eintraege === []): ?>
        <div class="leer">Nichts zu prüfen – alle Belege sind erfasst.</div>
    <?php else: ?>
        <?php if ($entsperrt): ?>
            <p class="knopfreihe">
                <a class="knopf knopf-primaer" href="/app/belege/pruefen/<?= e((string) $eintraege[0]->document->id) ?>">Mit dem ältesten beginnen</a>
            </p>
        <?php endif; ?>

        <div class="tabelle-rahmen">
            <table class="tabelle">
                <caption><?= e(sprintf('%d %s zu prüfen%s', count($eintraege), count($eintraege) === 1 ? 'Beleg' : 'Belege', $abgeschnitten ? ' (Liste gekürzt)' : '')) ?>, älteste zuerst</caption>
                <thead>
                    <tr>
                        <th scope="col">Referenz</th>
                        <th scope="col">Eingang</th>
                        <th scope="col">Status</th>
                        <th scope="col">Mannschaft/Bereich</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($eintraege as $item): ?>
                        <?php $document = $item->document; ?>
                        <tr>
                            <td>
                                <a href="/app/belege/pruefen/<?= e((string) $document->id) ?>"><?= e($item->referenz ?? '#' . $document->id) ?></a>
                            </td>
                            <td><?= e($item->eingegangenAm?->format('d.m.Y H:i') ?? '–') ?></td>
                            <td><?php require __DIR__ . '/posteingang-status.php'; ?></td>
                            <td><?= e($document->costCenterId === null ? '–' : ($kostenstellen[$document->costCenterId] ?? '?')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
