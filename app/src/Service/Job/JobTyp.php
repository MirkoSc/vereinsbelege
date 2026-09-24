<?php

declare(strict_types=1);

namespace App\Service\Job;

use App\Domain\Permission;

/**
 * What every job type declares about itself, whether or not App\Service\
 * Job\JobRunner ever calls a `schritt()` on it (docs/spec/06-betrieb.md
 * section 4). JobHandler (session jobs, driven by `POST /api/jobs/step`)
 * extends this with the step itself; a browser job type such as
 * App\Service\Document\PdfRasterung (issue #30/M4-8) implements only this -
 * its steps run in the browser, not inside a request JobRunner drives, but
 * JobRunner::offen() still needs `typ()`/`recht()` to count it into the
 * header ("N Belege in Verarbeitung").
 */
interface JobTyp
{
    /** The `job.typ` this type is responsible for. */
    public function typ(): string;

    /** The right a session needs to drive this job type. */
    public function recht(): Permission;
}
