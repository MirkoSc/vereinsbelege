<?php

declare(strict_types=1);

namespace App\Service\Integritaet;

/**
 * The code integrity check cannot run here (no per-file checksums, or
 * they belong to another version); the message says why, in German.
 */
final class IntegritaetPruefungNichtMoeglich extends \RuntimeException
{
}
