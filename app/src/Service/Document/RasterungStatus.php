<?php

declare(strict_types=1);

namespace App\Service\Document;

/**
 * What one call into App\Service\Document\PdfRasterung produced (issue
 * #30/M4-8). `App\Api\RasterungController` sends the value straight into the
 * JSON answer `public/js/rasterung.js` reads; `gesperrt` (no unlocked vault)
 * is added by the controller, the same way App\Api\JobController adds it for
 * session jobs.
 */
enum RasterungStatus: string
{
    /** A task was claimed - render the page(s) it names. */
    case Aufgabe = 'aufgabe';

    /** Nothing to render right now. */
    case Leer = 'leer';

    /** A page was stored; more of this job, or another job, may follow. */
    case Ok = 'ok';

    /** The whole job (every source PDF) is done. */
    case Fertig = 'fertig';

    /**
     * The upload does not match what this job currently expects - a stale
     * request from a tab that lost track of its own progress. The browser
     * has to fetch a fresh task, not retry blindly.
     */
    case Unerwartet = 'unerwartet';

    /**
     * The lock is no longer this caller's (expired and reclaimed, or the
     * job already finished under a different call) - stop, do not retry.
     */
    case Verloren = 'verloren';

    /** Not a JPEG, or not one at all (empty body). */
    case UngueltigerTyp = 'ungueltiger_typ';

    /** A JPEG, but larger than App\Service\Document\PdfRasterung allows -
     * in bytes or in pixel dimensions. */
    case ZuGross = 'zu_gross';
}
