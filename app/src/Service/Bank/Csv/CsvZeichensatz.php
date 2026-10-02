<?php

declare(strict_types=1);

namespace App\Service\Bank\Csv;

/**
 * Character set of a CSV export. German banks send Windows-1252 (often
 * announced as ISO-8859-1 - Windows-1252 is the superset, so it covers
 * both) or UTF-8, sometimes with a byte order mark.
 *
 * "Automatisch" decides per file: valid UTF-8 is UTF-8, anything else
 * Windows-1252. German text in Windows-1252 with an umlaut is never valid
 * UTF-8, so the rule does not misread a real export - and a profile keeps
 * working when a bank changes the character set of its export.
 */
enum CsvZeichensatz: string
{
    case Automatisch = 'auto';
    case Utf8 = 'UTF-8';
    case Windows1252 = 'Windows-1252';

    public function label(): string
    {
        return match ($this) {
            self::Automatisch => 'Automatisch (UTF-8 oder Windows-1252)',
            self::Utf8 => 'UTF-8',
            self::Windows1252 => 'Windows-1252 / ISO-8859-1',
        };
    }

    /**
     * The text as UTF-8, without a byte order mark. A file declared UTF-8
     * that is none is refused rather than shown garbled.
     *
     * @throws CsvException
     */
    public function zuUtf8(string $inhalt): string
    {
        if ($this === self::Automatisch) {
            return self::erkenne($inhalt)->zuUtf8($inhalt);
        }
        if (str_starts_with($inhalt, "\xEF\xBB\xBF")) {
            $inhalt = substr($inhalt, 3);
        }

        return match ($this) {
            self::Utf8 => mb_check_encoding($inhalt, 'UTF-8')
                ? $inhalt
                : throw new CsvException('Die Datei ist nicht in UTF-8 gespeichert – bitte den Zeichensatz Windows-1252 wählen.'),
            default => mb_convert_encoding($inhalt, 'UTF-8', 'Windows-1252'),
        };
    }

    /** UTF-8 when the bytes are valid UTF-8 (or carry its BOM), otherwise Windows-1252. */
    public static function erkenne(string $inhalt): self
    {
        return str_starts_with($inhalt, "\xEF\xBB\xBF") || mb_check_encoding($inhalt, 'UTF-8') ? self::Utf8 : self::Windows1252;
    }
}
