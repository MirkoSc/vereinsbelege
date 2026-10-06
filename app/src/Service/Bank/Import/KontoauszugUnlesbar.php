<?php

declare(strict_types=1);

namespace App\Service\Bank\Import;

/**
 * The file cannot be imported as it is (M9-4, issue #62): not readable, an
 * unknown CSV layout, statements of several accounts, no bookings. The
 * message is German - the import page shows it - and never quotes the
 * file: at most a line number and a field name (CLAUDE.md section 4).
 */
final class KontoauszugUnlesbar extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $csvFormatFehlt = false)
    {
        parent::__construct($message);
    }
}
