<?php

declare(strict_types=1);

namespace App\Service\Bank\Csv;

/**
 * One record of a CSV file: its cells and the line it starts on (a quoted
 * cell may span lines, so record and line numbers differ).
 */
final readonly class CsvZeile
{
    /**
     * @param list<string> $zellen
     */
    public function __construct(
        public int $zeile,
        public array $zellen,
    ) {
    }

    public function istLeer(): bool
    {
        foreach ($this->zellen as $zelle) {
            if (trim($zelle) !== '') {
                return false;
            }
        }

        return true;
    }
}
