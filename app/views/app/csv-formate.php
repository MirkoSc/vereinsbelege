<?php

/**
 * CSV formats for the statement import (M9-3, issue #61, docs/spec/
 * 04-bank-und-abgleich.md section 3): the shipped ones first, then the
 * club's own, each with its file format. Only components from
 * /admin/designsystem; the table scrolls sideways on narrow screens.
 *
 * @var list<\App\Service\Bank\Csv\CsvProfil> $profile
 */

?>
<section>
    <h2>CSV-Formate</h2>

    <p><a href="/app/konten">← Konten</a></p>

    <p class="gedaempft">
        Wie der Kontoauszug-Import die CSV-Exporte der Banken liest. Empfohlen bleibt MT940:
        es enthält Anfangs- und Schlusssaldo, sodass der Import die Vollständigkeit prüfen kann.
        Für Sparkasse und VR Bank sind Formate mitgeliefert; für andere Banken lernen Sie hier ein Format an.
    </p>

    <p class="knopfreihe">
        <a class="knopf knopf-primaer" href="/app/konten/csv-formate/neu">Neues Format anlernen</a>
    </p>

    <?php if ($profile === []): ?>
        <div class="leer">Noch kein CSV-Format vorhanden.</div>
    <?php else: ?>
        <div class="tabelle-rahmen">
            <table class="tabelle">
                <caption><?= e((string) count($profile)) ?> <?= count($profile) === 1 ? 'Format' : 'Formate' ?></caption>
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Herkunft</th>
                        <th scope="col">Trennzeichen</th>
                        <th scope="col">Zeichensatz</th>
                        <th scope="col">Datum</th>
                        <th scope="col">Zahlen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($profile as $profil): ?>
                        <tr>
                            <td><a href="/app/konten/csv-formate/<?= e((string) $profil->id) ?>"><?= e($profil->name) ?></a></td>
                            <td><span class="marke<?= $profil->mitgeliefert ? ' marke-ok' : '' ?>"><?= $profil->mitgeliefert ? 'mitgeliefert' : 'eigenes' ?></span></td>
                            <td><?= e($profil->trennzeichen->label()) ?></td>
                            <td><?= e($profil->zeichensatz->label()) ?></td>
                            <td><?= e($profil->datumsformat->label()) ?></td>
                            <td><?= e($profil->dezimaltrenner->label()) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
