<?php

/**
 * SEPA payment runs for reimbursements (issue #85, docs/spec/
 * 04-bank-und-abgleich.md section 5 "Stand SEPA-Zahllauf", E-42): newest
 * first, with account, execution date, count, sum and status. Payees and
 * amounts are vault data - without an unlocked vault the list is empty.
 *
 * @var bool $entsperrt
 * @var list<\App\Service\Bank\Zahllauf> $laeufe
 * @var int $unlesbar
 */

use App\Service\Processing\Betrag;

?>
<section>
    <h2>SEPA-Zahlläufe</h2>
    <p class="gedaempft">
        Ein Zahllauf fasst offene Erstattungen zu einer SEPA-Sammelüberweisung (pain.001) zusammen, die Sie im
        Online-Banking hochladen. Er bleibt „angewiesen“, bis der Abgleich die Überweisungen auf dem Kontoauszug
        gefunden und den Belegen zugeordnet hat.
    </p>
    <p class="knopfreihe">
        <a class="knopf knopf-primaer" href="/app/zahllaeufe/neu">Neuer Zahllauf</a>
        <a class="knopf" href="/app/offen/erstattungen">Offene Erstattungen</a>
    </p>
</section>

<?php if (!$entsperrt): ?>
    <p class="hinweis hinweis-warnung">Ihr Tresor ist in dieser Sitzung nicht entsperrt. Bitte melden Sie sich neu an.</p>
<?php else: ?>
    <?php if ($unlesbar > 0): ?>
        <p class="hinweis hinweis-warnung"><?= e(sprintf('%d %s nicht entschlüsseln.', $unlesbar, $unlesbar === 1 ? 'Zahllauf ließ sich' : 'Zahlläufe ließen sich')) ?></p>
    <?php endif; ?>
    <?php if ($laeufe === []): ?>
        <div class="leer">Noch kein Zahllauf.</div>
    <?php else: ?>
        <div class="tabelle-rahmen">
            <table class="tabelle tabelle-karten">
                <thead>
                    <tr>
                        <th scope="col">Zahllauf</th>
                        <th scope="col">Konto</th>
                        <th scope="col">Ausführung</th>
                        <th scope="col" class="zahl">Überweisungen</th>
                        <th scope="col" class="zahl">Summe</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($laeufe as $lauf): ?>
                        <tr>
                            <td data-label="Zahllauf"><a href="/app/zahllaeufe/<?= e((string) $lauf->record->id) ?>"><?= e('Nr. ' . $lauf->record->id . ' vom ' . $lauf->record->createdAt->format('d.m.Y')) ?></a></td>
                            <td data-label="Konto"><?= e($lauf->kontoName) ?></td>
                            <td data-label="Ausführung"><?= e($lauf->record->executionDate->format('d.m.Y')) ?></td>
                            <td data-label="Überweisungen" class="zahl"><?= e((string) count($lauf->ueberweisungen)) ?></td>
                            <td data-label="Summe" class="zahl"><?= e(Betrag::format($lauf->summe()) . ' €') ?></td>
                            <td data-label="Status"><span class="marke <?= e($lauf->markeKlasse()) ?>"><?= e($lauf->statusText()) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php endif; ?>
