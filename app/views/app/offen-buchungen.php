<?php

/**
 * Bookings without a receipt (M10-5, issue #69, docs/spec/
 * 04-bank-und-abgleich.md section 5 "Übersichten"): `fehlt` and
 * `teilweise`, newest first. What rules (M9-6) or a person marked "kein
 * Beleg nötig" is not here. Per booking: upload the receipt, or a text to
 * ask for it by mail (copied by hand - nothing is sent from here).
 *
 * @var bool $entsperrt
 * @var list<\App\Service\Bank\FehlenderBeleg> $fehlend
 * @var bool $darfHochladen
 * @var string $einreichenUrl
 */

use App\Domain\BankTransactionDocStatus;
use App\Service\Processing\Betrag;

?>
<section>
    <h2>Buchungen ohne Beleg</h2>
    <?php require __DIR__ . '/../partials/offen-reiter.php'; ?>
    <p class="gedaempft">
        Buchungen, zu denen ein Beleg nötig ist, aber noch keiner (oder nicht für den ganzen Betrag) zugeordnet ist.
        Einnahmen, Zinsen und alles, was eine Regel als „kein Beleg nötig“ einordnet, stehen nicht hier –
        <a href="/app/buchungen/regeln">Regeln</a>.
    </p>
</section>

<?php if ($entsperrt): ?>
    <section>
        <?php if ($fehlend === []): ?>
            <div class="leer">Zu jeder Buchung, die einen Beleg braucht, ist einer da.</div>
        <?php else: ?>
            <div class="tabelle-rahmen">
                <table class="tabelle">
                    <caption><?= e(sprintf('%d %s, neueste zuerst', count($fehlend), count($fehlend) === 1 ? 'Buchung' : 'Buchungen')) ?></caption>
                    <thead>
                        <tr>
                            <th scope="col">Datum</th>
                            <th scope="col">Buchung</th>
                            <th scope="col" class="zahl">Betrag</th>
                            <th scope="col" class="zahl">Ohne Beleg</th>
                            <th scope="col">Aktion</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fehlend as $f): ?>
                            <?php
                            $b = $f->buchung;
                            $gegenseite = $b->counterpartyName !== '' ? $b->counterpartyName : 'ohne Gegenseite';
                            $vorlage = sprintf(
                                "Hallo,\n\nzu folgender Buchung fehlt uns noch der Beleg:\n%s, %s %s, %s%s\n\nBitte reiche ihn über %s ein (Foto oder PDF genügt).\n\nVielen Dank!",
                                $b->bookingDate->format('d.m.Y'),
                                Betrag::format(abs($b->amount)),
                                $b->currency,
                                $gegenseite,
                                $b->purpose !== '' ? ' – ' . $b->purpose : '',
                                $einreichenUrl,
                            );
                            ?>
                            <tr>
                                <td><?= e($b->bookingDate->format('d.m.Y')) ?></td>
                                <td>
                                    <a href="/app/buchungen/<?= e((string) $b->id) ?>"><?= e($gegenseite) ?></a>
                                    <?php if ($b->purpose !== ''): ?>
                                        <br><span class="gedaempft"><?= e($b->purpose) ?></span>
                                    <?php endif; ?>
                                    <?php if ($b->docStatus === BankTransactionDocStatus::Teilweise): ?>
                                        <br><span class="marke marke-warnung">teilweise belegt</span>
                                    <?php endif; ?>
                                </td>
                                <td class="zahl"><?= e(Betrag::format($b->amount) . ' ' . $b->currency) ?></td>
                                <td class="zahl"><?= e(Betrag::format($f->fehlt) . ' ' . $b->currency) ?></td>
                                <td>
                                    <?php if ($darfHochladen): ?>
                                        <a href="/app/belege/neu">Beleg hochladen</a>
                                    <?php endif; ?>
                                    <details>
                                        <summary>Beleg anfordern</summary>
                                        <label for="vorlage-<?= e((string) $b->id) ?>">Text für eine Mail</label>
                                        <textarea id="vorlage-<?= e((string) $b->id) ?>" rows="9" readonly><?= e($vorlage) ?></textarea>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="gedaempft">
                „Beleg anfordern“ zeigt einen Text zum Kopieren in eine eigene Mail – von hier wird nichts verschickt.
                Ist der Beleg da, ordnen Sie ihn der Buchung im Abgleich zu.
            </p>
        <?php endif; ?>
    </section>
<?php endif; ?>
