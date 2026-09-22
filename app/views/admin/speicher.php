<?php

/**
 * Storage admin page (M2-5, issue #12): where the encrypted files lie,
 * switching the backend and the integrity check.
 *
 * Only components from /admin/designsystem, no page-specific CSS. The
 * progress is filled in by public/js/speicher.js - never by an inline
 * script (CLAUDE.md section 4).
 *
 * @var string $csrf
 * @var \App\Service\Storage\StorageState $state
 * @var list<\App\Domain\BlobStorage> $backends
 */

$dateien = static fn(int $anzahl): string => $anzahl === 1
    ? '1 Datei'
    : number_format($anzahl, 0, ',', '.') . ' Dateien';

$groesse = static function (int $bytes): string {
    $einheiten = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $wert = (float) $bytes;
    while ($wert >= 1024.0 && $i < count($einheiten) - 1) {
        $wert /= 1024.0;
        $i++;
    }

    return number_format($wert, $i === 0 ? 0 : 1, ',', '.') . ' ' . $einheiten[$i];
};
?>
<section class="schmal">
    <h2>Speicher</h2>

    <p class="gedaempft">
        Belegbilder, PDFs und Kontoauszugsdateien liegen immer verschlüsselt –
        das Backend bestimmt nur, wo. Umgestellt wird in kleinen Schritten,
        während diese Seite offen ist; abbrechen und später fortsetzen ist
        jederzeit möglich.
    </p>

    <p class="knopfreihe">
        Aktuelles Backend:
        <span class="marke marke-ok"><?= e($state->ziel->bezeichnung()) ?></span>
    </p>

    <div class="tabelle-rahmen">
        <table class="tabelle">
            <caption>Bestand je Backend</caption>
            <thead>
                <tr>
                    <th scope="col">Backend</th>
                    <th scope="col" class="zahl">Dateien</th>
                    <th scope="col" class="zahl">Größe</th>
                    <th scope="col" class="zahl">Unfertig</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($backends as $backend): ?>
                    <?php $zeile = $state->bestandFuer($backend); ?>
                    <tr>
                        <td>
                            <?= e($backend->bezeichnung()) ?>
                            <?php if ($backend === $state->ziel): ?>
                                <span class="marke marke-ok">Ziel</span>
                            <?php endif; ?>
                        </td>
                        <td class="zahl"><?= e(number_format($zeile['anzahl'], 0, ',', '.')) ?></td>
                        <td class="zahl"><?= e($groesse($zeile['bytes'])) ?></td>
                        <td class="zahl"><?= e(number_format($zeile['entwuerfe'], 0, ',', '.')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($state->entwuerfe > 0): ?>
        <div class="hinweis hinweis-info">
            <p>
                <?= e($dateien($state->entwuerfe)) ?> aus abgebrochenen Uploads
                <?= $state->entwuerfe === 1 ? 'wird' : 'werden' ?> nicht verschoben:
                ohne Prüfsumme lässt sich ein Umzug nicht prüfen. Der Inhalt bleibt
                unverändert liegen, wo er ist; lesbar war er nie.
            </p>
        </div>
    <?php endif; ?>

    <h3>Backend wechseln</h3>
    <form method="post" action="/admin/speicher/ziel" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <fieldset>
            <legend>Neue Dateien speichern in</legend>
            <?php foreach ($backends as $backend): ?>
                <label class="feld-ankreuz">
                    <input
                        type="radio"
                        name="ziel"
                        value="<?= e($backend->value) ?>"
                        <?= $backend === $state->ziel ? 'checked' : '' ?>
                    > <?= e($backend->bezeichnung()) ?>
                </label>
                <p class="feld-hilfe"><?= e($backend->beschreibung()) ?></p>
            <?php endforeach; ?>
        </fieldset>
        <p><button type="submit" class="knopf knopf-primaer">Backend übernehmen</button></p>
    </form>

    <div id="speicher" data-csrf="<?= e($csrf) ?>">
        <h3>Umzug</h3>
        <p id="speicher-stand" aria-live="polite">
            <?php if ($state->fertig()): ?>
                <?= e($dateien($state->gesamt)) ?>
                <?= $state->gesamt === 1 ? 'liegt' : 'liegen' ?>
                <?= e($state->ziel->ortsangabe()) ?>.
            <?php else: ?>
                <?= e(number_format($state->offen, 0, ',', '.')) ?> von
                <?= e($dateien($state->gesamt)) ?>
                <?= $state->offen === 1 ? 'muss' : 'müssen' ?> noch
                <?= e($state->ziel->zielangabe()) ?>.
            <?php endif; ?>
        </p>
        <p class="knopfreihe">
            <button type="button" id="speicher-umzug" class="knopf"<?= $state->fertig() ? ' hidden' : '' ?>>
                Umzug starten
            </button>
            <button type="button" id="speicher-pruefen" class="knopf knopf-still">
                Integritätsprüfung starten
            </button>
        </p>
        <ul id="speicher-log"></ul>
        <p id="speicher-fehler" class="hinweis hinweis-fehler" hidden></p>
    </div>

    <h3>Letzte Integritätsprüfung</h3>
    <?php $pruefung = $state->pruefung; ?>
    <?php if ($pruefung->geprueft === 0 && !$pruefung->fertig): ?>
        <div class="leer">Noch nicht geprüft.</div>
    <?php else: ?>
        <div class="karte">
            <p>
                <?= e($dateien($pruefung->geprueft)) ?> geprüft<?php
                    if ($pruefung->beendetAm !== null) {
                        echo ', abgeschlossen am ' . e($pruefung->beendetAm->format('d.m.Y H:i'));
                    } elseif (!$pruefung->fertig) {
                        echo ' (läuft noch)';
                    }
                ?>.
            </p>
            <?php if ($pruefung->beschaedigt === []): ?>
                <p><span class="marke marke-ok">Ohne Befund</span></p>
            <?php else: ?>
                <p>
                    <span class="marke marke-fehler">
                        <?= e((string) count($pruefung->beschaedigt)) ?> beschädigt
                    </span>
                </p>
                <p class="klein">
                    Betroffene Datensätze (IDs): <?= e(implode(', ', $pruefung->beschaedigt)) ?>.
                    Diese Dateien lassen sich nicht mehr entschlüsseln – das Original aus
                    dem Backup zurückholen (Backup: M2-6).
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>
