<?php

/**
 * Matching receipt <-> booking (M10-2/M10-4, issues #66/#68, docs/spec/
 * 04-bank-und-abgleich.md section 5, E-35, E-41): the button that
 * allocates what is unambiguous and the suggestions - best first, each with
 * the signals behind its points, "Übernehmen" and "Verwerfen" -, the
 * filters, the selected receipt (allocations, difference, bookings that
 * could take it with a part amount), both lists side by side, then the
 * points and thresholds.
 *
 * Only components from /admin/designsystem plus the two-column grid
 * `abgleich-spalten`, no JavaScript. Everything is cards or forms that wrap,
 * so nothing scrolls sideways at 360 px.
 *
 * @var string $csrf
 * @var bool $entsperrt
 * @var \App\Service\Bank\Abgleichsstand|null $stand null without an unlocked vault or before "Abgleich berechnen"
 * @var \App\Service\Bank\AbgleichFilter $filter
 * @var list<\App\Domain\BankAccount> $konten
 * @var \App\Service\Bank\AbgleichAnzeige\Liste|null $liste null without an unlocked vault
 * @var \App\Service\Bank\AbgleichAnzeige\Detail|null $detail the selected receipt
 * @var \App\Service\Bank\Abgleichsparameter $parameter
 * @var \App\Service\Bank\BankRuleViolation|null $fehler of the settings form
 * @var array<string, string> $felder the settings form as sent, after an error
 */

use App\Domain\BankTransactionDirection;
use App\Domain\Differenzgrund;
use App\Domain\Iban;
use App\Service\Bank\AbgleichFilter;
use App\Service\Bank\Abgleichsparameter;
use App\Service\Bank\Signal;
use App\Service\Processing\Betrag;

$betrag = static fn(int $cent): string => e(Betrag::format($cent)) . ' €';
$datum = static fn(\DateTimeImmutable $d): string => e($d->format('d.m.Y'));
$fehlerAn = static fn(string $feld): string => $fehler?->feld === $feld ? ' aria-invalid="true" aria-describedby="abgleich-fehler"' : '';
$werte = $parameter->alsWerte();
// Hidden fields: CSRF plus the structural filters, so a write comes back to the same view.
$verborgen = static function (array $aendern = []) use ($csrf, $filter): string {
    $html = '<input type="hidden" name="_csrf" value="' . e($csrf) . '">';
    foreach ($filter->abfrage($aendern) as $name => $wert) {
        $html .= '<input type="hidden" name="' . e($name) . '" value="' . e($wert) . '">';
    }

    return $html;
};
$kontoName = [];
foreach ($konten as $konto) {
    $kontoName[$konto->id] = $konto->data->name;
}
// Paging: links while only structure is filtered; with amount or search a POST form that sends them again.
$blaettern = static function (string $feld, int $seite, int $gesamt) use ($filter, $csrf): string {
    $seiten = max(1, (int) ceil($gesamt / AbgleichFilter::SEITE));
    if ($seiten <= 1) {
        return '';
    }
    $html = '<p class="knopfreihe">';
    foreach ([[$seite - 1, 'Zurück'], [$seite + 1, 'Weiter']] as [$ziel, $text]) {
        if ($ziel < 1 || $ziel > $seiten) {
            continue;
        }
        if ($filter->vertraulich()) {
            $html .= '<form method="post" action="/app/abgleich/filter"><input type="hidden" name="_csrf" value="' . e($csrf) . '">';
            foreach ($filter->abfrage([$feld => $ziel]) as $name => $wert) {
                $html .= '<input type="hidden" name="' . e($name) . '" value="' . e($wert) . '">';
            }
            $html .= '<input type="hidden" name="betrag" value="' . e($filter->betrag === null ? '' : Betrag::format($filter->betrag)) . '">'
                . '<input type="hidden" name="suche" value="' . e($filter->suche) . '">'
                . '<button type="submit" class="knopf knopf-still">' . e($text) . '</button></form>';
        } else {
            $html .= '<a class="knopf knopf-still" href="' . e($filter->url([$feld => $ziel])) . '">' . e($text) . '</a>';
        }
    }

    return $html . '<span class="gedaempft">Seite ' . e((string) $seite) . ' von ' . e((string) $seiten) . '</span></p>';
};
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
        Links die offenen Belege, rechts die Buchungen ohne (vollständigen) Beleg. Wählen Sie einen Beleg, um ihn einer
        Buchung zuzuordnen – auch mit einem Teilbetrag. „Abgleich berechnen“ bewertet alle Paare mit Punkten: Eindeutiges
        (mindestens <?= e((string) $parameter->autoSchwelle) ?> Punkte, <?= e((string) $parameter->autoAbstand) ?> Punkte
        Vorsprung, Betrag gleicht beide Seiten aus) lässt sich auf einmal anlegen, alles andere erscheint als Vorschlag.
        Jede Zuordnung lässt sich wieder lösen.
    </p>

    <?php if (!$entsperrt): ?>
        <p class="hinweis hinweis-info">
            Belege und Buchungen sind verschlüsselt. Melden Sie sich neu an, um den Abgleich zu nutzen.
        </p>
    <?php elseif ($stand === null): ?>
        <form method="get" action="/app/abgleich" class="knopfreihe">
            <input type="hidden" name="berechnen" value="1">
            <button type="submit" class="knopf knopf-primaer">Abgleich berechnen</button>
        </form>
        <p class="gedaempft">Bewertet die offenen Belege und Buchungen – angelegt wird dabei noch nichts.</p>
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
            <p class="gedaempft">Je Beleg die bis zu drei besten Buchungen, sortiert nach Punkten. Ein verworfenes Paar wird nicht mehr vorgeschlagen.</p>
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
                            <a href="<?= e($filter->url(['beleg' => $beleg->record->id])) ?>#beleg"><?= $beleg->daten->invoiceNumber !== '' ? 'Nr. ' . e($beleg->daten->invoiceNumber) : 'ohne Nummer' ?></a>
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
                    <div class="knopfreihe">
                        <form method="post" action="/app/abgleich/uebernehmen">
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="beleg" value="<?= e((string) $vorschlag->invoiceId) ?>">
                            <input type="hidden" name="buchung" value="<?= e((string) $vorschlag->transactionId) ?>">
                            <input type="hidden" name="betrag" value="<?= e((string) $vorschlag->betrag) ?>">
                            <button type="submit" class="knopf">Übernehmen (<?= $betrag($vorschlag->betrag) ?>)</button>
                        </form>
                        <form method="post" action="/app/abgleich/verwerfen">
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="zurueck" value="vorschlaege">
                            <input type="hidden" name="beleg" value="<?= e((string) $vorschlag->invoiceId) ?>">
                            <input type="hidden" name="buchung" value="<?= e((string) $vorschlag->transactionId) ?>">
                            <button type="submit" class="knopf knopf-still">Verwerfen</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($entsperrt && $liste !== null): ?>
<section id="abgleich-listen">
    <h3>Offene Belege und Buchungen</h3>
    <form method="post" action="/app/abgleich/filter" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <?php if ($filter->belegId !== null): ?>
            <input type="hidden" name="beleg" value="<?= e((string) $filter->belegId) ?>">
        <?php endif; ?>
        <label for="abgleich-konto">Konto (Buchungen)
            <select id="abgleich-konto" name="konto">
                <option value="">alle Konten</option>
                <?php foreach ($konten as $konto): ?>
                    <option value="<?= e((string) $konto->id) ?>"<?= $filter->kontoId === $konto->id ? ' selected' : '' ?>><?= e($konto->data->name) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label for="abgleich-von" class="feld-kurz">Von
            <input type="date" id="abgleich-von" name="von" value="<?= e($filter->von?->format('Y-m-d') ?? '') ?>">
        </label>
        <label for="abgleich-bis" class="feld-kurz">Bis
            <input type="date" id="abgleich-bis" name="bis" value="<?= e($filter->bis?->format('Y-m-d') ?? '') ?>">
        </label>
        <label for="abgleich-betrag" class="feld-kurz">Betrag (€)
            <input type="text" inputmode="decimal" id="abgleich-betrag" name="betrag" autocomplete="off" value="<?= e($filter->betrag === null ? '' : Betrag::format($filter->betrag)) ?>">
        </label>
        <p class="feld-hilfe">Brutto oder offener Betrag, ohne Vorzeichen.</p>
        <label for="abgleich-suche">Suche
            <input type="search" id="abgleich-suche" name="suche" autocomplete="off" maxlength="<?= e((string) AbgleichFilter::SUCHE_MAX) ?>" value="<?= e($filter->suche) ?>">
        </label>
        <p class="feld-hilfe">Lieferant, Rechnungsnummer, Gegenseite, IBAN oder Verwendungszweck.</p>
        <p class="knopfreihe">
            <button type="submit" class="knopf">Filtern</button>
            <a class="knopf knopf-still" href="/app/abgleich">Zurücksetzen</a>
        </p>
    </form>

    <?php if ($filter->belegId !== null && $detail === null): ?>
        <p class="hinweis hinweis-warnung" id="beleg">Diesen Beleg gibt es nicht oder er liegt außerhalb Ihres Bereichs.</p>
    <?php elseif ($detail !== null): ?>
        <?php
        $b = $detail->beleg;
        $offen = $detail->stand->offen();
        ?>
        <article class="karte" id="beleg">
            <h4>
                <?= e($b->lieferant !== '' ? $b->lieferant : 'Beleg ohne Lieferant') ?>
                <span class="marke<?= $b->record->paymentStatus->ausgeglichen() ? ' marke-ok' : '' ?>"><?= e($b->record->paymentStatus->label()) ?></span>
            </h4>
            <dl>
                <dt>Beleg</dt>
                <dd>
                    <a href="/app/belege/pruefen/<?= e((string) $b->record->documentId) ?>"><?= $b->daten->invoiceNumber !== '' ? 'Nr. ' . e($b->daten->invoiceNumber) : 'ohne Nummer' ?></a>
                    vom <?= $datum($b->record->invoiceDate) ?><?= $b->record->dueDate !== null ? ', fällig ' . $datum($b->record->dueDate) : '' ?>
                </dd>
                <dt>Betrag</dt>
                <dd>
                    brutto <?= $betrag($detail->stand->brutto) ?>, zugeordnet <?= $betrag($detail->stand->zugeordnet) ?>,
                    <strong>offen <?= $betrag($offen) ?></strong>
                    <?php if ($detail->stand->differenz !== null): ?>
                        <br>Differenz <?= $betrag($detail->stand->differenzbetrag()) ?> abgeschlossen: <?= e($detail->stand->differenz->label()) ?>
                    <?php endif; ?>
                </dd>
            </dl>

            <h5>Zuordnungen</h5>
            <?php if ($detail->zuordnungen === []): ?>
                <p class="gedaempft">Noch keiner Buchung zugeordnet.</p>
            <?php else: ?>
                <ul class="abgleich-zeilen">
                    <?php foreach ($detail->zuordnungen as $zeile): ?>
                        <li>
                            <?= $betrag($zeile->zuordnung->amount) ?> –
                            <?php if ($zeile->buchung === null): ?>
                                <span class="gedaempft">Buchung außerhalb Ihres Bereichs</span>
                            <?php else: ?>
                                <a href="/app/buchungen/<?= e((string) $zeile->buchung->id) ?>"><?= $datum($zeile->buchung->bookingDate) ?></a>,
                                <?= e($zeile->buchung->counterpartyName !== '' ? $zeile->buchung->counterpartyName : 'Gegenseite unbekannt') ?>
                            <?php endif; ?>
                            <span class="gedaempft">(<?= e($zeile->zuordnung->method->label()) ?>)</span>
                            <?php if ($zeile->buchung !== null): ?>
                                <form method="post" action="/app/abgleich/loesen" class="knopfreihe">
                                    <?= $verborgen() ?>
                                    <input type="hidden" name="zuordnung" value="<?= e((string) $zeile->zuordnung->id) ?>">
                                    <button type="submit" class="knopf knopf-still">Lösen</button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($detail->stand->differenz !== null): ?>
                <form method="post" action="/app/abgleich/differenz" class="knopfreihe">
                    <?= $verborgen() ?>
                    <input type="hidden" name="grund" value="oeffnen">
                    <button type="submit" class="knopf knopf-still">Differenz wieder öffnen</button>
                </form>
            <?php elseif ($detail->stand->zugeordnet > 0 && $offen > 0): ?>
                <form method="post" action="/app/abgleich/differenz" class="formular">
                    <?= $verborgen() ?>
                    <label for="abgleich-grund">Rest von <?= $betrag($offen) ?> als ausgeglichen abschließen
                        <select id="abgleich-grund" name="grund" required>
                            <option value="">Grund wählen …</option>
                            <?php foreach (Differenzgrund::cases() as $grund): ?>
                                <option value="<?= e($grund->value) ?>"><?= e($grund->label()) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <p class="knopfreihe"><button type="submit" class="knopf">Differenz abschließen</button></p>
                </form>
            <?php endif; ?>

            <?php if (!$detail->nimmtZuordnungAn): ?>
                <p class="hinweis hinweis-info">
                    Dieser Beleg nimmt keine weitere Zuordnung an – er ist ausgeglichen, noch nicht geprüft oder erwartet
                    keine Buchung (Zahlungsweg „<?= e($b->record->paymentKind->label()) ?>“).
                </p>
            <?php elseif ($offen > 0): ?>
                <h5>Passende Buchungen</h5>
                <?php if ($detail->kandidaten === []): ?>
                    <div class="leer">Keine offene Buchung in dieser Richtung<?= $filter->kontoId !== null || $filter->von !== null || $filter->bis !== null || $filter->vertraulich() ? ' für diese Filter' : '' ?>.</div>
                <?php else: ?>
                    <p class="gedaempft">
                        Nach Punkten sortiert<?= $detail->kandidatenGesamt > count($detail->kandidaten) ? ', die besten ' . e((string) count($detail->kandidaten)) . ' von ' . e((string) $detail->kandidatenGesamt) . ' – mit Betrag oder Suche oben eingrenzen' : '' ?>.
                        Der Betrag ist vorbelegt mit dem, was beide Seiten noch offen haben; für eine Teilzahlung kleiner eintragen.
                    </p>
                    <?php foreach ($detail->kandidaten as $k): ?>
                        <?php $tx = $k->buchung->buchung; ?>
                        <div class="karte">
                            <p>
                                <span class="marke"><?= e((string) ($k->bewertung->score ?? 0)) ?> Punkte</span>
                                <?php if ($k->verworfen): ?><span class="marke marke-warnung">verworfen</span><?php endif; ?>
                                <a href="/app/buchungen/<?= e((string) $tx->id) ?>"><?= $datum($tx->bookingDate) ?></a>,
                                <?= $betrag($tx->amount) ?><?= $k->buchung->offen !== abs($tx->amount) ? ' (noch ohne Beleg: ' . $betrag($k->buchung->offen) . ')' : '' ?>
                                <span class="gedaempft"><?= e($kontoName[$tx->accountId] ?? '') ?></span><br>
                                <?= e($tx->counterpartyName !== '' ? $tx->counterpartyName : 'Gegenseite unbekannt') ?><br>
                                <span class="gedaempft"><?= e($tx->purpose) ?></span>
                                <?php if ($k->bewertung !== null && $k->bewertung->begruendung() !== []): ?>
                                    <br><span class="klein gedaempft"><?= e(implode(', ', array_map(static fn(array $g): string => ($g['punkte'] > 0 ? '+' : '') . $g['punkte'] . ' ' . $g['label'], $k->bewertung->begruendung()))) ?></span>
                                <?php endif; ?>
                            </p>
                            <form method="post" action="/app/abgleich/zuordnen" class="knopfreihe">
                                <?= $verborgen() ?>
                                <input type="hidden" name="buchung" value="<?= e((string) $tx->id) ?>">
                                <label for="abgleich-betrag-<?= e((string) $tx->id) ?>" class="visuell-versteckt">Betrag (€)</label>
                                <input type="text" inputmode="decimal" class="feld-kurz" id="abgleich-betrag-<?= e((string) $tx->id) ?>" name="betrag" autocomplete="off" required value="<?= e(Betrag::format($k->betrag)) ?>">
                                <button type="submit" class="knopf">Zuordnen</button>
                            </form>
                            <?php if ($k->verworfen || ($k->bewertung !== null && $k->bewertung->score >= $parameter->vorschlagAb)): ?>
                                <form method="post" action="/app/abgleich/verwerfen" class="knopfreihe">
                                    <?= $verborgen() ?>
                                    <input type="hidden" name="buchung" value="<?= e((string) $tx->id) ?>">
                                    <?php if ($k->verworfen): ?>
                                        <input type="hidden" name="zuruecknehmen" value="1">
                                        <button type="submit" class="knopf knopf-still">Wieder vorschlagen</button>
                                    <?php else: ?>
                                        <button type="submit" class="knopf knopf-still">Als Vorschlag verwerfen</button>
                                    <?php endif; ?>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
            <p><a href="<?= e($filter->url(['beleg' => null])) ?>">Auswahl aufheben</a></p>
        </article>
    <?php endif; ?>

    <div class="abgleich-spalten">
        <div>
            <h4>Offene Belege (<?= e((string) $liste->belegeGesamt) ?>)</h4>
            <?php if ($liste->belege === []): ?>
                <div class="leer">Keine offenen Belege<?= $filter->von !== null || $filter->bis !== null || $filter->vertraulich() ? ' für diese Filter' : '' ?>.</div>
            <?php endif; ?>
            <?php foreach ($liste->belege as $b): ?>
                <div class="karte<?= $filter->belegId === $b->record->id ? ' abgleich-gewaehlt' : '' ?>">
                    <p>
                        <strong><?= e($b->lieferant !== '' ? $b->lieferant : 'Beleg ohne Lieferant') ?></strong>
                        <?php if ($b->record->paymentStatus->value === 'teilbezahlt'): ?><span class="marke">teilbezahlt</span><?php endif; ?><br>
                        <?= $b->daten->invoiceNumber !== '' ? 'Nr. ' . e($b->daten->invoiceNumber) . ', ' : '' ?><?= $datum($b->record->invoiceDate) ?>,
                        <?= $b->record->direction->value === 'einnahme' ? 'Einnahme' : 'Ausgabe' ?><br>
                        brutto <?= $betrag($b->daten->gross) ?><?= $b->offen !== $b->daten->gross ? ', offen ' . $betrag($b->offen) : '' ?>
                    </p>
                    <p class="knopfreihe">
                        <a class="knopf" href="<?= e($filter->url(['beleg' => $b->record->id])) ?>#beleg">Zuordnen …</a>
                    </p>
                </div>
            <?php endforeach; ?>
            <?= $blaettern('seite_belege', $filter->seiteBelege, $liste->belegeGesamt) ?>
        </div>
        <div>
            <h4>Buchungen ohne Beleg (<?= e((string) $liste->buchungenGesamt) ?>)</h4>
            <?php if ($liste->buchungen === []): ?>
                <div class="leer">Keine Buchungen ohne Beleg<?= $filter->kontoId !== null || $filter->von !== null || $filter->bis !== null || $filter->vertraulich() ? ' für diese Filter' : '' ?>.</div>
            <?php endif; ?>
            <?php foreach ($liste->buchungen as $u): ?>
                <?php $tx = $u->buchung; ?>
                <div class="karte">
                    <p>
                        <strong><?= e($tx->counterpartyName !== '' ? $tx->counterpartyName : 'Gegenseite unbekannt') ?></strong><br>
                        <?= $datum($tx->bookingDate) ?>, <?= $betrag($tx->amount) ?><?= $u->offen !== abs($tx->amount) ? ' (noch ohne Beleg: ' . $betrag($u->offen) . ')' : '' ?>
                        <span class="gedaempft"><?= e($kontoName[$tx->accountId] ?? '') ?></span><br>
                        <span class="gedaempft"><?= e($tx->purpose) ?></span>
                    </p>
                    <p class="knopfreihe">
                        <a class="knopf knopf-still" href="/app/buchungen/<?= e((string) $tx->id) ?>">Buchung öffnen</a>
                        <?php if ($tx->direction === BankTransactionDirection::Ausgabe && $tx->counterpartyIban !== ''): ?>
                            <a class="knopf knopf-still" href="/app/buchungen/<?= e((string) $tx->id) ?>/erstattung">Erstattung?</a>
                        <?php endif; ?>
                    </p>
                </div>
            <?php endforeach; ?>
            <?= $blaettern('seite_buchungen', $filter->seiteBuchungen, $liste->buchungenGesamt) ?>
            <p class="feld-hilfe">
                „Kein Beleg nötig“, Regeln und Umbuchungen zwischen eigenen Konten stehen an der Buchung.
            </p>
        </div>
    </div>
</section>
<?php endif; ?>

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
