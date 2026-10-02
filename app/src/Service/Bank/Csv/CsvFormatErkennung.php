<?php

declare(strict_types=1);

namespace App\Service\Bank\Csv;

/**
 * Detects the layout of a CSV export nobody has a profile for yet
 * (docs/spec/04-bank-und-abgleich.md section 3, "Erkennung von
 * Trennzeichen, Zeichensatz und Zahlenformat"):
 *
 * - character set: UTF-8 when the bytes are valid UTF-8 (or start with its
 *   BOM), otherwise Windows-1252;
 * - separator: the candidate (; , tab |) that splits the most records of
 *   the first lines into the same number (> 1) of cells - quotes respected;
 * - header: the first record with that number of cells, at least half of
 *   them filled and none looking like a date - a preamble (account,
 *   period) above it is skipped;
 * - date and number notation: the one most cells below the header match;
 * - mapping: a suggestion per column from its name (CsvFeld::vorschlag()).
 *
 * Reads at most the first ERKENNUNG_ZEILEN records. Framework-free; nothing
 * is stored.
 */
final class CsvFormatErkennung
{
    private const int ERKENNUNG_ZEILEN = 60;

    /**
     * @throws CsvException when no separator splits the file into columns
     */
    public function erkenne(string $inhalt): CsvErkennung
    {
        CsvTabelle::pruefeText($inhalt);
        $zeichensatz = CsvZeichensatz::erkenne($inhalt);
        $text = $zeichensatz->zuUtf8($inhalt);

        [$trennzeichen, $spaltenzahl, $zeilen] = $this->trennzeichen($text);
        $kopfIndex = $this->kopfIndex($zeilen, $spaltenzahl);
        $kopf = array_map(trim(...), $zeilen[$kopfIndex]->zellen);
        $daten = array_values(array_filter(
            array_slice($zeilen, $kopfIndex + 1),
            static fn (CsvZeile $z): bool => !$z->istLeer(),
        ));

        $datumsformat = $this->datumsformat($daten);

        return new CsvErkennung(
            $zeichensatz,
            $trennzeichen,
            $kopf,
            $zeilen[$kopfIndex]->zeile,
            $datumsformat,
            $this->dezimaltrenner($daten, $datumsformat),
            $this->vorschlag($kopf),
        );
    }

    /**
     * The header under a separator and character set the person chose in
     * the assistant - the column names its mapping offers, found the same
     * way erkenne() finds them.
     *
     * @return array{list<string>, int} header cells, line of the header
     *
     * @throws CsvException
     */
    public function kopf(string $inhalt, CsvZeichensatz $zeichensatz, CsvTrennzeichen $trennzeichen): array
    {
        CsvTabelle::pruefeText($inhalt);
        $zeilen = CsvTabelle::zerlege($zeichensatz->zuUtf8($inhalt), $trennzeichen, self::ERKENNUNG_ZEILEN);
        $anzahl = [];
        foreach ($zeilen as $zeile) {
            if (!$zeile->istLeer() && count($zeile->zellen) > 1) {
                $anzahl[count($zeile->zellen)] = ($anzahl[count($zeile->zellen)] ?? 0) + 1;
            }
        }
        if ($anzahl === []) {
            throw new CsvException('Mit diesem Trennzeichen zerfällt die Datei nicht in Spalten – bitte ein anderes wählen.');
        }
        arsort($anzahl);
        $index = $this->kopfIndex($zeilen, (int) array_key_first($anzahl));

        return [array_map(trim(...), $zeilen[$index]->zellen), $zeilen[$index]->zeile];
    }

    /**
     * @return array{CsvTrennzeichen, int, list<CsvZeile>}
     */
    private function trennzeichen(string $text): array
    {
        $bestes = null;
        foreach (CsvTrennzeichen::cases() as $kandidat) {
            $zeilen = CsvTabelle::zerlege($text, $kandidat, self::ERKENNUNG_ZEILEN);
            $anzahl = [];
            foreach ($zeilen as $zeile) {
                if (!$zeile->istLeer() && count($zeile->zellen) > 1) {
                    $anzahl[count($zeile->zellen)] = ($anzahl[count($zeile->zellen)] ?? 0) + 1;
                }
            }
            if ($anzahl === []) {
                continue;
            }
            arsort($anzahl);
            $spaltenzahl = (int) array_key_first($anzahl);
            $treffer = $anzahl[$spaltenzahl];
            // Earlier candidates win a tie: ";" is what German banks use.
            if ($bestes === null || $treffer > $bestes[3]) {
                $bestes = [$kandidat, $spaltenzahl, $zeilen, $treffer];
            }
        }

        if ($bestes === null) {
            throw new CsvException('In der Datei wurde kein Trennzeichen erkannt – ist es ein CSV-Export?');
        }

        return [$bestes[0], $bestes[1], $bestes[2]];
    }

    /**
     * @param list<CsvZeile> $zeilen
     */
    private function kopfIndex(array $zeilen, int $spaltenzahl): int
    {
        foreach ($zeilen as $index => $zeile) {
            if (count($zeile->zellen) !== $spaltenzahl) {
                continue;
            }
            $gefuellt = array_filter($zeile->zellen, static fn (string $z): bool => trim($z) !== '');
            if (count($gefuellt) * 2 < $spaltenzahl) {
                continue;
            }
            foreach ($gefuellt as $zelle) {
                foreach (CsvDatumsformat::cases() as $format) {
                    if ($format->passt($zelle)) {
                        continue 3;
                    }
                }
            }

            return $index;
        }

        throw new CsvException('In der Datei wurde keine Kopfzeile mit Spaltennamen gefunden.');
    }

    /**
     * @param list<CsvZeile> $daten
     */
    private function datumsformat(array $daten): ?CsvDatumsformat
    {
        $treffer = [];
        foreach ($daten as $zeile) {
            foreach ($zeile->zellen as $zelle) {
                foreach (CsvDatumsformat::cases() as $format) {
                    if ($format->parse($zelle) !== null) {
                        $treffer[$format->value] = ($treffer[$format->value] ?? 0) + 1;
                    }
                }
            }
        }
        if ($treffer === []) {
            return null;
        }
        arsort($treffer);

        return CsvDatumsformat::from((string) array_key_first($treffer));
    }

    /**
     * @param list<CsvZeile> $daten
     */
    private function dezimaltrenner(array $daten, ?CsvDatumsformat $datumsformat): ?CsvDezimaltrenner
    {
        $treffer = [];
        foreach ($daten as $zeile) {
            foreach ($zeile->zellen as $zelle) {
                if ($datumsformat?->passt($zelle) === true) {
                    continue;
                }
                foreach (CsvDezimaltrenner::cases() as $trenner) {
                    if ($trenner->passt($zelle)) {
                        $treffer[$trenner->value] = ($treffer[$trenner->value] ?? 0) + 1;
                    }
                }
            }
        }
        if ($treffer === []) {
            return null;
        }
        arsort($treffer);

        return CsvDezimaltrenner::from((string) array_key_first($treffer));
    }

    /**
     * One column per field, the first that fits - except the purpose,
     * which may take several ("Verwendungszweck 1", "Verwendungszweck 2").
     *
     * @param list<string> $kopf
     *
     * @return array<string, list<string>>
     */
    private function vorschlag(array $kopf): array
    {
        $vorschlag = [];
        foreach ($kopf as $spalte) {
            $feld = CsvFeld::vorschlag($spalte);
            if ($feld === null || ($feld !== CsvFeld::Verwendungszweck && isset($vorschlag[$feld->value]))) {
                continue;
            }
            $vorschlag[$feld->value][] = $spalte;
        }
        // A signed amount column beats debit/credit columns next to it.
        if (isset($vorschlag[CsvFeld::Betrag->value])) {
            unset($vorschlag[CsvFeld::Soll->value], $vorschlag[CsvFeld::Haben->value]);
        }

        return $vorschlag;
    }
}
