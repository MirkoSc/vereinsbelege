<?php

/**
 * One SEPA payment run (issue #85, E-42): account, dates, the transfers
 * with their receipts and whether the matching has found them yet; the
 * file as download and, while it is ordered, "verwerfen". Only components
 * from /admin/designsystem, no JavaScript.
 *
 * @var \App\Service\Bank\Zahllauf $lauf
 * @var string $csrf
 */

use App\Domain\Iban;
use App\Domain\ZahllaufStatus;
use App\Service\Processing\Betrag;

$id = (string) $lauf->record->id;
$angewiesen = $lauf->record->status === ZahllaufStatus::Angewiesen;
?>
<section>
    <h2>SEPA-Zahllauf Nr. <?= e($id) ?></h2>
    <p><a href="/app/zahllaeufe">← Alle Zahlläufe</a></p>

    <div class="tabelle-rahmen">
        <table class="tabelle">
            <tbody>
                <tr><th scope="row">Status</th><td><span class="marke <?= e($lauf->markeKlasse()) ?>"><?= e($lauf->statusText()) ?></span></td></tr>
                <tr><th scope="row">Auftraggeber</th><td><?= e($lauf->auftraggeber) ?></td></tr>
                <tr><th scope="row">Konto</th><td><?= e($lauf->kontoName) ?><br><span class="gedaempft"><?= e(Iban::formatieren($lauf->iban) . ($lauf->bic !== '' ? ' · ' . $lauf->bic : '')) ?></span></td></tr>
                <tr><th scope="row">Angelegt</th><td><?= e($lauf->erstellt->format('d.m.Y H:i')) ?></td></tr>
                <tr><th scope="row">Ausführung</th><td><?= e($lauf->record->executionDate->format('d.m.Y')) ?></td></tr>
                <tr><th scope="row">Summe</th><td><?= e(Betrag::format($lauf->summe()) . ' €') ?> (<?= e((string) count($lauf->ueberweisungen)) ?> Überweisungen)</td></tr>
                <tr><th scope="row">Nachrichten-ID</th><td><?= e($lauf->msgId) ?></td></tr>
            </tbody>
        </table>
    </div>

    <?php if ($angewiesen): ?>
        <p class="knopfreihe">
            <a class="knopf knopf-primaer" href="/app/zahllaeufe/<?= e($id) ?>/datei" download>SEPA-Datei herunterladen</a>
        </p>
        <p class="feld-hilfe">
            Die Datei (pain.001.001.09) wird bei jedem Herunterladen neu erzeugt und nirgends gespeichert. Laden Sie sie
            nur einmal im Online-Banking hoch – jede Einreichung wird ausgeführt.
        </p>
    <?php endif; ?>
</section>

<section>
    <h3>Überweisungen</h3>
    <div class="tabelle-rahmen">
        <table class="tabelle tabelle-karten">
            <thead>
                <tr>
                    <th scope="col">Empfänger</th>
                    <th scope="col">Verwendungszweck</th>
                    <th scope="col" class="zahl">Betrag</th>
                    <th scope="col">Abgleich</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lauf->ueberweisungen as $u): ?>
                    <tr>
                        <td data-label="Empfänger"><?= e($u->name) ?><br><span class="gedaempft"><?= e(Iban::formatieren($u->iban)) ?></span></td>
                        <td data-label="Verwendungszweck">
                            <?= e($u->zweck) ?>
                            <br><span class="gedaempft"><?= e(sprintf('Referenz %s · %d %s', $u->endToEndId, count($u->belege), count($u->belege) === 1 ? 'Beleg' : 'Belege')) ?></span>
                        </td>
                        <td data-label="Betrag" class="zahl"><?= e(Betrag::format($u->cent) . ' €') ?></td>
                        <td data-label="Abgleich">
                            <?php if ($lauf->ueberweisungAusgeglichen($u)): ?>
                                <span class="marke marke-ok">zugeordnet</span>
                            <?php else: ?>
                                <span class="marke marke-warnung">offen</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($lauf->verwerfbar()): ?>
    <section>
        <h3>Zahllauf verwerfen</h3>
        <p>
            Nur wenn die Bank die Datei abgelehnt hat oder sie nie eingereicht wurde: Verwerfen gibt die Belege für einen
            neuen Zahllauf frei. Wurde schon überwiesen, entstünde so eine doppelte Zahlung.
        </p>
        <form method="post" action="/app/zahllaeufe/<?= e($id) ?>/verwerfen" class="formular">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <p class="knopfreihe"><button type="submit" class="knopf knopf-gefahr">Zahllauf verwerfen</button></p>
        </form>
    </section>
<?php endif; ?>
