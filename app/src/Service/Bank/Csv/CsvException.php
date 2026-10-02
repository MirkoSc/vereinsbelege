<?php

declare(strict_types=1);

namespace App\Service\Bank\Csv;

/**
 * The file cannot be read as a CSV export at all (no text, no separator,
 * no header the profile knows). German, shown on the page; names at most a
 * line number and a column NAME from the header - never a cell's content
 * (CLAUDE.md section 4). Problems of single rows are no exception but a
 * CsvZeilenfehler in the result.
 */
final class CsvException extends \RuntimeException
{
}
