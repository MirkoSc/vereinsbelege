<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Text recognition status of a `document` row (docs/spec/02-datenmodell.md
 * "Fachdaten"). Since issue #45/M7-3 every new document is received
 * `Ausstehend`, with the `extract_text` job queued
 * (App\Service\Document\Texterkennung); `Keine` remains for rows from
 * before that nobody queued it for (migrations/026). The job ends in
 * `Fertig` - the text layer is enough for the AI, the page images need not
 * be evaluated - or `Uebersprungen`: no PDF, or a text layer that is not
 * usable, so the page images are needed (docs/spec/03-erfassung-und-ki.md
 * section 3).
 */
enum OcrStatus: string
{
    case Keine = 'keine';
    case Ausstehend = 'ausstehend';
    case Fertig = 'fertig';
    case Uebersprungen = 'uebersprungen';
}
