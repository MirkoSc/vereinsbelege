<?php

declare(strict_types=1);

namespace App\Service\Bank\Csv;

/**
 * What CsvFormatErkennung found out about an unknown file: the starting
 * point of the mapping assistant, every value changeable there.
 */
final readonly class CsvErkennung
{
    /**
     * @param list<string> $kopf header cells
     * @param int $kopfzeile line of the header in the file (1-based); lines
     *        before it are a preamble the parser skips
     * @param CsvDatumsformat|null $datumsformat null when no cell looked like
     *        a date
     * @param CsvDezimaltrenner|null $dezimaltrenner null when no cell looked
     *        like an amount with decimals
     * @param array<string, list<string>> $vorschlag suggested mapping, CsvFeld
     *        value => column names
     */
    public function __construct(
        public CsvZeichensatz $zeichensatz,
        public CsvTrennzeichen $trennzeichen,
        public array $kopf,
        public int $kopfzeile,
        public ?CsvDatumsformat $datumsformat,
        public ?CsvDezimaltrenner $dezimaltrenner,
        public array $vorschlag,
    ) {
    }
}
