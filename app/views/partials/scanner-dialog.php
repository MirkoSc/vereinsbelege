<?php

/**
 * The corner editor as a dialog (docs/spec/03-erfassung-und-ki.md section 2,
 * issue #34/M5-4): /einreichen opens it for every image, /app/belege/neu on
 * "Zuschneiden". `public/js/scanner/scanner.js` (`scannerOeffnen()`) binds
 * the editor inside to each image in turn - one dialog per page, because
 * the editor's colour-mode radios share one name
 * (`app/views/partials/eck-editor.php`).
 *
 * Reads no variables; include it once per page.
 */
?>
<dialog class="scanner-dialog" id="scanner-dialog" aria-labelledby="scanner-dialog-titel">
    <h2 id="scanner-dialog-titel">Beleg zuschneiden</h2>
    <p class="feld-hilfe">
        Ecken auf die Kanten des Belegs ziehen. Die Originalaufnahme wird zusätzlich unverändert gespeichert.
    </p>

    <?php require __DIR__ . '/eck-editor.php'; ?>

    <p class="knopfreihe">
        <button type="button" class="knopf knopf-primaer" data-scanner="uebernehmen">Übernehmen</button>
        <button type="button" class="knopf knopf-still" data-scanner="abbrechen">Abbrechen</button>
        <span class="lade-anzeige" data-scanner="status" hidden>Wird aufbereitet …</span>
    </p>
</dialog>
