<?php

declare(strict_types=1);

namespace App\Service\Bank\Csv;

/**
 * How to read one bank's CSV export (`csv_profile`, docs/spec/
 * 04-bank-und-abgleich.md section 3): separator, character set, date and
 * number notation, and which columns carry which field.
 *
 * Columns are mapped by NAME, never by position, and a field may list
 * several names: header variants of the same bank (CSV-CAMT V2/V8) differ
 * in order and extra columns, sometimes in spelling ("Glaeubiger ID" /
 * "Gläubiger-ID"). Names are compared normalised (normalisiere()).
 *
 * Holds no club data - column names and formats only - so the table is
 * plaintext (02-datenmodell.md).
 */
final readonly class CsvProfil
{
    public const int NAME_MAX = 100;

    /**
     * @param array<string, list<string>> $zuordnung CsvFeld value => column
     *        names in the order they are tried (text fields: joined)
     * @param string|null $kopfSignatur signature() of the header the
     *        profile was made from; null when unknown
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public bool $mitgeliefert,
        public CsvTrennzeichen $trennzeichen,
        public CsvZeichensatz $zeichensatz,
        public CsvDatumsformat $datumsformat,
        public CsvDezimaltrenner $dezimaltrenner,
        public array $zuordnung,
        public ?string $kopfSignatur,
    ) {
    }

    /**
     * A profile as typed into the assistant: trimmed, empty column names
     * dropped, checked.
     *
     * @param array<string, list<string>> $zuordnung
     *
     * @throws CsvProfilUngueltig
     */
    public static function neu(
        ?int $id,
        string $name,
        CsvTrennzeichen $trennzeichen,
        CsvZeichensatz $zeichensatz,
        CsvDatumsformat $datumsformat,
        CsvDezimaltrenner $dezimaltrenner,
        array $zuordnung,
        ?string $kopfSignatur,
    ): self {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($name === '') {
            throw new CsvProfilUngueltig('Bitte einen Namen für das Format angeben.', 'name');
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            throw new CsvProfilUngueltig('Der Name darf höchstens ' . self::NAME_MAX . ' Zeichen lang sein.', 'name');
        }

        $sauber = [];
        foreach (CsvFeld::cases() as $feld) {
            $spalten = [];
            foreach ($zuordnung[$feld->value] ?? [] as $spalte) {
                $spalte = trim($spalte);
                if ($spalte !== '' && !in_array($spalte, $spalten, true)) {
                    $spalten[] = $spalte;
                }
            }
            if ($spalten !== []) {
                $sauber[$feld->value] = $spalten;
            }
        }

        $profil = new self($id, $name, false, $trennzeichen, $zeichensatz, $datumsformat, $dezimaltrenner, $sauber, $kopfSignatur);
        $profil->pruefePflichtfelder();

        return $profil;
    }

    /**
     * @throws CsvProfilUngueltig when the booking date or the amount is not
     *         mapped
     */
    public function pruefePflichtfelder(): void
    {
        if ($this->spalten(CsvFeld::Buchungstag) === []) {
            throw new CsvProfilUngueltig('Bitte die Spalte für den Buchungstag zuordnen.', CsvFeld::Buchungstag->value);
        }
        $betrag = $this->spalten(CsvFeld::Betrag) !== [];
        $soll = $this->spalten(CsvFeld::Soll) !== [];
        $haben = $this->spalten(CsvFeld::Haben) !== [];
        if ($betrag && ($soll || $haben)) {
            throw new CsvProfilUngueltig('Entweder eine Betragsspalte oder getrennte Spalten für Soll und Haben – nicht beides.', CsvFeld::Betrag->value);
        }
        if (!$betrag && !$soll && !$haben) {
            throw new CsvProfilUngueltig('Bitte die Spalte für den Betrag zuordnen – oder die Spalten für Soll und Haben.', CsvFeld::Betrag->value);
        }
        if (!$betrag && !$soll) {
            throw new CsvProfilUngueltig('Bitte auch die Spalte für Soll zuordnen.', CsvFeld::Soll->value);
        }
        if (!$betrag && !$haben) {
            throw new CsvProfilUngueltig('Bitte auch die Spalte für Haben zuordnen.', CsvFeld::Haben->value);
        }
    }

    /**
     * @return list<string>
     */
    public function spalten(CsvFeld $feld): array
    {
        return $this->zuordnung[$feld->value] ?? [];
    }

    /**
     * Which header cells each field reads - only columns the file has. A
     * non-text field reads the first listed one present.
     *
     * @param list<string> $kopf header cells
     *
     * @return array<string, list<int>> CsvFeld value => column indexes
     */
    public function aufloesen(array $kopf): array
    {
        $position = [];
        foreach ($kopf as $index => $spalte) {
            $position[self::normalisiere($spalte)] ??= $index;
        }

        $ergebnis = [];
        foreach (CsvFeld::cases() as $feld) {
            $indexe = [];
            foreach ($this->spalten($feld) as $spalte) {
                $index = $position[self::normalisiere($spalte)] ?? null;
                if ($index !== null && !in_array($index, $indexe, true)) {
                    $indexe[] = $index;
                }
            }
            if ($indexe !== []) {
                $ergebnis[$feld->value] = $feld->istText() ? $indexe : [$indexe[0]];
            }
        }

        return $ergebnis;
    }

    /**
     * Whether a header carries every required field of this profile.
     *
     * @param list<string> $kopf
     */
    public function passtZu(array $kopf): bool
    {
        return $this->fehlendesPflichtfeld($kopf) === null;
    }

    /**
     * @param list<string> $kopf
     */
    public function fehlendesPflichtfeld(array $kopf): ?CsvFeld
    {
        $aufgeloest = $this->aufloesen($kopf);
        if (!isset($aufgeloest[CsvFeld::Buchungstag->value])) {
            return CsvFeld::Buchungstag;
        }
        if ($this->spalten(CsvFeld::Betrag) !== []) {
            return isset($aufgeloest[CsvFeld::Betrag->value]) ? null : CsvFeld::Betrag;
        }
        foreach ([CsvFeld::Soll, CsvFeld::Haben] as $feld) {
            if (!isset($aufgeloest[$feld->value])) {
                return $feld;
            }
        }

        return null;
    }

    /**
     * Lower case, umlauts spelled out ("Gläubiger" = "Glaeubiger" - banks
     * write both, and a Windows-1252 file read as something else loses
     * them), whitespace collapsed, quotes and a BOM trimmed.
     */
    public static function normalisiere(string $spalte): string
    {
        $text = mb_strtolower(trim($spalte, " \t\n\r\0\x0B\"'\u{FEFF}"));
        $text = strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);

        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    /**
     * Fingerprint of a header line: SHA-256 over the normalised column
     * names in order. Equal signature = the very same export layout - the
     * strongest signal of the profile detection.
     *
     * @param list<string> $kopf
     */
    public static function signatur(array $kopf): string
    {
        return hash('sha256', implode("\x1F", array_map(self::normalisiere(...), $kopf)));
    }
}
