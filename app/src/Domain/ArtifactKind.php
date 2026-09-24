<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * `document_artifact.kind` (docs/spec/02-datenmodell.md "Fachdaten",
 * docs/spec/03-erfassung-und-ki.md section 5). Only `PageImage` has a
 * producer yet (`render_pages`, issue #30/M4-8); the rest are the spec's
 * other kinds and arrive with their own jobs.
 */
enum ArtifactKind: string
{
    case PageImage = 'page_image';
    case Pdfa = 'pdfa';
    case Text = 'text';
    case Extraction = 'extraction';
}
