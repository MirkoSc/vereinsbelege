<?php

declare(strict_types=1);

namespace App\Service\Document;

/**
 * Marker for `job.last_error` (issue #30/M4-8): pdf.js could open the file
 * but it needs a password neither the submitter nor the club ever gave it -
 * nothing left to try in the browser. Never thrown; only its class name is
 * stored (App\Repository\JobRepository::fail()).
 */
final class PdfPasswortGeschuetzt extends \RuntimeException
{
}
