<?php

/**
 * One export run (issue #179/M12-4, docs/spec/05-auswertung-und-export.md
 * section 2): download its ZIP - as often as needed - and confirm that it
 * was saved completely; only then do its receipts count as exported. A
 * confirmed run can be reset.
 *
 * Only components from /admin/designsystem, no page-specific CSS or
 * JavaScript. Everything shown is plaintext structure (dates, counts); the
 * download needs the unlocked vault, the confirmation does not.
 *
 * @var bool $entsperrt
 * @var \App\Domain\ExportRun $lauf
 * @var \App\Service\Export\ExportFilter $filter
 * @var string $dateiname the ZIP's name - the period only
 * @var string $csrf
 */

use App\Domain\ExportRunStatus;

$pfad = '/app/export/lauf/' . $lauf->id;
$statusMarke = match ($lauf->status) {
    ExportRunStatus::Bestaetigt => 'marke-ok',
    ExportRunStatus::Offen => 'marke-warnung',
    ExportRunStatus::Zurueckgesetzt => '',
};
?>
<section>
    <h2>Export</h2>

    <p><a href="/app/export">← Zum Export</a></p>

    <div class="karte">
        <h4><code><?= e($dateiname) ?></code> <span class="marke <?= e($statusMarke) ?>"><?= e($lauf->status->label()) ?></span></h4>
        <p>
            <strong><?= e((string) $lauf->belege) ?> Belege</strong>,
            <?= e((string) $lauf->dateien) ?> Dateien inkl. <code>index.csv</code> –
            Belegdatum <?= e($filter->von->format('d.m.Y')) ?> bis <?= e($filter->bis->format('d.m.Y')) ?>,
            <?= e($filter->status->bezeichnung()) ?><?= $filter->nurNeue ? ', nur noch nicht exportierte' : '' ?><?= $filter->originale ? ', mit Originalen' : '' ?>
        </p>
        <p class="gedaempft">
            Erstellt am <?= e($lauf->createdAt->format('d.m.Y \u\m H:i')) ?>
            <?php if ($lauf->confirmedAt !== null): ?>
                · als exportiert markiert am <?= e($lauf->confirmedAt->format('d.m.Y \u\m H:i')) ?>
            <?php endif; ?>
            <?php if ($lauf->resetAt !== null): ?>
                · zurückgesetzt am <?= e($lauf->resetAt->format('d.m.Y \u\m H:i')) ?>
            <?php endif; ?>
        </p>
    </div>

    <h3>1. Herunterladen</h3>
    <?php if (!$entsperrt): ?>
        <p class="hinweis hinweis-info">
            Belege sind verschlüsselt und nur mit entsperrtem Tresor lesbar.
            Melden Sie sich neu an, um sie herunterzuladen.
        </p>
    <?php else: ?>
        <form method="post" action="<?= e($pfad) ?>/zip" class="formular">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <p class="feld-hilfe">
                Große Exporte dauern einige Minuten. Bricht der Download ab, einfach erneut herunterladen –
                oder den Zeitraum verkleinern (z. B. je Quartal) und in Teilen exportieren.
            </p>
            <p class="knopfreihe">
                <button type="submit" class="knopf<?= $lauf->status === ExportRunStatus::Offen ? ' knopf-primaer' : '' ?>">ZIP herunterladen</button>
            </p>
        </form>
    <?php endif; ?>

    <h3>2. Als exportiert markieren</h3>
    <?php if ($lauf->status === ExportRunStatus::Offen): ?>
        <p>
            Öffnen Sie die ZIP-Datei und prüfen Sie, ob sie vollständig ist. Erst nach Ihrer Bestätigung gelten
            die <?= e((string) $lauf->belege) ?> Belege als exportiert – für alle im Verein. Die Option
            „Nur noch nicht exportierte“ lässt sie dann beim nächsten Export weg.
        </p>
        <form method="post" action="<?= e($pfad) ?>/bestaetigen" class="formular">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <p class="knopfreihe">
                <button type="submit" class="knopf knopf-primaer">Vollständig gespeichert – als exportiert markieren</button>
            </p>
        </form>
        <p class="gedaempft">Solange Sie nicht bestätigen, gilt kein Beleg dieses Exports als exportiert.</p>
    <?php elseif ($lauf->status === ExportRunStatus::Bestaetigt): ?>
        <p class="hinweis hinweis-ok">Die <?= e((string) $lauf->belege) ?> Belege dieses Exports gelten als exportiert.</p>
        <form method="post" action="<?= e($pfad) ?>/zuruecksetzen" class="formular">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <p class="feld-hilfe">
                War der Export doch nicht vollständig oder ging er nicht weiter? Dann die Markierung zurücksetzen –
                die Belege werden beim nächsten Export mit „Nur noch nicht exportierte“ wieder berücksichtigt.
            </p>
            <p class="knopfreihe">
                <button type="submit" class="knopf">Als nicht exportiert zurücksetzen</button>
            </p>
        </form>
    <?php else: ?>
        <p class="hinweis hinweis-info">
            Die Markierung dieses Exports ist zurückgesetzt; seine Belege gelten durch ihn nicht als exportiert.
            Für eine neue Markierung bitte einen neuen Export erstellen.
        </p>
    <?php endif; ?>
</section>
