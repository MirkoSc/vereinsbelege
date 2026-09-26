<?php

/**
 * The corner editor (docs/spec/03-erfassung-und-ki.md section 2, "Manuelle
 * Korrektur: 4 ziehbare Eckpunkte ... Lupe beim Ziehen" + Farbmodus-
 * Umschalter, issue #33/M5-3): markup only. `public/js/scanner/eckeditor.js`
 * (`eckEditorBinden()`) wires it up once a source image and starting
 * corners are known - here for the designsystem demo, from M5-4 on also in
 * `/einreichen` and the internal capture.
 *
 * A caller includes this file as-is, the same way `app/views/app/posteingang.php`
 * pulls in `posteingang-status.php` (`require __DIR__ . '/../partials/eck-editor.php'`
 * from a template one level down, e.g. `app/views/admin/`); it reads no
 * variables, so every inclusion is identical markup and `eckEditorBinden()`
 * finds the same selectors everywhere.
 */
?>
<div class="eck-editor">
    <div class="eck-editor-buehne">
        <canvas class="eck-editor-bild"></canvas>
        <svg class="eck-editor-rahmen" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <polygon></polygon>
        </svg>
        <button type="button" class="eck-editor-griff" data-ecke="0" aria-label="Ecke oben links"></button>
        <button type="button" class="eck-editor-griff" data-ecke="1" aria-label="Ecke oben rechts"></button>
        <button type="button" class="eck-editor-griff" data-ecke="2" aria-label="Ecke unten rechts"></button>
        <button type="button" class="eck-editor-griff" data-ecke="3" aria-label="Ecke unten links"></button>
        <canvas class="eck-editor-lupe" width="120" height="120" hidden aria-hidden="true"></canvas>
    </div>

    <p class="knopfreihe">
        <button type="button" class="knopf knopf-still" data-eck-editor="ganzes-bild">Ganzes Bild</button>
        <button type="button" class="knopf knopf-still" data-eck-editor="reset">Zurücksetzen</button>
    </p>

    <fieldset>
        <legend>Farbmodus</legend>
        <label class="feld-ankreuz">
            <input type="radio" name="eck-editor-farbmodus" value="sw" data-eck-editor="farbmodus" checked>
            Schwarzweiß
        </label>
        <label class="feld-ankreuz">
            <input type="radio" name="eck-editor-farbmodus" value="grau" data-eck-editor="farbmodus">
            Graustufen
        </label>
        <label class="feld-ankreuz">
            <input type="radio" name="eck-editor-farbmodus" value="farbe" data-eck-editor="farbmodus">
            Original (Farbe)
        </label>
    </fieldset>
</div>
