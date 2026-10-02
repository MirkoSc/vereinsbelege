<?php

declare(strict_types=1);

namespace App\Service\Bank\Csv;

/**
 * Splits CSV text into records (RFC 4180 as banks write it): cells in
 * double quotes may contain the separator, line breaks and doubled quotes
 * (""); CRLF, LF and CR all end a record.
 *
 * Own loop instead of str_getcsv()/fgetcsv(): those treat a backslash as
 * escape character and cannot report on which line a record starts - the
 * line number is all an error message may name. Works in memory only;
 * nothing of the file is written anywhere.
 */
final class CsvTabelle
{
    private function __construct()
    {
        // Static utility, no instances.
    }

    /**
     * @param int|null $hoechstens stop after this many records (format
     *        detection reads only the beginning)
     *
     * @return list<CsvZeile> every record, empty ones included
     */
    public static function zerlege(string $text, CsvTrennzeichen $trennzeichen, ?int $hoechstens = null): array
    {
        $trenner = $trennzeichen->zeichen();
        $laenge = strlen($text);
        $zeilen = [];
        $zellen = [];
        $zelle = '';
        $inQuotes = false;
        $zeile = 1;
        $beginn = 1;

        for ($i = 0; $i < $laenge; $i++) {
            $zeichen = $text[$i];

            if ($inQuotes) {
                if ($zeichen === '"') {
                    if (($text[$i + 1] ?? '') === '"') {
                        $zelle .= '"';
                        $i++;
                    } else {
                        $inQuotes = false;
                    }
                    continue;
                }
                if ($zeichen === "\n" || ($zeichen === "\r" && ($text[$i + 1] ?? '') !== "\n")) {
                    $zeile++;
                }
                $zelle .= $zeichen;
                continue;
            }

            if ($zeichen === '"' && trim($zelle) === '') {
                $inQuotes = true;
                $zelle = '';
                continue;
            }
            if ($zeichen === $trenner) {
                $zellen[] = $zelle;
                $zelle = '';
                continue;
            }
            if ($zeichen === "\r" || $zeichen === "\n") {
                if ($zeichen === "\r" && ($text[$i + 1] ?? '') === "\n") {
                    $i++;
                }
                $zellen[] = $zelle;
                $zeilen[] = new CsvZeile($beginn, $zellen);
                if ($hoechstens !== null && count($zeilen) >= $hoechstens) {
                    return $zeilen;
                }
                $zellen = [];
                $zelle = '';
                $zeile++;
                $beginn = $zeile;
                continue;
            }
            $zelle .= $zeichen;
        }

        if ($zelle !== '' || $zellen !== [] || $inQuotes) {
            $zellen[] = $zelle;
            $zeilen[] = new CsvZeile($beginn, $zellen);
        }

        return $zeilen;
    }

    /**
     * @throws CsvException when the bytes are no text file
     */
    public static function pruefeText(string $inhalt): void
    {
        if (trim($inhalt) === '') {
            throw new CsvException('Die Datei ist leer.');
        }
        if (str_contains($inhalt, "\0")) {
            throw new CsvException('Die Datei ist keine Textdatei – bitte den CSV-Export der Bank wählen.');
        }
    }
}
