<?php

declare(strict_types=1);

namespace App\Service\Bank\Csv;

/**
 * Internal to CsvParser: ends reading one row, becomes a CsvZeilenfehler.
 */
final class CsvZeilenfehlerException extends \RuntimeException
{
}
