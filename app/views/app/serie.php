<?php

/**
 * One recurring series (M8-1, issue #57, docs/spec/03-erfassung-und-ki.md
 * section 9): confirm a suggestion with its rhythm, expected amount, next
 * date and receipts - or change a confirmed series, or dismiss either.
 *
 * Only components from /admin/designsystem, no JavaScript: the receipts
 * are checkboxes (ticked = in the series), the supplier's other checked
 * receipts in no series are offered unticked below them.
 *
 * @var string $csrf
 * @var \App\Service\Invoice\SerienAnsicht $serie
 * @var array<string, string> $felder
 * @var list<int> $belege ticked invoice ids
 * @var string|null $fehler
 * @var string|null $fehlerFeld
 * @var list<\App\Domain\SeriesPeriod> $perioden
 */

use App\Domain\SeriesStatus;
use App\Service\Invoice\Serien;
use App\Service\Processing\Betrag;

$record = $serie->record;
$vorschlag = $record->status === SeriesStatus::Vorgeschlagen;
$verworfen = $record->status === SeriesStatus::Verworfen;
$id = (string) $record->id;
$markiert = static fn(string $feld): string => $fehlerFeld === $feld ? ' aria-invalid="true" aria-describedby="serie-fehler"' : '';

$posten = static function (array $liste, string $legende, string $leer) use ($belege, $verworfen): void {
    ?>
    <fieldset>
        <legend><?= e($legende) ?></legend>
        <?php if ($liste === []): ?>
            <p class="gedaempft"><?= e($leer) ?></p>
        <?php endif; ?>
        <?php foreach ($liste as $p): ?>
            <label class="feld-ankreuz">
                <input type="checkbox" name="belege[]" value="<?= e((string) $p->invoiceId) ?>"<?= in_array($p->invoiceId, $belege, true) ? ' checked' : '' ?><?= $verworfen ? ' disabled' : '' ?>>
                <?= e($p->datum->format('d.m.Y')) ?> – <?= e(Betrag::format($p->brutto) . ' ' . $p->waehrung) ?>
                <?php if (!$p->geprueft): ?><span class="marke marke-warnung">nicht mehr geprüft</span><?php endif; ?>
                · <a href="/app/belege/pruefen/<?= e((string) $p->documentId) ?>">Beleg öffnen</a>
            </label>
        <?php endforeach; ?>
    </fieldset>
    <?php
};

?>
<section>
    <h2><?= $vorschlag ? 'Serien-Vorschlag prüfen' : 'Wiederkehrende Rechnung' ?></h2>

    <p><a href="/app/serien">← Alle wiederkehrenden Rechnungen</a></p>

    <p>
        <strong><?= e($serie->lieferant) ?></strong> · <?= e($record->direction->label()) ?>
        <span class="marke <?= $vorschlag ? 'marke-warnung' : ($verworfen ? 'marke-fehler' : 'marke-ok') ?>"><?= e($record->status->label()) ?></span>
    </p>

    <?php if ($vorschlag): ?>
        <p class="hinweis hinweis-info">
            Diese Serie wurde aus <?= e((string) $serie->anzahl) ?> geprüften Belegen erkannt. Bitte Rhythmus, Betrag und
            nächste Fälligkeit prüfen und Belege abwählen, die nicht dazugehören – dann bestätigen. Ist es keine
            Serie, verwerfen: diese Belege werden dann nicht noch einmal vorgeschlagen.
        </p>
    <?php elseif ($verworfen): ?>
        <p class="hinweis hinweis-info">Diese Serie wurde verworfen. Ihre Belege werden nicht noch einmal vorgeschlagen.</p>
    <?php endif; ?>

    <?php if ($fehler !== null): ?>
        <p class="hinweis hinweis-fehler" role="alert" id="serie-fehler"><?= e($fehler) ?></p>
    <?php endif; ?>

    <form method="post" action="/app/serien/<?= e($id) ?>" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <fieldset<?= $verworfen ? ' disabled' : '' ?>>
            <legend>Serie</legend>

            <label for="serie-bezeichnung">Bezeichnung
                <input type="text" id="serie-bezeichnung" name="bezeichnung" value="<?= e($felder['bezeichnung']) ?>"
                       maxlength="<?= e((string) Serien::BEZEICHNUNG_MAX) ?>" aria-describedby="serie-bezeichnung-hilfe"<?= $markiert('bezeichnung') ?>>
            </label>
            <p class="feld-hilfe" id="serie-bezeichnung-hilfe">Zum Beispiel „Hallenmiete“. Leer: der Name des Lieferanten.</p>

            <label for="serie-periode">Rhythmus <span class="pflicht" aria-hidden="true">*</span>
                <select id="serie-periode" name="periode" required<?= $markiert('periode') ?>>
                    <?php foreach ($perioden as $periode): ?>
                        <option value="<?= e($periode->value) ?>"<?= $felder['periode'] === $periode->value ? ' selected' : '' ?>><?= e($periode->label()) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label for="serie-betrag">Erwarteter Betrag (<?= e($serie->daten->currency) ?>) <span class="pflicht" aria-hidden="true">*</span>
                <input type="text" id="serie-betrag" name="betrag" value="<?= e($felder['betrag']) ?>" inputmode="decimal"
                       required aria-describedby="serie-betrag-hilfe"<?= $markiert('betrag') ?>>
            </label>
            <p class="feld-hilfe" id="serie-betrag-hilfe">Vorbelegt mit dem Betrag des neuesten Belegs.</p>

            <label for="serie-naechste">Nächste Fälligkeit
                <input type="date" id="serie-naechste" name="naechste" value="<?= e($felder['naechste']) ?>"
                       aria-describedby="serie-naechste-hilfe"<?= $markiert('naechste') ?>>
            </label>
            <p class="feld-hilfe" id="serie-naechste-hilfe">
                Wann der nächste Beleg erwartet wird – vorgeschlagen: neuester Beleg plus Rhythmus. Leer nur bei „unregelmäßig“.
            </p>

            <label for="serie-toleranz">Toleranz in Tagen <span class="pflicht" aria-hidden="true">*</span>
                <input type="number" id="serie-toleranz" name="toleranz" value="<?= e($felder['toleranz']) ?>" min="0"
                       max="<?= e((string) Serien::TOLERANZ_TAGE_MAX) ?>" required aria-describedby="serie-toleranz-hilfe"<?= $markiert('toleranz') ?>>
            </label>
            <p class="feld-hilfe" id="serie-toleranz-hilfe">So viele Tage darf der nächste Beleg später kommen, bevor er als fehlend gilt.</p>

            <label for="serie-vertrag">Vertrags- oder Kundennummer
                <input type="text" id="serie-vertrag" name="vertrag" value="<?= e($felder['vertrag']) ?>"
                       maxlength="<?= e((string) Serien::VERTRAG_MAX) ?>"<?= $markiert('vertrag') ?>>
            </label>
        </fieldset>

        <?php $posten($serie->mitglieder, 'Belege der Serie', 'Noch keine Belege.'); ?>
        <?php if (!$verworfen): ?>
            <?php $posten($serie->weitere, 'Weitere geprüfte Belege dieses Partners', 'Keine weiteren Belege ohne Serie.'); ?>
        <?php endif; ?>

        <?php if (!$verworfen): ?>
            <p class="knopfreihe">
                <button type="submit" class="knopf knopf-primaer"><?= $vorschlag ? 'Serie bestätigen' : 'Speichern' ?></button>
            </p>
        <?php endif; ?>
    </form>

    <?php if (!$verworfen): ?>
        <form method="post" action="/app/serien/<?= e($id) ?>/verwerfen" class="knopfreihe">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit" class="knopf knopf-gefahr"><?= $vorschlag ? 'Vorschlag verwerfen' : 'Serie verwerfen' ?></button>
        </form>
    <?php endif; ?>
</section>
