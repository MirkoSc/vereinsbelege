<?php

/**
 * One export run (issue #179/M12-4, docs/spec/05-auswertung-und-export.md
 * section 2): download its ZIP - as often as needed - and confirm that it
 * was saved completely; only then do its receipts count as exported. A
 * confirmed run can be reset.
 *
 * Only components from /admin/designsystem, no page-specific CSS. The only
 * JavaScript is public/js/nextcloud.js, which drives a running Nextcloud
 * filing (issue #95) - token and counts come from data-* attributes on
 * #nextcloud-ablage (CSP: no inline script). Everything shown is plaintext
 * structure (dates, counts); download and filing need the unlocked vault,
 * the confirmation does not.
 *
 * @var bool $entsperrt
 * @var \App\Domain\ExportRun $lauf
 * @var \App\Service\Export\ExportFilter $filter
 * @var string $dateiname the ZIP's name - the period only
 * @var string $csrf
 * @var bool $darfMarkieren false for external roles (read only, issue #78)
 * @var array{eingerichtet: bool, stand: ?\App\Domain\ExportRunUpload, darfEinrichten: bool}|null $nextcloud
 *      null when this user may not file into the Nextcloud (issue #95)
 */

use App\Domain\ExportRunStatus;
use App\Domain\NextcloudAblageStatus;

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

    <?php if ($nextcloud !== null): ?>
        <?php $stand = $nextcloud['stand']; ?>
        <h3>Oder: in der Nextcloud ablegen</h3>
        <?php if (!$nextcloud['eingerichtet']): ?>
            <p class="gedaempft">
                Der Export kann auch direkt in die Vereins-Nextcloud gelegt werden. Dafür ist noch keine
                Nextcloud eingerichtet<?php if ($nextcloud['darfEinrichten']): ?> –
                    <a href="/admin/export/nextcloud">jetzt einrichten</a><?php else: ?>; das erledigt ein Admin<?php endif; ?>.
            </p>
        <?php elseif (!$entsperrt): ?>
            <p class="hinweis hinweis-info">
                Zum Ablegen in der Nextcloud muss Ihr Tresor entsperrt sein. Melden Sie sich neu an.
            </p>
        <?php else: ?>
            <div id="nextcloud-ablage" data-csrf="<?= e($csrf) ?>" data-lauf="<?= e((string) $lauf->id) ?>"
                 data-status="<?= e($stand?->status->value ?? '') ?>"
                 data-verarbeitet="<?= e((string) ($stand?->nextIndex ?? 0)) ?>" data-gesamt="<?= e((string) ($stand?->total ?? 0)) ?>">
                <p class="feld-hilfe">
                    Legt dieselben Ordner und Dateien wie im ZIP samt <code>index.csv</code> einzeln in den
                    eingerichteten Nextcloud-Ordner. Was dort schon liegt, wird übersprungen; eine andere Datei
                    mit gleichem Namen wird nie überschrieben – die neue bekommt dann „(2)“ angehängt.
                </p>
                <?php if ($stand !== null && $stand->status === NextcloudAblageStatus::Laeuft): ?>
                    <p id="nextcloud-stand" role="status" aria-live="polite">
                        Wird abgelegt: <?= e((string) min($stand->nextIndex, $stand->total)) ?> von <?= e((string) $stand->total) ?> Dateien.
                    </p>
                    <progress id="nextcloud-fortschritt" max="<?= e((string) max(1, $stand->total)) ?>" value="<?= e((string) $stand->nextIndex) ?>"></progress>
                    <p id="nextcloud-fehler" class="hinweis hinweis-fehler" role="alert" hidden></p>
                    <p class="knopfreihe">
                        <button type="button" id="nextcloud-weiter" class="knopf" hidden>Weiter ablegen</button>
                    </p>
                    <noscript><p class="hinweis hinweis-info">Das Ablegen braucht JavaScript.</p></noscript>
                <?php else: ?>
                    <?php if ($stand !== null): ?>
                        <p class="hinweis hinweis-ok">
                            Abgelegt am <?= e(($stand->finishedAt ?? $stand->updatedAt)->format('d.m.Y \u\m H:i')) ?>:
                            <?= e((string) $stand->hochgeladen) ?> Dateien hochgeladen,
                            <?= e((string) $stand->vorhanden) ?> waren schon vorhanden<?php if ($stand->umbenannt > 0): ?>,
                                <?= e((string) $stand->umbenannt) ?> mit Zusatz „(2)“ o. ä., weil eine andere Datei den Namen trug –
                                die <code>index.csv</code> nennt für diese den ursprünglichen Namen<?php endif; ?>.
                        </p>
                    <?php endif; ?>
                    <form method="post" action="<?= e($pfad) ?>/nextcloud" class="formular">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <p class="knopfreihe">
                            <button type="submit" class="knopf"><?= $stand === null ? 'In der Nextcloud ablegen' : 'Erneut ablegen' ?></button>
                        </p>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <h3>2. Als exportiert markieren</h3>
    <?php $darfMarkieren ??= true; ?>
    <?php if (!$darfMarkieren && $lauf->status !== ExportRunStatus::Zurueckgesetzt): ?>
        <p class="gedaempft">Als exportiert markiert der Verein selbst – Ihr Zugang ist nur lesend.</p>
    <?php endif; ?>
    <?php if ($lauf->status === ExportRunStatus::Offen && $darfMarkieren): ?>
        <p>
            Öffnen Sie die ZIP-Datei<?= $nextcloud !== null && $nextcloud['stand'] !== null ? ' bzw. den Ordner in der Nextcloud' : '' ?> und prüfen Sie, ob alles vollständig ist. Erst nach Ihrer Bestätigung gelten
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
    <?php elseif ($lauf->status === ExportRunStatus::Offen): ?>
        <p class="gedaempft">Kein Beleg dieses Exports gilt bisher als exportiert.</p>
    <?php elseif ($lauf->status === ExportRunStatus::Bestaetigt): ?>
        <p class="hinweis hinweis-ok">Die <?= e((string) $lauf->belege) ?> Belege dieses Exports gelten als exportiert.</p>
        <?php if ($darfMarkieren): ?>
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
        <?php endif; ?>
    <?php else: ?>
        <p class="hinweis hinweis-info">
            Die Markierung dieses Exports ist zurückgesetzt; seine Belege gelten durch ihn nicht als exportiert.
            Für eine neue Markierung bitte einen neuen Export erstellen.
        </p>
    <?php endif; ?>
</section>
