<?php

declare(strict_types=1);

namespace App\Service\Bank\Csv;

/**
 * A profile the assistant cannot save. The message is German (shown on the
 * page); $feld names the form field to mark.
 */
final class CsvProfilUngueltig extends \DomainException
{
    public function __construct(string $message, public readonly string $feld)
    {
        parent::__construct($message);
    }
}
