<?php

/**
 * The live camera (docs/spec/03-erfassung-und-ki.md section 1, issue
 * #34/M5-4): video with an A4 guide frame, driven by
 * `public/js/scanner/kamera.js` (`kameraOeffnen()`). "Kamera-App verwenden"
 * is the native camera through <input capture> - the fallback when the
 * browser refuses getUserMedia, and a choice for anyone who prefers it.
 *
 * Reads no variables; include it once per page.
 */
?>
<dialog class="scanner-dialog kamera-dialog" id="kamera-dialog" aria-labelledby="kamera-dialog-titel">
    <h2 id="kamera-dialog-titel">Foto aufnehmen</h2>

    <div class="kamera-buehne">
        <video class="kamera-video" autoplay muted playsinline></video>
        <div class="kamera-rahmen" aria-hidden="true" hidden></div>
    </div>
    <p class="feld-hilfe">Den Beleg im Rahmen ausrichten, flach und gut ausgeleuchtet.</p>
    <p class="hinweis hinweis-fehler" data-kamera="fehler" hidden></p>

    <p class="knopfreihe">
        <button type="button" class="knopf knopf-primaer" data-kamera="ausloesen" disabled>Auslösen</button>
        <label class="knopf knopf-still">
            Kamera-App verwenden
            <input type="file" class="visuell-versteckt" accept="image/*" capture="environment" data-kamera="datei">
        </label>
        <button type="button" class="knopf knopf-still" data-kamera="abbrechen">Abbrechen</button>
    </p>
</dialog>
