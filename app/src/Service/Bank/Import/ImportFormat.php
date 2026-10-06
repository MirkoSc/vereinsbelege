<?php

declare(strict_types=1);

namespace App\Service\Bank\Import;

/**
 * How a statement file is read (`bank_import.format`, M9-4, issue #62):
 * `mt940`, or `csv:<id>` with the id of the CSV profile
 * (App\Service\Bank\Csv\CsvProfil). Stored with the import, so every step
 * of the chain reads the file the same way the preview did.
 */
final readonly class ImportFormat
{
    private const string MT940 = 'mt940';

    private function __construct(public ?int $csvProfilId)
    {
    }

    public static function mt940(): self
    {
        return new self(null);
    }

    public static function csv(int $profilId): self
    {
        if ($profilId < 1) {
            throw new \InvalidArgumentException('Ungültiges CSV-Format.');
        }

        return new self($profilId);
    }

    /**
     * @throws \InvalidArgumentException for anything toString() never wrote
     */
    public static function fromString(string $wert): self
    {
        if ($wert === self::MT940) {
            return self::mt940();
        }
        if (preg_match('/^csv:([1-9][0-9]{0,18})$/', $wert, $treffer) === 1) {
            return self::csv((int) $treffer[1]);
        }

        throw new \InvalidArgumentException('Unbekanntes Importformat.');
    }

    public function istMt940(): bool
    {
        return $this->csvProfilId === null;
    }

    public function toString(): string
    {
        return $this->csvProfilId === null ? self::MT940 : 'csv:' . $this->csvProfilId;
    }
}
