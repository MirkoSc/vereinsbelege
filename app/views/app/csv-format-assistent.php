<?php

/**
 * The CSV mapping assistant (M9-3, issue #61, docs/spec/
 * 04-bank-und-abgleich.md section 3): "Datei hochladen → Spalten zuordnen
 * mit Live-Vorschau der ersten 10 Zeilen → als Profil speichern".
 *
 * One form. Every change sends it - file included - to the preview route
 * via htmx (hx-trigger="change"), which answers with the mapping fragment
 * (app/views/app/csv-format-zuordnung.php) swapped into #csv-zuordnung.
 * The submit button posts the same form the ordinary way to save. No
 * JavaScript of ours, no hx-on, no inline script (CLAUDE.md section 4).
 *
 * The htmx attributes sit on a div inside the form, which includes the
 * form (hx-include), not on the form itself: htmx validates a form before
 * every request, so with the required name still empty, choosing the file
 * would silently send nothing. The browser's own check stays for saving.
 *
 * The file is read in memory on each request and never stored; after a
 * failed save the browser has dropped it, so the page asks for it again.
 *
 * @var string $csrf
 * @var \App\Service\Bank\Csv\CsvProfil|null $bestehend the club profile being edited
 * @var string $name
 * @var array{text: string, feld: string}|null $speicherFehler
 * @var bool $datei
 */

use App\App\CsvFormatController;
use App\Service\Bank\Csv\CsvProfil;

$ziel = $bestehend === null ? '/app/konten/csv-formate' : '/app/konten/csv-formate/' . $bestehend->id;
$fehlerAn = static fn(string $feld): string => ($speicherFehler['feld'] ?? null) === $feld ? ' aria-invalid="true" aria-describedby="csv-fehler"' : '';
?>
<section class="schmal">
    <h2><?= e($bestehend === null ? 'Neues CSV-Format' : 'CSV-Format „' . $bestehend->name . '“ bearbeiten') ?></h2>

    <p><a href="<?= e($bestehend === null ? '/app/konten/csv-formate' : $ziel) ?>">← <?= $bestehend === null ? 'Alle CSV-Formate' : 'Zurück zum Format' ?></a></p>

    <p class="gedaempft">
        Für Banken, deren CSV-Export noch kein Format kennt. Die Beispieldatei wird nur gelesen,
        um die Spalten zu zeigen – gespeichert werden allein die Spaltennamen und Formate, keine Buchungen.
    </p>

    <?php if ($speicherFehler !== null): ?>
        <p class="hinweis hinweis-fehler" role="alert" id="csv-fehler">
            <?= e($speicherFehler['text']) ?>
            <?php if ($datei): ?>
                Bitte die Datei zum Speichern erneut wählen.
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <form method="post" action="<?= e($ziel) ?>" enctype="multipart/form-data" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div hx-post="/app/konten/csv-formate/vorschau" hx-trigger="change" hx-include="closest form"
             hx-target="#csv-zuordnung" hx-swap="innerHTML" hx-encoding="multipart/form-data" hx-indicator="#csv-laedt">
            <?php if ($bestehend !== null): ?>
                <input type="hidden" name="id" value="<?= e((string) $bestehend->id) ?>">
            <?php endif; ?>

            <label for="csv-name">Name des Formats <span class="pflicht" aria-hidden="true">*</span>
                <input type="text" id="csv-name" name="name" value="<?= e($name) ?>"
                       maxlength="<?= e((string) CsvProfil::NAME_MAX) ?>" required<?= $fehlerAn('name') ?>>
            </label>
            <p class="feld-hilfe">Zum Beispiel „Volksbank Musterstadt CSV“.</p>

            <label for="csv-datei">Beispieldatei (CSV-Export der Bank)<?= $bestehend === null ? ' <span class="pflicht" aria-hidden="true">*</span>' : '' ?>
                <input type="file" id="csv-datei" name="datei" accept=".csv,.txt,text/csv,text/plain"<?= $bestehend === null ? ' required' : '' ?><?= $fehlerAn('datei') ?>>
            </label>
            <p class="feld-hilfe">
                <?= $bestehend === null
                    ? 'Höchstens ' . e((string) (CsvFormatController::MAX_BYTES / 1024 / 1024)) . ' MB. Ein Export über wenige Wochen genügt.'
                    : 'Optional: mit einem aktuellen Export prüfen, ob die Zuordnung noch passt.' ?>
                <span id="csv-laedt" class="htmx-indicator lade-anzeige">Datei wird gelesen …</span>
            </p>

            <div id="csv-zuordnung" aria-live="polite">
                <?php require __DIR__ . '/csv-format-zuordnung.php'; ?>
            </div>
        </div>

        <p class="knopfreihe">
            <button type="submit" class="knopf knopf-primaer">Format speichern</button>
            <a class="knopf" href="<?= e($bestehend === null ? '/app/konten/csv-formate' : $ziel) ?>">Abbrechen</a>
        </p>
    </form>
</section>
