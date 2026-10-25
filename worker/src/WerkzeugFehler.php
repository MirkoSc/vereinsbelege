<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * A tool failed. $endgueltig: the input itself is the problem (a broken or
 * password-protected PDF) - trying again will not help, the web host's
 * browser/session route takes over. The code is an identifier for the
 * web host's `fail` (never the tool's output).
 */
final class WerkzeugFehler extends \RuntimeException
{
    public function __construct(public readonly string $fehlercode, public readonly bool $endgueltig)
    {
        parent::__construct($fehlercode);
    }
}
