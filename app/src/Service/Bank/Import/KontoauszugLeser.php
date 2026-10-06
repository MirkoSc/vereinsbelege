<?php

declare(strict_types=1);

namespace App\Service\Bank\Import;

use App\Service\Bank\Csv\CsvBuchung;
use App\Service\Bank\Csv\CsvException;
use App\Service\Bank\Csv\CsvParser;
use App\Service\Bank\Csv\CsvProfil;
use App\Service\Bank\Csv\CsvProfilErkennung;
use App\Service\Bank\Kontoangabe;
use App\Service\Bank\Kontoauszug;
use App\Service\Bank\Mt940Exception;
use App\Service\Bank\Mt940Parser;

/**
 * Reads a statement file for the import (M9-4, issue #62, docs/spec/
 * 04-bank-und-abgleich.md section 4) with the parsers of M9-2/M9-3 and
 * turns both formats into one App\Service\Bank\Import\ImportDatei.
 *
 * Pure: the file comes in as a string, nothing is stored or logged, and
 * every message names at most a line and a field (KontoauszugUnlesbar).
 *
 * Fixed here:
 * - MT940 is recognised by its first fields (`:20:` or a SWIFT block
 *   `{1:`), everything else is CSV and needs a fitting profile
 *   (App\Service\Bank\Csv\CsvProfilErkennung).
 * - One file, one account: an MT940 file with statements of several
 *   accounts is refused - one import belongs to one account.
 * - A CSV export written newest first is turned round, so the postings are
 *   chronological. The direction comes from the dates, for a file of a
 *   single day from the balance column if there is one.
 */
final readonly class KontoauszugLeser
{
    /** How many non-empty lines at the start of a file decide whether it is MT940. */
    private const int KOPF_ZEILEN = 10;

    public function __construct(
        private Mt940Parser $mt940 = new Mt940Parser(),
        private CsvParser $csv = new CsvParser(),
        private CsvProfilErkennung $profilErkennung = new CsvProfilErkennung(),
    ) {
    }

    public function istMt940(string $inhalt): bool
    {
        $text = str_starts_with($inhalt, "\xEF\xBB\xBF") ? substr($inhalt, 3) : $inhalt;
        $gesehen = 0;
        foreach (preg_split('/\r\n|\r|\n/', substr($text, 0, 8192)) ?: [] as $zeile) {
            $zeile = trim($zeile);
            if ($zeile === '') {
                continue;
            }
            if (str_starts_with($zeile, ':20:') || str_starts_with($zeile, '{1:')) {
                return true;
            }
            if (++$gesehen >= self::KOPF_ZEILEN) {
                break;
            }
        }

        return false;
    }

    /**
     * The format of an uploaded file: MT940, or the CSV profile that fits.
     *
     * @param list<CsvProfil> $profile
     *
     * @throws KontoauszugUnlesbar
     */
    public function erkenne(string $inhalt, array $profile): ImportFormat
    {
        if ($this->istMt940($inhalt)) {
            return ImportFormat::mt940();
        }

        $profil = $this->profilErkennung->finde($inhalt, $profile);
        if ($profil === null || $profil->id === null) {
            throw new KontoauszugUnlesbar(
                'Die Datei ist weder MT940 noch passt sie zu einem bekannten CSV-Format. Bitte unter „CSV-Formate“ ein Format für diese Bank anlegen.',
                csvFormatFehlt: true,
            );
        }

        return ImportFormat::csv($profil->id);
    }

    /**
     * @param list<CsvProfil> $profile the stored CSV profiles - a CSV format
     *        names one of them by id
     *
     * @throws KontoauszugUnlesbar
     */
    public function lies(string $inhalt, ImportFormat $format, array $profile): ImportDatei
    {
        if ($format->istMt940()) {
            return $this->liesMt940($inhalt, $format);
        }

        foreach ($profile as $profil) {
            if ($profil->id === $format->csvProfilId) {
                return $this->liesCsv($inhalt, $format, $profil);
            }
        }

        throw new KontoauszugUnlesbar('Das CSV-Format dieses Imports gibt es nicht mehr.', csvFormatFehlt: true);
    }

    /**
     * Who the account of an MT940 account line is: the IBAN, else bank code
     * and account number (leading zeros dropped), else the line itself.
     */
    public static function kontokennung(Kontoangabe $konto): string
    {
        if ($konto->iban !== null) {
            return $konto->iban;
        }
        if ($konto->blz !== null && $konto->kontonummer !== null) {
            return strtoupper($konto->blz) . '/' . ltrim($konto->kontonummer, '0');
        }

        return strtoupper((string) preg_replace('/\s+/', '', $konto->roh));
    }

    /**
     * @throws KontoauszugUnlesbar
     */
    private function liesMt940(string $inhalt, ImportFormat $format): ImportDatei
    {
        try {
            $auszuege = $this->mt940->parse($inhalt);
        } catch (Mt940Exception $e) {
            throw new KontoauszugUnlesbar('Die MT940-Datei ist nicht lesbar: ' . $e->getMessage());
        }
        if ($auszuege === []) {
            throw new KontoauszugUnlesbar('Die Datei enthält keinen Kontoauszug.');
        }

        $kennungen = array_unique(array_map(static fn(Kontoauszug $a): string => self::kontokennung($a->konto), $auszuege));
        if (count($kennungen) > 1) {
            throw new KontoauszugUnlesbar('Die Datei enthält Auszüge mehrerer Konten. Bitte je Konto eine eigene Datei exportieren und einzeln importieren.');
        }

        $posten = [];
        foreach ($auszuege as $auszug) {
            foreach ($auszug->umsaetze as $umsatz) {
                $posten[] = new ImportPosten(count($posten), $umsatz, $auszug->schlusssaldo->waehrung, null, null);
            }
        }

        return new ImportDatei($format, $auszuege[0]->konto, $posten, $auszuege, [], 0, false);
    }

    /**
     * @throws KontoauszugUnlesbar
     */
    private function liesCsv(string $inhalt, ImportFormat $format, CsvProfil $profil): ImportDatei
    {
        try {
            $ergebnis = $this->csv->parse($inhalt, $profil);
        } catch (CsvException $e) {
            throw new KontoauszugUnlesbar('Die CSV-Datei ist nicht lesbar: ' . $e->getMessage());
        }

        $buchungen = $ergebnis->buchungen;
        if (self::absteigend($buchungen)) {
            $buchungen = array_reverse($buchungen);
        }

        $posten = [];
        $mitSaldo = $buchungen !== [];
        foreach ($buchungen as $buchung) {
            $posten[] = new ImportPosten(count($posten), $buchung->umsatz, $buchung->waehrung, $buchung->saldoCent, $buchung->zeile);
            $mitSaldo = $mitSaldo && $buchung->saldoCent !== null;
        }

        return new ImportDatei($format, null, $posten, [], $ergebnis->fehler, $ergebnis->vorgemerkt, $mitSaldo);
    }

    /**
     * Whether the rows run newest first. By the dates where they differ;
     * a file of one day only tells by its balance column - the chain
     * "balance before + amount = balance after" holds one way round only.
     *
     * @param list<CsvBuchung> $buchungen
     */
    private static function absteigend(array $buchungen): bool
    {
        if (count($buchungen) < 2) {
            return false;
        }
        $erste = $buchungen[0]->umsatz->buchungsdatum;
        $letzte = $buchungen[count($buchungen) - 1]->umsatz->buchungsdatum;
        if ($erste != $letzte) {
            return $erste > $letzte;
        }

        return !self::kette($buchungen) && self::kette(array_reverse($buchungen));
    }

    /**
     * @param list<CsvBuchung> $buchungen
     */
    private static function kette(array $buchungen): bool
    {
        for ($i = 1, $n = count($buchungen); $i < $n; $i++) {
            $vorher = $buchungen[$i - 1]->saldoCent;
            $nachher = $buchungen[$i]->saldoCent;
            if ($vorher === null || $nachher === null || $vorher + $buchungen[$i]->umsatz->cent !== $nachher) {
                return false;
            }
        }

        return true;
    }
}
