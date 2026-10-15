<?php

/**
 * Archive import, start page (issue #77/M12-3, docs/spec/
 * 05-auswertung-und-export.md section 3): upload the ZIP, and the latest
 * imports with their counts.
 *
 * Only components from /admin/designsystem; the history turns into cards on
 * narrow screens (.tabelle-karten). The ZIP is too large for one request,
 * so it goes through the chunk upload: public/js/upload.js cuts it,
 * public/js/archivimport.js wires the form (CSP: no inline script - the
 * CSRF token and the limit come from data-* attributes on #archivimport-upload).
 *
 * @var string $csrf
 * @var bool $entsperrt
 * @var list<\App\Domain\ArchiveImportRecord> $importe newest first
 * @var int $maxBytes
 */

use App\Domain\ArchiveImportStatus;

$statusMarke = static fn(ArchiveImportStatus $s): string => match ($s) {
    ArchiveImportStatus::Fertig => 'marke-ok',
    ArchiveImportStatus::Laeuft => 'marke-warnung',
    ArchiveImportStatus::Abgebrochen => 'marke-fehler',
    ArchiveImportStatus::Vorschau => '',
};
?>
<section>
    <h2>Archiv importieren</h2>

    <p><a href="/app/posteingang">← Zum Posteingang</a></p>

    <p class="gedaempft">
        Übernehmen Sie Ihr bisheriges Belegarchiv auf einmal: Packen Sie den Jahresordner als ZIP und laden Sie ihn hoch.
        Aus jeder PDF- oder Bilddatei wird ein Beleg in der Prüfliste. Aus Dateinamen wie
        „Stadtwerke Musterstadt 15.01.2026.pdf“ werden Lieferant und Datum vorbelegt; ein Ordner „Kasse“ wird als
        bar bezahlt vermerkt, Dateien aus einem Ordner „Wiedervorlage“ kommen in die Wiedervorlage.
        Bevor etwas gespeichert wird, zeigt eine Vorschau, was der Import tut.
    </p>

    <?php if (!$entsperrt): ?>
        <p class="hinweis hinweis-info">
            Belege sind verschlüsselt und nur mit entsperrtem Tresor lesbar.
            Melden Sie sich neu an, um ein Archiv zu importieren.
        </p>
    <?php else: ?>
        <form class="formular" id="archivimport-upload" data-csrf="<?= e($csrf) ?>" data-max="<?= e((string) $maxBytes) ?>">
            <label for="archivimport-datei">ZIP-Datei <span class="pflicht" aria-hidden="true">*</span>
                <input type="file" id="archivimport-datei" name="datei" required accept=".zip,application/zip" aria-describedby="archivimport-hilfe">
            </label>
            <p class="feld-hilfe" id="archivimport-hilfe">
                Höchstens <?= e((string) intdiv($maxBytes, 1024 * 1024 * 1024)) ?> GB. Große Archive am besten je Jahr hochladen.
            </p>

            <p class="hinweis hinweis-info" id="archivimport-stand" aria-live="polite" hidden></p>
            <p class="hinweis hinweis-fehler" id="archivimport-fehler" role="alert" hidden></p>
            <noscript>
                <p class="hinweis hinweis-warnung">Das Hochladen braucht JavaScript. Bitte aktivieren und die Seite neu laden.</p>
            </noscript>

            <p class="knopfreihe">
                <button type="submit" class="knopf knopf-primaer" id="archivimport-senden">Hochladen und prüfen</button>
            </p>
        </form>

        <h3>Letzte Importe</h3>
        <?php if ($importe === []): ?>
            <div class="leer">Noch kein Archiv importiert.</div>
        <?php else: ?>
            <div class="tabelle-rahmen">
                <table class="tabelle tabelle-karten">
                    <caption>Die letzten <?= e((string) count($importe)) ?> Importe</caption>
                    <thead>
                        <tr>
                            <th scope="col">Hochgeladen</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="zahl">Einträge</th>
                            <th scope="col" class="zahl">Belege</th>
                            <th scope="col" class="zahl">Übersprungen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($importe as $import): ?>
                            <tr>
                                <td class="tabelle-kurz" data-label="Hochgeladen"><a href="/app/archiv-import/<?= e((string) $import->id) ?>"><?= e($import->createdAt->format('d.m.Y H:i')) ?></a></td>
                                <td class="tabelle-kurz" data-label="Status"><span class="marke <?= e($statusMarke($import->status)) ?>"><?= e($import->status->label()) ?></span></td>
                                <td class="zahl tabelle-kurz" data-label="Einträge"><?= e((string) $import->total) ?></td>
                                <td class="zahl tabelle-kurz" data-label="Belege"><?= e((string) $import->stats->belege) ?></td>
                                <td class="zahl tabelle-kurz" data-label="Übersprungen"><?= e((string) $import->stats->uebersprungen) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
