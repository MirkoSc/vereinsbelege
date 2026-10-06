<?php

declare(strict_types=1);

namespace App\Service\Processing;

/**
 * What reading a PDF's text layer found (App\Service\Processing\Pdf\
 * PdfTextlayer, issue #45/M7-3). Only `Gelesen` can carry text; every other
 * case sends the document down the page image route
 * (docs/spec/03-erfassung-und-ki.md section 3). Technical, never content.
 */
enum TextlayerBefund: string
{
    /** The pages were read - whether the text is any good, Textlayer::brauchbar() decides. */
    case Gelesen = 'gelesen';

    /** Encrypted: the strings cannot be read without the key (pdf.js asks for a password when rendering). */
    case Verschluesselt = 'verschluesselt';

    /** Not a PDF, or one whose structure this reader cannot follow. */
    case Defekt = 'defekt';

    /** Beyond the reader's limits (decoded size, operators per page). */
    case ZuGross = 'zu_gross';
}
