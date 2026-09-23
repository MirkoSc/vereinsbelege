<?php

/**
 * Confirmation for the public submission (issue #24/M4-2, docs/spec/
 * 03-erfassung-und-ki.md section 1). Plain text, rendered by
 * App\Service\Mail\MailTemplates, not App\View\View - no e() escaping here on
 * purpose.
 *
 * No receipt content: only the reference number the submitter already saw on
 * screen, so this mail carries nothing a lost or misdirected copy could leak
 * (CLAUDE.md section 4).
 *
 * @var string $referenz
 * @var string $vereinsname
 */
?>
Vielen Dank für die Einreichung bei <?= $vereinsname !== '' ? $vereinsname : 'Vereinsbelege' ?>.

Ihre Referenznummer: <?= $referenz ?>

Bitte notieren Sie sich diese Nummer für Rückfragen.
