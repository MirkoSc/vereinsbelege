<?php

declare(strict_types=1);

namespace App\Service\Export;

/**
 * A value of the DATEV settings form the export could not use (issue #93).
 * The message is German and shown as it is; $feld names the form field.
 */
final class UngueltigeDatevEinstellung extends \RuntimeException
{
    public function __construct(string $message, public readonly string $feld)
    {
        parent::__construct($message);
    }
}
