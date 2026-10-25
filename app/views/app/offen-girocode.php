<?php

/**
 * The GiroCode (EPC-QR) of one unpaid receipt (issue #86,
 * docs/spec/04-bank-und-abgleich.md section 5, E-40): one code per usable
 * supplier IBAN, the same transfer data as text next to it. Served with
 * `Cache-Control: no-store` (App\App\UebersichtController::girocode()).
 *
 * @var bool $entsperrt
 * @var \App\Service\Bank\Ueberweisung|null $ueberweisung
 * @var string|null $belegPfad
 */

use App\Domain\Iban;
use App\Domain\PaymentStatus;
use App\Service\Processing\Betrag;

$p = $ueberweisung?->posten;
?>
<section>
    <h2>GiroCode zum Bezahlen</h2>
    <p><a href="/app/offen/rechnungen">Zurück zu den unbezahlten Rechnungen</a></p>
    <?php if (!$entsperrt): ?>
        <p class="hinweis hinweis-info">
            Beträge und Lieferanten sind verschlüsselt und nur mit entsperrtem Tresor lesbar.
            Melden Sie sich neu an, um den GiroCode zu sehen.
        </p>
    <?php endif; ?>
</section>

<?php if ($ueberweisung !== null && $p !== null): ?>
    <section>
        <h3>
            <?php if ($belegPfad !== null): ?>
                <a href="<?= e($belegPfad . $p->record->documentId) ?>"><?= e($p->lieferant !== '' ? $p->lieferant : 'ohne Lieferant') ?></a>
            <?php else: ?>
                <?= e($p->lieferant !== '' ? $p->lieferant : 'ohne Lieferant') ?>
            <?php endif; ?>
        </h3>
        <p class="gedaempft">
            <?= e(trim(($p->nummer !== '' ? $p->nummer . ' · ' : '') . 'vom ' . $p->record->invoiceDate->format('d.m.Y'))) ?>
            <?php if ($p->record->dueDate !== null): ?>
                · fällig <?= e($p->record->dueDate->format('d.m.Y')) ?>
            <?php endif; ?>
        </p>
        <p>
            Brutto <?= e(Betrag::format($p->brutto) . ' ' . $p->waehrung) ?>,
            <strong>offen <?= e(Betrag::format($p->offen) . ' ' . $p->waehrung) ?></strong>
            <?php if ($p->record->paymentStatus === PaymentStatus::Teilbezahlt): ?>
                <span class="marke marke-warnung"><?= e(PaymentStatus::Teilbezahlt->label()) ?></span>
            <?php endif; ?>
        </p>

        <?php if ($ueberweisung->grund !== null): ?>
            <p class="hinweis hinweis-warnung">Kein GiroCode: <?= e($ueberweisung->grund) ?></p>
        <?php else: ?>
            <p class="hinweis hinweis-info">
                Mit der Banking-App scannen – sie füllt die Überweisung vor. Prüfen Sie Empfänger, IBAN und Betrag dort
                gegen die Rechnung, bevor Sie sie freigeben. Bezahlt ist der Beleg hier erst, wenn die Buchung aus dem
                Kontoauszug zugeordnet ist – bis dahin steht er weiter in der Liste.
            </p>
            <?php if (count($ueberweisung->codes) > 1): ?>
                <p class="hinweis hinweis-warnung">
                    Beim Lieferanten sind mehrere IBANs hinterlegt. Nehmen Sie den Code der IBAN, die auf der Rechnung steht.
                </p>
            <?php endif; ?>
            <?php if ($p->nummer === ''): ?>
                <p class="hinweis hinweis-warnung">
                    Der Beleg hat keine Rechnungsnummer – der Verwendungszweck ist leer. Tragen Sie ihn in der Banking-App nach.
                </p>
            <?php endif; ?>
            <?php foreach ($ueberweisung->codes as $code): ?>
                <div class="karte">
                    <?= $code->svg() ?>
                    <dl>
                        <dt>Empfänger</dt>
                        <dd><?= e($code->name) ?></dd>
                        <dt>IBAN</dt>
                        <dd><?= e(Iban::formatieren($code->iban)) ?></dd>
                        <?php if ($code->bic !== ''): ?>
                            <dt>BIC</dt>
                            <dd><?= e($code->bic) ?></dd>
                        <?php endif; ?>
                        <dt>Betrag</dt>
                        <dd><?= e(Betrag::format($code->cent) . ' EUR') ?></dd>
                        <dt>Verwendungszweck</dt>
                        <dd><?= e($code->zweck !== '' ? $code->zweck : '–') ?></dd>
                    </dl>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php if ($ueberweisung->verworfeneIbans > 0): ?>
            <p class="gedaempft">
                <?= e(sprintf(
                    '%d beim Lieferanten hinterlegte %s ungültig oder ein Konto des Vereins und %s deshalb keinen Code.',
                    $ueberweisung->verworfeneIbans,
                    $ueberweisung->verworfeneIbans === 1 ? 'IBAN ist' : 'IBANs sind',
                    $ueberweisung->verworfeneIbans === 1 ? 'bekommt' : 'bekommen',
                )) ?>
            </p>
        <?php endif; ?>
    </section>
<?php endif; ?>
