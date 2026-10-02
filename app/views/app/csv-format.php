<?php

/**
 * One CSV format (M9-3, issue #61, docs/spec/04-bank-und-abgleich.md
 * section 3): file format and column mapping, read-only. A club's own
 * format can be checked against a fresh export, changed and deleted from
 * here; a shipped one only be looked at.
 *
 * @var string $csrf
 * @var \App\Service\Bank\Csv\CsvProfil $profil
 */

use App\Service\Bank\Csv\CsvFeld;

$basis = '/app/konten/csv-formate/' . $profil->id;
?>
<section class="schmal">
    <h2><?= e($profil->name) ?></h2>

    <p><a href="/app/konten/csv-formate">← Alle CSV-Formate</a></p>

    <?php if ($profil->mitgeliefert): ?>
        <p class="hinweis hinweis-info">
            Dieses Format ist mitgeliefert und wird mit Updates gepflegt; es lässt sich nicht ändern.
            Liest es einen Export nicht richtig, lernen Sie bitte ein eigenes Format an.
        </p>
    <?php endif; ?>

    <h3>Dateiformat</h3>
    <div class="tabelle-rahmen">
        <table class="tabelle">
            <tbody>
                <tr><th scope="row">Trennzeichen</th><td><?= e($profil->trennzeichen->label()) ?></td></tr>
                <tr><th scope="row">Zeichensatz</th><td><?= e($profil->zeichensatz->label()) ?></td></tr>
                <tr><th scope="row">Datumsformat</th><td><?= e($profil->datumsformat->label()) ?></td></tr>
                <tr><th scope="row">Zahlenformat</th><td><?= e($profil->dezimaltrenner->label()) ?></td></tr>
            </tbody>
        </table>
    </div>

    <h3>Spalten</h3>
    <div class="tabelle-rahmen">
        <table class="tabelle">
            <thead>
                <tr>
                    <th scope="col">Feld</th>
                    <th scope="col">Spalte in der Datei</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (CsvFeld::cases() as $feld): ?>
                    <?php $spalten = $profil->spalten($feld); ?>
                    <?php if ($spalten !== []): ?>
                        <tr>
                            <td><?= e($feld->label()) ?></td>
                            <td><?= e(implode($feld->istText() ? ' + ' : ' oder ', $spalten)) ?></td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if (!$profil->mitgeliefert): ?>
        <p class="knopfreihe">
            <a class="knopf knopf-primaer" href="<?= e($basis) ?>/bearbeiten">Bearbeiten</a>
        </p>

        <h3>Format löschen</h3>
        <p class="gedaempft">Bereits importierte Buchungen bleiben erhalten.</p>
        <form method="post" action="<?= e($basis) ?>/loeschen" class="formular">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <p class="knopfreihe">
                <button type="submit" class="knopf knopf-gefahr">Format löschen</button>
            </p>
        </form>
    <?php endif; ?>
</section>
