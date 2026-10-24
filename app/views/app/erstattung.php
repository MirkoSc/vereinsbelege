<?php

/**
 * Reimbursements for one booking (M10-3, issue #67, docs/spec/
 * 04-bank-und-abgleich.md section 5, E-36): the open reimbursements whose
 * submitter gave the booking's counterparty IBAN, and the combinations of
 * them that add up to what the booking leaves open - each with one button.
 *
 * Only components from /admin/designsystem, no JavaScript. The form sends
 * invoice ids only.
 *
 * @var string $csrf
 * @var \App\Service\Bank\Erstattungsvorschlag $vorschlag
 */

use App\Domain\Iban;
use App\Service\Bank\Kombinationssuche;
use App\Service\Processing\Betrag;

$buchung = $vorschlag->buchung;
$betrag = static fn(int $cent): string => e(Betrag::format($cent)) . ' €';
$beschreibung = static fn(\App\Service\Bank\Erstattungskandidat $k): string => e($k->invoiceDate->format('d.m.Y'))
    . ($k->invoiceNumber === '' ? '' : ' · ' . e($k->invoiceNumber))
    . ($k->purposeShort === '' ? '' : ' · ' . e($k->purposeShort));
?>
<section class="schmal">
    <h2>Erstattung zuordnen</h2>

    <p><a href="/app/buchungen/<?= e((string) $buchung->id) ?>">← Buchung vom <?= e($buchung->bookingDate->format('d.m.Y')) ?></a></p>

    <div class="tabelle-rahmen">
        <table class="tabelle tabelle-karten">
            <caption>Buchung</caption>
            <tbody>
                <tr><th scope="row">Betrag</th><td data-label="Betrag"><?= $betrag($buchung->amount) ?></td></tr>
                <tr><th scope="row">Noch ohne Beleg</th><td data-label="Noch ohne Beleg"><?= $betrag($vorschlag->offen) ?></td></tr>
                <tr><th scope="row">Gegenseite</th><td data-label="Gegenseite"><?= $buchung->counterpartyName === '' ? '–' : e($buchung->counterpartyName) ?></td></tr>
                <?php if ($buchung->counterpartyIban !== ''): ?>
                    <tr><th scope="row">IBAN der Gegenseite</th><td data-label="IBAN der Gegenseite"><?= e(Iban::formatieren($buchung->counterpartyIban)) ?></td></tr>
                <?php endif; ?>
                <tr><th scope="row">Zweck</th><td data-label="Zweck"><?= $buchung->purpose === '' ? '–' : e($buchung->purpose) ?></td></tr>
            </tbody>
        </table>
    </div>

    <?php if ($vorschlag->hinweis !== null): ?>
        <p class="hinweis hinweis-info"><?= e($vorschlag->hinweis) ?></p>
    <?php elseif ($vorschlag->kandidaten === []): ?>
        <p class="hinweis hinweis-info">
            Keine offene Erstattung mit dieser IBAN. Gesucht wird unter den Belegen mit Zahlungsweg „Erstattung“,
            deren Einreicher bei der Einreichung diese IBAN für die Überweisung angegeben hat.
        </p>
    <?php else: ?>
        <h3>Passende Kombinationen</h3>
        <?php if ($vorschlag->kombinationen === []): ?>
            <p class="hinweis hinweis-info">
                Keine Kombination aus höchstens <?= e((string) Kombinationssuche::MAX_BELEGE) ?> offenen Erstattungen
                ergibt genau <?= $betrag($vorschlag->offen) ?>. Bitte die Belege einzeln prüfen.
            </p>
        <?php endif; ?>
        <?php foreach ($vorschlag->kombinationen as $nr => $kombination): ?>
            <?php $ids = implode(',', array_map(static fn($k): string => (string) $k->invoiceId, $kombination)); ?>
            <form method="post" action="/app/buchungen/<?= e((string) $buchung->id) ?>/erstattung" class="formular">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="belege" value="<?= e($ids) ?>">
                <div class="tabelle-rahmen">
                    <table class="tabelle tabelle-karten">
                        <caption>Vorschlag <?= e((string) ($nr + 1)) ?>: <?= count($kombination) === 1 ? 'ein Beleg' : e((string) count($kombination)) . ' Belege' ?></caption>
                        <thead>
                            <tr><th scope="col">Beleg</th><th scope="col">Einreicher</th><th scope="col">Offen</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($kombination as $kandidat): ?>
                                <tr>
                                    <td data-label="Beleg"><a href="/app/belege/pruefen/<?= e((string) $kandidat->documentId) ?>"><?= $beschreibung($kandidat) ?></a></td>
                                    <td data-label="Einreicher"><?= $kandidat->einreicher === '' ? '–' : e($kandidat->einreicher) ?></td>
                                    <td data-label="Offen"><?= $betrag($kandidat->offen) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="knopfreihe">
                    <button type="submit" class="knopf<?= $nr === 0 ? ' knopf-primaer' : '' ?>">Diese Belege zuordnen</button>
                </p>
            </form>
        <?php endforeach; ?>
        <?php if (!$vorschlag->vollstaendig): ?>
            <p class="hinweis hinweis-warnung">
                Die Suche wurde begrenzt (höchstens <?= e((string) Kombinationssuche::MAX_BELEGE) ?> Belege je Kombination,
                die <?= e((string) Kombinationssuche::MAX_KANDIDATEN) ?> ältesten offenen Erstattungen, begrenzte Rechenzeit) –
                es kann weitere Kombinationen geben.
            </p>
        <?php endif; ?>

        <h3>Offene Erstattungen mit dieser IBAN</h3>
        <div class="tabelle-rahmen">
            <table class="tabelle tabelle-karten">
                <thead>
                    <tr><th scope="col">Beleg</th><th scope="col">Einreicher</th><th scope="col">Offen</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($vorschlag->kandidaten as $kandidat): ?>
                        <tr>
                            <td data-label="Beleg"><a href="/app/belege/pruefen/<?= e((string) $kandidat->documentId) ?>"><?= $beschreibung($kandidat) ?></a></td>
                            <td data-label="Einreicher"><?= $kandidat->einreicher === '' ? '–' : e($kandidat->einreicher) ?></td>
                            <td data-label="Offen"><?= $betrag($kandidat->offen) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
