<?php

/**
 * One archive import (issue #77/M12-3, docs/spec/05-auswertung-und-
 * export.md section 3): the preview before anything is written, the
 * running step chain, or the result with the files that were not imported.
 *
 * Only components from /admin/designsystem; the tables turn into cards on
 * narrow screens (.tabelle-karten), so nothing scrolls sideways at 360 px.
 * The preview needs no JavaScript - changing the pattern is a GET form; the
 * running chain is driven by public/js/archivimport.js (CSP: no inline
 * script - token and counts come from data-* attributes on #archivimport).
 *
 * @var string $csrf
 * @var \App\Domain\ArchiveImportRecord $import
 * @var \App\Service\Archiv\ArchivVorschau|null $vorschau only while `vorschau`
 * @var list<array{pfad: string, grund: string}> $bericht files not imported
 * @var array<string, string> $platzhalter of the file name pattern
 * @var string|null $fehler
 */

use App\Domain\ArchiveImportStatus;

$status = $import->status;
$s = $import->stats;
$aktion = static fn(string $was): string => '/app/archiv-import/' . $import->id . '/' . $was;
$lieferantMarke = static fn(?string $wie): string => match ($wie) {
    'vorhanden' => '<span class="marke marke-ok">vorhanden</span>',
    'neu' => '<span class="marke">wird angelegt</span>',
    'mehrdeutig' => '<span class="marke marke-warnung">mehrdeutig</span>',
    default => '',
};
?>
<section>
    <h2>Archiv-Import</h2>

    <p><a href="/app/archiv-import">← Alle Importe</a></p>

    <?php if ($fehler !== null): ?>
        <p class="hinweis hinweis-fehler" role="alert"><?= e($fehler) ?></p>
    <?php endif; ?>

    <p>Hochgeladen am <?= e($import->createdAt->format('d.m.Y H:i')) ?> · <?= e((string) $import->total) ?> Einträge im ZIP · Status: <strong><?= e($status->label()) ?></strong></p>

    <?php if ($vorschau !== null && $vorschau->fehler !== null): ?>
        <p class="hinweis hinweis-fehler" role="alert"><?= e($vorschau->fehler) ?></p>
    <?php elseif ($vorschau !== null): ?>
        <?php $z = $vorschau->zaehler; ?>
        <h3>Dateinamen-Muster</h3>
        <form method="get" action="/app/archiv-import/<?= e((string) $import->id) ?>" class="formular">
            <label for="archivimport-muster">Muster der Dateinamen
                <input type="text" id="archivimport-muster" name="muster" value="<?= e($vorschau->muster) ?>" maxlength="300"
                       aria-describedby="archivimport-muster-hilfe"<?= $vorschau->musterFehler !== null ? ' aria-invalid="true"' : '' ?>>
            </label>
            <p class="feld-hilfe" id="archivimport-muster-hilfe">
                Platzhalter:
                <?php foreach ($platzhalter as $name => $text): ?>
                    <code>{<?= e($name) ?>}</code> <?= e($text) ?><?= $name === array_key_last($platzhalter) ? '.' : ';' ?>
                <?php endforeach; ?>
                Endung und ein Zusatz wie „ (2)“ zählen nicht. Ordner werden für sich ausgewertet: „Kasse“, „Wiedervorlage“,
                Jahres- und Monatsordner (nur zur Kontrolle des Datums) und Ordner mit dem Namen einer Kategorie.
            </p>
            <?php if ($vorschau->musterFehler !== null): ?>
                <p class="hinweis hinweis-fehler" role="alert"><?= e($vorschau->musterFehler) ?></p>
            <?php endif; ?>
            <p class="knopfreihe"><button type="submit" class="knopf">Vorschau aktualisieren</button></p>
        </form>

        <?php if ($vorschau->musterFehler === null): ?>
            <h3>Was der Import tut</h3>
            <div class="tabelle-rahmen">
                <table class="tabelle tabelle-karten">
                    <caption>Vorschau – noch ist nichts gespeichert</caption>
                    <tbody>
                        <tr><th scope="row">Dateien, die Belege werden</th><td class="zahl"><?= e((string) $z['dateien']) ?></td></tr>
                        <tr><th scope="row">davon ohne passenden Dateinamen (nichts vorbelegt)</th><td class="zahl"><?= e((string) $z['ohne_vorbelegung']) ?></td></tr>
                        <tr><th scope="row">davon aus einem Ordner „Kasse“</th><td class="zahl"><?= e((string) $z['kasse']) ?></td></tr>
                        <tr><th scope="row">davon in die Wiedervorlage</th><td class="zahl"><?= e((string) $z['wiedervorlage']) ?></td></tr>
                        <tr><th scope="row">neue Lieferanten bzw. Zahler</th><td class="zahl"><?= e((string) $z['lieferanten_neu']) ?></td></tr>
                        <?php if ($z['lieferanten_mehrdeutig'] > 0): ?>
                            <tr><th scope="row">mehrdeutige Namen (werden nicht zugeordnet)</th><td class="zahl"><span class="marke marke-warnung"><?= e((string) $z['lieferanten_mehrdeutig']) ?></span></td></tr>
                        <?php endif; ?>
                        <tr><th scope="row">übersprungen (Kopien, unlesbare Einträge)</th><td class="zahl"><?= e((string) $z['uebersprungen']) ?></td></tr>
                        <tr><th scope="row">Systemdateien und Ordner</th><td class="zahl"><?= e((string) ($z['systemdateien'] + $z['ordner'])) ?></td></tr>
                    </tbody>
                </table>
            </div>
            <p class="feld-hilfe">
                Ob eine Datei wirklich ein PDF oder Bild ist, zeigt erst der Import – andere Dateien landen dann im Bericht.
                Beträge und Kategorien liest wie gewohnt die Auswertung; die Werte aus dem Archiv haben dabei Vorrang.
            </p>

            <?php if ($vorschau->zeilen !== []): ?>
                <details open>
                    <summary>Die ersten <?= e((string) count($vorschau->zeilen)) ?> Dateien</summary>
                    <div class="tabelle-rahmen">
                        <table class="tabelle tabelle-karten">
                            <caption>Was aus Pfad und Dateiname gelesen wird</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Datei</th>
                                    <th scope="col">Lieferant</th>
                                    <th scope="col">Datum</th>
                                    <th scope="col">Weiteres</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($vorschau->zeilen as $zeile): ?>
                                    <?php $v = $zeile->vorbelegung; ?>
                                    <tr>
                                        <td data-label="Datei"><code><?= e($v->pfad) ?></code></td>
                                        <?php if ($v->ueberspringen !== null): ?>
                                            <td data-label="Lieferant" colspan="3"><span class="marke">übersprungen</span> <?= e($v->ueberspringen) ?></td>
                                        <?php else: ?>
                                            <td data-label="Lieferant"><?= $v->lieferant === null ? '<span class="gedaempft">–</span>' : e($v->lieferant) . ' ' . $lieferantMarke($zeile->lieferantStatus) ?></td>
                                            <td class="tabelle-kurz" data-label="Datum"><?= $v->datum === null ? '<span class="gedaempft">–</span>' : e($v->datum->format('d.m.Y')) ?></td>
                                            <td data-label="Weiteres">
                                                <?php if ($v->kasse): ?><span class="marke">Kasse</span><?php endif; ?>
                                                <?php if ($v->wiedervorlage): ?><span class="marke marke-warnung">Wiedervorlage</span><?php endif; ?>
                                                <?php if ($zeile->kategorie !== null): ?><span class="marke"><?= e($zeile->kategorie) ?></span><?php endif; ?>
                                                <?php if ($v->richtungAusOrdner): ?><span class="marke"><?= e($v->richtung->label()) ?></span><?php endif; ?>
                                                <?php foreach ($v->hinweise as $hinweis): ?>
                                                    <br><span class="gedaempft"><?= e($hinweis) ?></span>
                                                <?php endforeach; ?>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>
            <?php endif; ?>

            <div class="knopfreihe">
                <?php if ($z['dateien'] > 0): ?>
                    <form method="post" action="<?= e($aktion('starten')) ?>">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="muster" value="<?= e($vorschau->muster) ?>">
                        <button type="submit" class="knopf knopf-primaer">
                            Import starten (<?= e((string) $z['dateien']) ?> <?= $z['dateien'] === 1 ? 'Datei' : 'Dateien' ?>)
                        </button>
                    </form>
                <?php endif; ?>
                <form method="post" action="<?= e($aktion('verwerfen')) ?>">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <button type="submit" class="knopf knopf-still">Verwerfen</button>
                </form>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($status === ArchiveImportStatus::Laeuft): ?>
        <div id="archivimport" data-csrf="<?= e($csrf) ?>" data-id="<?= e((string) $import->id) ?>"
             data-verarbeitet="<?= e((string) $import->nextIndex) ?>" data-gesamt="<?= e((string) $import->total) ?>">
            <p class="hinweis hinweis-info" id="archivimport-stand" aria-live="polite">
                Das Archiv wird übernommen: <?= e((string) $import->nextIndex) ?> von <?= e((string) $import->total) ?> Einträgen.
            </p>
            <progress id="archivimport-fortschritt" max="<?= e((string) max(1, $import->total)) ?>" value="<?= e((string) $import->nextIndex) ?>"></progress>
            <noscript>
                <p class="hinweis hinweis-warnung">Das Übernehmen braucht JavaScript. Bitte aktivieren und die Seite neu laden.</p>
            </noscript>
            <p class="hinweis hinweis-fehler" id="archivimport-fehler" role="alert" hidden></p>
            <p class="feld-hilfe">Die Seite bitte offen lassen. Wird sie geschlossen, macht „Fortsetzen“ später an derselben Stelle weiter.</p>
            <div class="knopfreihe">
                <button type="button" class="knopf" id="archivimport-weiter" hidden>Fortsetzen</button>
                <form method="post" action="<?= e($aktion('verwerfen')) ?>">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <button type="submit" class="knopf knopf-still">Abbrechen</button>
                </form>
            </div>
        </div>
    <?php elseif ($status === ArchiveImportStatus::Fertig || $status === ArchiveImportStatus::Abgebrochen): ?>
        <p class="hinweis <?= $status === ArchiveImportStatus::Fertig ? 'hinweis-ok' : 'hinweis-warnung' ?>">
            <?= $status === ArchiveImportStatus::Fertig ? 'Übernommen' : 'Abgebrochen' ?>:
            <?= e((string) $s->belege) ?> <?= $s->belege === 1 ? 'Beleg' : 'Belege' ?>,
            <?= e((string) $s->lieferantenNeu) ?> neue Lieferanten bzw. Zahler.
            <?php if ($status === ArchiveImportStatus::Abgebrochen && $s->belege > 0): ?>
                Die bereits übernommenen Belege bleiben erhalten.
            <?php endif; ?>
        </p>
        <?php if ($s->belege > 0): ?>
            <p><a href="/app/belege/pruefen">Zur Prüfliste</a> – Text und Beträge werden im Hintergrund gelesen, solange Sie angemeldet sind.</p>
        <?php endif; ?>
        <div class="tabelle-rahmen">
            <table class="tabelle tabelle-karten">
                <caption>Ergebnis</caption>
                <tbody>
                    <tr><th scope="row">Belege</th><td class="zahl"><?= e((string) $s->belege) ?></td></tr>
                    <tr><th scope="row">davon in der Wiedervorlage</th><td class="zahl"><?= e((string) $s->wiedervorlage) ?></td></tr>
                    <tr><th scope="row">davon aus einem Ordner „Kasse“</th><td class="zahl"><?= e((string) $s->kasse) ?></td></tr>
                    <tr><th scope="row">davon ohne passenden Dateinamen</th><td class="zahl"><?= e((string) $s->ohneVorbelegung) ?></td></tr>
                    <tr><th scope="row">neue Lieferanten bzw. Zahler</th><td class="zahl"><?= e((string) $s->lieferantenNeu) ?></td></tr>
                    <tr><th scope="row">nicht übernommen</th><td class="zahl"><?= e((string) $s->uebersprungen) ?></td></tr>
                    <tr><th scope="row">Systemdateien und Ordner</th><td class="zahl"><?= e((string) ($s->systemdateien + $s->ordner)) ?></td></tr>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($bericht !== []): ?>
        <details>
            <summary><?= e((string) count($bericht)) ?> <?= count($bericht) === 1 ? 'Datei' : 'Dateien' ?> nicht übernommen</summary>
            <div class="tabelle-rahmen">
                <table class="tabelle tabelle-karten">
                    <caption>Nicht übernommene Dateien</caption>
                    <thead>
                        <tr><th scope="col">Datei</th><th scope="col">Grund</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bericht as $zeile): ?>
                            <tr>
                                <td data-label="Datei"><code><?= e($zeile['pfad']) ?></code></td>
                                <td data-label="Grund"><?= e($zeile['grund']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
    <?php endif; ?>
</section>
