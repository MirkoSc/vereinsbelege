<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * `document_artifact.kind` (docs/spec/02-datenmodell.md "Fachdaten",
 * docs/spec/03-erfassung-und-ki.md section 5). Producers so far:
 * `PageImage` (`render_pages`, issue #30/M4-8), `Text` and `ERechnung`
 * (`extract_text`, issues #45/M7-3 and #46/M7-4); the rest are the spec's
 * other kinds and arrive with their own jobs.
 */
enum ArtifactKind: string
{
    case PageImage = 'page_image';
    case Pdfa = 'pdfa';
    case Text = 'text';
    case Extraction = 'extraction';

    /**
     * The fields of a structured e-invoice (ZUGFeRD/Factur-X, XRechnung),
     * read exactly and without AI - kept apart from `Extraction`, which holds
     * the AI's suggestions (M7-5): both have their own run and lifetime.
     */
    case ERechnung = 'e_rechnung';
}
