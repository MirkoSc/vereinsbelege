<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Text recognition status of a `document` row (docs/spec/02-datenmodell.md
 * "Fachdaten"). A freshly received document starts `Keine` - it is turned
 * into `Ausstehend` once something actually queues the text layer or OCR
 * step (docs/spec/03-erfassung-und-ki.md section 3, from M7 on).
 */
enum OcrStatus: string
{
    case Keine = 'keine';
    case Ausstehend = 'ausstehend';
    case Fertig = 'fertig';
    case Uebersprungen = 'uebersprungen';
}
