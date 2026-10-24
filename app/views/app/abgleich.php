<?php

/**
 * Matching receipt <-> booking (M10-2, issue #66, docs/spec/
 * 04-bank-und-abgleich.md section 5 "Punktebewertung", E-35): the button
 * that allocates what is unambiguous, then the suggestions - best first,
 * each with the signals behind its points and "Übernehmen" -, then the
 * points and thresholds.
 *
 * Only components from /admin/designsystem, no JavaScript. Suggestions are
 * cards, so nothing scrolls sideways at 360 px.
 *
 * @var string $csrf
 * @var bool $entsperrt
 * @var \App\Service\Bank\Abgleichsstand|null $stand null without an unlocked vault
 * @var \App\Service\Bank\Abgleichsparameter $parameter
 * @var \App\Service\Bank\BankRuleViolation|null $fehler of the settings form
 * @var array<string, string> $felder the settings form as sent, after an error
 */

use App\Domain\Iban;
use App\Service\Bank\Abgleichsparameter;
use App\Service\Bank\Signal;
use App\Service\Processing\Betrag;

$betrag = static fn(int $cent): string => e(Betrag::format($cent)) . ' €';
$datum = static fn(\DateTimeImmutable $d): string => e($d->format('d.m.Y'));
$fehlerAn = static fn(string $feld): string => $fehler?->feld === $feld ? ' aria-invalid="true" aria-describedby="abgleich-fehler"' : '';
$werte = $parameter->alsWerte();
$wert = static function (string $feld) use ($felder, $werte): string {
    if (array_key_exists($feld, $felder)) {
        return e($felder[$feld]);
    }
    if (str_starts_with($feld, 'punkte_')) {
        return e((string) ($werte['punkte'][substr($feld, 7)] ?? ''));
    }

    return e((string) ($werte[$feld] ?? ''));
};
$weitere = [
    'skonto_prozent' => ['Skonto-Toleranz (%)', 'Bis zu so viel Prozent weniger als der offene Betrag zählt als „Skonto“.'],
    'name_aehnlichkeit_prozent' => ['Namensähnlichkeit ab (%)', 'Ab dieser Ähnlichkeit zählt der Name der Gegenseite als „ähnlich“.'],
    'fenster_vor_tage' => ['Zeitraum: Tage vor dem Rechnungsdatum', ''],
    'fenster_nach_tage' => ['Zeitraum: Tage nach der Fälligkeit', 'Ohne Fälligkeit zählt das Rechnungsdatum.'],
    'auto_schwelle' => ['Automatisch ab Punkten', ''],
    'auto_abstand' => ['Abstand zum Zweitbesten (Punkte)', 'So weit muss das beste Paar vor jedem anderen Kandidaten von Beleg und Buchung liegen.'],
    'vorschlag_ab' => ['Vorschlag ab Punkten', 'Darunter wird ein Paar nicht angezeigt.'],
];
?>
<section>
    <h2>Abgleich Beleg ↔ Buchung</h2>

    <p class="gedaempft">
        Der Abgleich bewertet jedes Paar aus offenem Beleg und Buchung ohne vollständigen Beleg mit Punkten.
        Automatisch zugeordnet wird nur, was eindeutig ist: mindestens <?= e((string) $parameter->autoSchwelle) ?> Punkte,
        mindestens <?= e((string) $parameter->autoAbstand) ?> Punkte Vorsprung vor jedem anderen Kandidaten
        und der Betrag gleicht Beleg und Buchung vollständig aus. Alles andere erscheint als Vorschlag.
        Jede Zuordnung lässt sich wieder lösen.
    </p>

    <?php if (!$entsperrt || $stand === null): ?>
        <p class="hinweis hinweis-info">
            Belege und Buchungen sind verschlüsselt. Melden Sie sich neu an, um den Abgleich zu nutzen.
        </p>
    <?php else: ?>
        <form method="post" action="/app/abgleich/ausfuehren" class="knopfreihe">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit" class="knopf knopf-primaer"<?= $stand->plan->auto === [] ? ' disabled' : '' ?>>
                <?= match (count($stand->plan->auto)) {
                    0 => 'Nichts eindeutig zuzuordnen',
                    1 => '1 eindeutige Zuordnung anlegen',
                    default => e((string) count($stand->plan->auto)) . ' eindeutige Zuordnungen anlegen',
                } ?>
            </button>
        </form>

        <h3>Vorschläge</h3>
        <?php if ($stand->plan->vorschlaege === []): ?>
            <div class="leer">Keine Vorschläge – entweder ist alles zugeordnet, oder kein Paar erreicht <?= e((string) $parameter->vorschlagAb) ?> Punkte.</div>
        <?php else: ?>
            <p class="gedaempft">Je Beleg die bis zu drei besten Buchungen, sortiert nach Punkten.</p>
            <?php foreach ($stand->plan->vorschlaege as $vorschlag): ?>
                <?php
                $beleg = $stand->belege[$vorschlag->invoiceId] ?? null;
                $buchung = $stand->buchungen[$vorschlag->transactionId] ?? null;
                if ($beleg === null || $buchung === null) {
                    continue;
                }
                ?>
                <article class="karte">
                    <h4>
                        <span class="marke"><?= e((string) $vorschlag->score) ?> Punkte</span>
                        <?= e($beleg->lieferant !== '' ? $beleg->lieferant : 'Beleg ohne Lieferant') ?>
                    </h4>
                    <dl>
                        <dt>Beleg</dt>
                        <dd>
                            <a href="/app/belege/pruefen/<?= e((string) $beleg->record->documentId) ?>"><?= $beleg->daten->invoiceNumber !== '' ? 'Nr. ' . e($beleg->daten->invoiceNumber) : 'ohne Nummer' ?></a>
                            vom <?= $datum($beleg->record->invoiceDate) ?>,
                            offen <?= $betrag($beleg->offen) ?>
                        </dd>
                        <dt>Buchung</dt>
                        <dd>
                            <a href="/app/buchungen/<?= e((string) $buchung->buchung->id) ?>"><?= $datum($buchung->buchung->bookingDate) ?></a>,
                            <?= $betrag($buchung->buchung->amount) ?>
                            <?= $buchung->offen !== abs($buchung->buchung->amount) ? '(noch ohne Beleg: ' . $betrag($buchung->offen) . ')' : '' ?><br>
                            <?= e($buchung->buchung->counterpartyName !== '' ? $buchung->buchung->counterpartyName : 'Gegenseite unbekannt') ?>
                            <?= $buchung->buchung->counterpartyIban !== '' ? '<span class="gedaempft">' . e(Iban::formatieren($buchung->buchung->counterpartyIban)) . '</span>' : '' ?><br>
                            <span class="gedaempft"><?= e($buchung->buchung->purpose) ?></span>
                        </dd>
                        <dt>Begründung</dt>
                        <dd>
                            <ul>
                                <?php foreach ($vorschlag->begruendung() as $grund): ?>
                                    <li><?= e(($grund['punkte'] > 0 ? '+' : '') . $grund['punkte']) ?> <?= e($grund['label']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </dd>
                    </dl>
                    <form method="post" action="/app/abgleich/uebernehmen" class="knopfreihe">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="beleg" value="<?= e((string) $vorschlag->invoiceId) ?>">
                        <input type="hidden" name="buchung" value="<?= e((string) $vorschlag->transactionId) ?>">
                        <input type="hidden" name="betrag" value="<?= e((string) $vorschlag->betrag) ?>">
                        <button type="submit" class="knopf">Übernehmen (<?= $betrag($vorschlag->betrag) ?>)</button>
                    </form>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="schmal" id="einstellungen">
    <h3>Punktebewertung</h3>
    <p class="gedaempft">
        Punkte je Signal und die Schwellen. Änderungen gelten ab dem nächsten Abgleich; bestehende Zuordnungen bleiben.
    </p>
    <?php if ($fehler !== null): ?>
        <p class="hinweis hinweis-fehler" role="alert" id="abgleich-fehler"><?= e($fehler->getMessage()) ?></p>
    <?php endif; ?>
    <form method="post" action="/app/abgleich/einstellungen" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <fieldset>
            <legend>Punkte je Signal</legend>
            <?php foreach (array_keys(Abgleichsparameter::PUNKTE_START) as $signal): ?>
                <?php $feld = 'punkte_' . $signal; ?>
                <label for="abgleich-<?= e($feld) ?>"><?= e(Signal::from($signal)->label()) ?>
                    <input type="number" inputmode="numeric" id="abgleich-<?= e($feld) ?>" name="<?= e($feld) ?>" value="<?= $wert($feld) ?>"
                           min="<?= e((string) Abgleichsparameter::PUNKTE_MIN) ?>" max="<?= e((string) Abgleichsparameter::PUNKTE_MAX) ?>" step="1" required<?= $fehlerAn($feld) ?>>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <fieldset>
            <legend>Toleranzen und Schwellen</legend>
            <?php foreach (Abgleichsparameter::WERTE as $feld => [, $min, $max]): ?>
                <label for="abgleich-<?= e($feld) ?>"><?= e($weitere[$feld][0]) ?>
                    <input type="number" inputmode="numeric" id="abgleich-<?= e($feld) ?>" name="<?= e($feld) ?>" value="<?= $wert($feld) ?>"
                           min="<?= e((string) $min) ?>" max="<?= e((string) $max) ?>" step="1" required<?= $fehlerAn($feld) ?>>
                </label>
                <?php if ($weitere[$feld][1] !== ''): ?>
                    <p class="feld-hilfe"><?= e($weitere[$feld][1]) ?></p>
                <?php endif; ?>
            <?php endforeach; ?>
        </fieldset>
        <p class="knopfreihe">
            <button type="submit" class="knopf">Speichern</button>
        </p>
    </form>
</section>
