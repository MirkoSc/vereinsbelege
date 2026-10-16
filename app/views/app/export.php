<?php

/**
 * The ZIP export (issue #76/M12-2, docs/spec/05-auswertung-und-export.md
 * section 2): the filters as a plain GET form, what the ZIP would hold for
 * them, and "Export erstellen" as a POST form carrying the same filter as
 * hidden fields plus the CSRF token - so the token never lands in a URL.
 * It creates an export run and leads to its page (app/export-lauf), where
 * the ZIP is downloaded and confirmed (issue #179/M12-4). Below, the latest
 * runs.
 *
 * Only components from /admin/designsystem, no page-specific CSS or
 * JavaScript. Supplier names need the vault; without it the page shows the
 * notice only.
 *
 * @var bool $entsperrt
 * @var \App\Service\Export\ExportFilter $filter
 * @var string|null $fehler what is wrong with the filter
 * @var \App\Service\Export\ExportPlan|null $plan null without vault or with $fehler
 * @var list<\App\Domain\Supplier> $lieferanten
 * @var list<\App\Domain\Category> $kategorien
 * @var list<\App\Domain\CostCenter> $kostenstellen
 * @var string $csrf
 * @var bool $darfMusterAendern holds `admin.settings`
 * @var list<\App\Domain\ExportRun> $laeufe newest first
 */

use App\Domain\ExportRunStatus;
use App\Service\Export\ExportFilter;
use App\Service\Export\ExportStatus;
use App\Service\Export\ZipExport;

$gewaehlt = static fn(bool $ja): string => $ja ? ' selected' : '';
$statusMarke = static fn(ExportRunStatus $s): string => match ($s) {
    ExportRunStatus::Bestaetigt => 'marke-ok',
    ExportRunStatus::Offen => 'marke-warnung',
    ExportRunStatus::Zurueckgesetzt => '',
};
$mb = static fn(int $bytes): string => number_format(max(0.1, $bytes / 1_048_576), 1, ',', '.') . ' MB';
?>
<section>
    <h2>Export</h2>

    <p class="gedaempft">
        Lädt die Belege eines Zeitraums als ZIP-Datei herunter – sortiert in Ordner nach
        dem Export-Muster, mit einer <code>index.csv</code> aller Belege (öffnet in Excel).
        Die Dateien werden beim Herunterladen entschlüsselt; auf dem Server entsteht keine Kopie.
        <?php if ($darfMusterAendern): ?>
            <a href="/admin/export">Ordner- und Dateinamen ändern</a>
        <?php endif; ?>
    </p>

    <?php if (!$entsperrt): ?>
        <p class="hinweis hinweis-info">
            Belege sind verschlüsselt und nur mit entsperrtem Tresor lesbar.
            Melden Sie sich neu an, um sie zu exportieren.
        </p>
    <?php else: ?>
        <form method="get" action="/app/export" class="formular">
            <label for="export-von" class="feld-kurz">Von
                <input type="date" id="export-von" name="von" value="<?= e($filter->von->format('Y-m-d')) ?>" required>
            </label>

            <label for="export-bis" class="feld-kurz">Bis
                <input type="date" id="export-bis" name="bis" value="<?= e($filter->bis->format('Y-m-d')) ?>" required>
            </label>
            <p class="feld-hilfe">Belegdatum. Ohne Angabe gilt das laufende Jahr.</p>

            <label for="export-status">Status
                <select id="export-status" name="status">
                    <?php foreach (ExportStatus::cases() as $status): ?>
                        <option value="<?= e($status->value) ?>"<?= $gewaehlt($filter->status === $status) ?>><?= e($status->bezeichnung()) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <p class="feld-hilfe">
                Noch nicht geprüfte Belege landen im Ordner <code><?= e(ZipExport::ORDNER_WIEDERVORLAGE) ?></code>.
                Abgelehnte Belege werden nie exportiert.
            </p>

            <label for="export-kategorie">Kategorie
                <select id="export-kategorie" name="kategorie">
                    <option value="">Alle</option>
                    <option value="<?= e(ExportFilter::OHNE) ?>"<?= $gewaehlt($filter->kategorieId === 0) ?>>ohne Kategorie</option>
                    <?php foreach ($kategorien as $kategorie): ?>
                        <option value="<?= e((string) $kategorie->id) ?>"<?= $gewaehlt($filter->kategorieId === $kategorie->id) ?>><?= e($kategorie->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label for="export-kostenstelle">Kostenstelle
                <select id="export-kostenstelle" name="kostenstelle">
                    <option value="">Alle</option>
                    <option value="<?= e(ExportFilter::OHNE) ?>"<?= $gewaehlt($filter->kostenstelleId === 0) ?>>ohne Kostenstelle</option>
                    <?php foreach ($kostenstellen as $kostenstelle): ?>
                        <option value="<?= e((string) $kostenstelle->id) ?>"<?= $gewaehlt($filter->kostenstelleId === $kostenstelle->id) ?>><?= e($kostenstelle->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label for="export-lieferant">Lieferant bzw. Zahler
                <select id="export-lieferant" name="lieferant">
                    <option value="">Alle</option>
                    <option value="<?= e(ExportFilter::OHNE) ?>"<?= $gewaehlt($filter->lieferantId === 0) ?>>ohne Lieferant</option>
                    <?php foreach ($lieferanten as $lieferant): ?>
                        <option value="<?= e((string) $lieferant->id) ?>"<?= $gewaehlt($filter->lieferantId === $lieferant->id) ?>><?= e($lieferant->data->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <fieldset>
                <legend>Optionen</legend>
                <label class="feld-ankreuz">
                    <input type="checkbox" name="originale" value="1"<?= $filter->originale ? ' checked' : '' ?>>
                    <span>Originale zusätzlich – Fotos und hochgeladene Dateien im Ordner <code><?= e(ZipExport::ORDNER_ORIGINALE) ?></code></span>
                </label>
                <label class="feld-ankreuz">
                    <input type="checkbox" name="nur_neue" value="1"<?= $filter->nurNeue ? ' checked' : '' ?>>
                    <span>Nur noch nicht exportierte – ohne Belege aus Exporten, die als vollständig gespeichert bestätigt sind</span>
                </label>
            </fieldset>

            <p class="knopfreihe">
                <button type="submit" class="knopf">Vorschau aktualisieren</button>
                <a class="knopf knopf-still" href="/app/export">Zurücksetzen</a>
            </p>
        </form>

        <?php if ($fehler !== null): ?>
            <p class="hinweis hinweis-fehler" role="alert"><?= e($fehler) ?></p>
        <?php elseif ($plan !== null): ?>
            <h3>Inhalt des Exports</h3>
            <?php if ($plan->anzahlBelege === 0): ?>
                <div class="leer">Für diese Auswahl gibt es keine Belege.</div>
            <?php else: ?>
                <div class="karte">
                    <h4><code><?= e($plan->dateiname()) ?></code></h4>
                    <p>
                        <strong><?= e((string) $plan->anzahlBelege) ?> Belege</strong>,
                        <?= e((string) $plan->anzahlDateien()) ?> Dateien inkl. <code>index.csv</code>,
                        etwa <?= e($mb($plan->groesse)) ?>
                    </p>
                </div>

                <?php if ($plan->fehlend > 0): ?>
                    <p class="hinweis hinweis-warnung">
                        <?= e((string) $plan->fehlend) ?> Datei(en) fehlen im Speicher und können nicht exportiert werden.
                        In der <code>index.csv</code> steht an ihrer Stelle „<?= e(ZipExport::DATEI_FEHLT) ?>“;
                        fehlende Originale fehlen nur im Ordner <code><?= e(ZipExport::ORDNER_ORIGINALE) ?></code>.
                    </p>
                <?php endif; ?>

                <?php if ($plan->zuGross()): ?>
                    <p class="hinweis hinweis-fehler" role="alert">
                        Der Export wäre zu groß für eine ZIP-Datei. Bitte den Zeitraum verkleinern, z. B. je Quartal.
                    </p>
                <?php else: ?>
                    <form method="post" action="/app/export/lauf" class="formular">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <?php foreach ($filter->alsFelder() as $name => $wert): ?>
                            <input type="hidden" name="<?= e($name) ?>" value="<?= e($wert) ?>">
                        <?php endforeach; ?>
                        <p class="feld-hilfe">
                            Im nächsten Schritt laden Sie die ZIP-Datei herunter und bestätigen danach,
                            dass sie vollständig gespeichert ist – erst dann gelten die Belege als exportiert.
                        </p>
                        <p class="knopfreihe">
                            <button type="submit" class="knopf knopf-primaer">Export erstellen</button>
                        </p>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>

    <h3>Letzte Exporte</h3>
    <?php if ($laeufe === []): ?>
        <div class="leer">Noch kein Export erstellt.</div>
    <?php else: ?>
        <div class="tabelle-rahmen">
            <table class="tabelle tabelle-karten">
                <caption>Die letzten <?= e((string) count($laeufe)) ?> Exporte</caption>
                <thead>
                    <tr>
                        <th scope="col">Erstellt</th>
                        <th scope="col">Zeitraum</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="zahl">Belege</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($laeufe as $lauf): ?>
                        <?php $laufFilter = ExportFilter::ausFeldern($lauf->filter, $lauf->createdAt); ?>
                        <tr>
                            <td class="tabelle-kurz" data-label="Erstellt"><a href="/app/export/lauf/<?= e((string) $lauf->id) ?>"><?= e($lauf->createdAt->format('d.m.Y H:i')) ?></a></td>
                            <td class="tabelle-kurz" data-label="Zeitraum"><?= e($laufFilter->von->format('d.m.Y')) ?> – <?= e($laufFilter->bis->format('d.m.Y')) ?></td>
                            <td class="tabelle-kurz" data-label="Status"><span class="marke <?= e($statusMarke($lauf->status)) ?>"><?= e($lauf->status->label()) ?></span></td>
                            <td class="zahl tabelle-kurz" data-label="Belege"><?= e((string) $lauf->belege) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
