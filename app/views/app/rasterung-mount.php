<?php

/**
 * Mount point for public/js/rasterung.js (issue #30/M4-8): shared by
 * app/views/app/posteingang.php and app/views/app/posteingang-detail.php,
 * the same way each row's status pulls in posteingang-status.php. Hidden -
 * it carries no visible content, only the data the script needs, the same
 * pattern app/views/partials/kopf.php uses for #jobs. Empty $pdfjsSrc (no
 * `document.edit`, App\App\InboxController::rasterungDaten()) means nothing
 * renders at all - there is nothing for the script to do.
 *
 * @var string $pdfjsSrc
 * @var string $pdfjsWorkerSrc
 * @var string $pdfjsWasmSrc
 * @var string $csrf
 */

if ($pdfjsSrc === '') {
    return;
}
?>
<span
    id="rasterung"
    hidden
    data-csrf="<?= e($csrf) ?>"
    data-pdfjs="<?= e($pdfjsSrc) ?>"
    data-pdfjs-worker="<?= e($pdfjsWorkerSrc) ?>"
    data-pdfjs-wasm="<?= e($pdfjsWasmSrc) ?>"
></span>
