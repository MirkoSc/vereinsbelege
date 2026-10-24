<?php

declare(strict_types=1);

namespace App\Service\Bank\Import;

/**
 * How a statement file is read (`bank_import.format`, M9-4, issue #62):
 * `mt940`, `camt053` (issue #91, an XML file or a ZIP of them), or
 * `csv:<id>` with the id of the CSV profile
 * (App\Service\Bank\Csv\CsvProfil). Stored with the import, so every step
 * of the chain reads the file the same way the preview did.
 */
final readonly class ImportFormat
{
    private const string MT940 = 'mt940';

    private const string CAMT053 = 'camt053';

    private function __construct(public ?int $csvProfilId, private string $art)
    {
    }

    public static function mt940(): self
    {
        return new self(null, self::MT940);
    }

    public static function camt053(): self
    {
        return new self(null, self::CAMT053);
    }

    public static function csv(int $profilId): self
    {
        if ($profilId < 1) {
            throw new \InvalidArgumentException('Ungültiges CSV-Format.');
        }

        return new self($profilId, 'csv');
    }

    /**
     * @throws \InvalidArgumentException for anything toString() never wrote
     */
    public static function fromString(string $wert): self
    {
        if ($wert === self::MT940) {
            return self::mt940();
        }
        if ($wert === self::CAMT053) {
            return self::camt053();
        }
        if (preg_match('/^csv:([1-9][0-9]{0,18})$/', $wert, $treffer) === 1) {
            return self::csv((int) $treffer[1]);
        }

        throw new \InvalidArgumentException('Unbekanntes Importformat.');
    }

    public function istMt940(): bool
    {
        return $this->art === self::MT940;
    }

    public function istCamt053(): bool
    {
        return $this->art === self::CAMT053;
    }

    public function istCsv(): bool
    {
        return $this->csvProfilId !== null;
    }

    /**
     * Whether the file is made of statements with opening and closing
     * balance (App\Service\Bank\Kontoauszug) - MT940 and CAMT.053 - rather
     * than of CSV rows.
     */
    public function hatAuszuege(): bool
    {
        return !$this->istCsv();
    }

    /** The short name the pages show ("CSV" without the profile's name). */
    public function bezeichnung(): string
    {
        return match ($this->art) {
            self::MT940 => 'MT940',
            self::CAMT053 => 'CAMT.053',
            default => 'CSV',
        };
    }

    public function toString(): string
    {
        return $this->csvProfilId === null ? $this->art : 'csv:' . $this->csvProfilId;
    }
}
