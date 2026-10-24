<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * `document_artifact.kind` (docs/spec/02-datenmodell.md "Fachdaten",
 * docs/spec/03-erfassung-und-ki.md section 5). Producers so far:
 * `PageImage` (`render_pages`, issue #30/M4-8), `Text` and `ERechnung`
 * (`extract_text`, issues #45/M7-3 and #46/M7-4), `Vorgabe` (the archive
 * import, issue #77/M12-3), `Extraction` (`ai_extract`, issue #47/M7-5),
 * `Lieferant` (`resolve_supplier`, issue #48/M7-6); the rest are the spec's other kinds and arrive
 * with their own jobs.
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

    /**
     * What the archive import read from the path of an imported file (issue
     * #77/M12-3): supplier, date, number, category, direction, the `Kasse`
     * folder - a suggestion that takes priority over the AI's
     * (App\Service\Archiv\ArchivVorgabe). One per document, written with
     * the document.
     */
    case Vorgabe = 'vorgabe';

    /**
     * How the supplier of the AI's suggestion was resolved (issue #48/M7-6,
     * `resolve_supplier`, App\Service\Document\LieferantenAufloesung):
     * which step matched, which supplier, the suggestions of a similar name,
     * or the supplier created for it. One per run, the newest counts.
     */
    case Lieferant = 'lieferant';
}
