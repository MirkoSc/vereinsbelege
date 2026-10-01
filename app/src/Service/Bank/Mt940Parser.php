<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Domain\Iban;

/**
 * Reads MT940 account statements (issue #60/M9-2,
 * docs/spec/04-bank-und-abgleich.md section 2). Pure PHP, no library - the
 * format is small enough to be read and tested completely here.
 *
 * Works on the file content in memory only and keeps nothing: no logging,
 * no temp files, and an exception names the line and field tag but never
 * what the line says (CLAUDE.md section 4).
 *
 * What it reads:
 * - Windows-1252/ISO-8859-1 (default) or UTF-8 (detected, BOM stripped),
 *   always returned as UTF-8; CRLF, LF, CR or the old "@@" line separator.
 * - Several statements per file; a field continues over following lines
 *   until the next ":NN:" tag. SWIFT header blocks ("{1:...}{4:") and
 *   fields not needed (:21:, :34F:, :64:, :65:, :NS: ...) are skipped.
 * - :61: with the booking date's year taken from the value date, picking
 *   the closest one across a year change (value date 02.01.2024, booking
 *   date "1229" -> 29.12.2023). Days past the month's end, as some banks
 *   send for interest (30.02.), are moved to the month's last day.
 * - :86: in the German structured format ("GVC?00...?20...") or as plain
 *   text. Purpose subfields ?20-?29/?60-?63 are joined: banks cut the text
 *   into 27-character pieces regardless of words, so a full piece is
 *   continued directly and a shorter one - which ended on its own - gets a
 *   space. SEPA keys (EREF+, SVWZ+ ...) are recognised where a subfield
 *   starts with one, the way banks write them.
 */
final class Mt940Parser
{
    /** Length of a purpose or name subfield in the structured :86:. */
    private const int TEILFELD_LAENGE = 27;

    private const string SEPA_SCHLUESSEL = 'EREF|KREF|MREF|CRED|DEBT|COAM|OAMT|SVWZ|ABWA|ABWE';

    /**
     * @return list<Kontoauszug>
     *
     * @throws Mt940Exception when the file is no readable MT940
     */
    public function parse(string $inhalt): array
    {
        $auszuege = [];
        $offen = null;

        foreach ($this->felder($this->zeilen($this->zuUtf8($inhalt))) as [$tag, $wert, $zeile]) {
            if ($tag === '20' || $tag === '-') {
                if ($offen !== null) {
                    $auszuege[] = $this->abschliessen($offen);
                    $offen = null;
                }
                if ($tag === '20') {
                    $offen = ['zeile' => $zeile, 'referenz' => trim($wert), 'konto' => null, 'nummer' => null,
                        'anfang' => null, 'schluss' => null, 'umsaetze' => [], 'letzter' => '20'];
                }
                continue;
            }

            if ($offen === null) {
                throw new Mt940Exception(sprintf('Zeile %d: Feld :%s: steht vor dem Beginn eines Kontoauszugs (:20:).', $zeile, $tag));
            }

            match ($tag) {
                '25' => $offen['konto'] = $this->kontoangabe($wert),
                '28', '28C' => $offen['nummer'] = trim($wert) !== '' ? trim($wert) : null,
                '60F', '60M' => $offen['anfang'] = $this->saldo($wert, $tag, $zeile),
                '62F', '62M' => $offen['schluss'] = $this->saldo($wert, $tag, $zeile),
                '61' => $offen['umsaetze'][] = $this->umsatz($wert, $zeile),
                '86' => $this->detailsAnhaengen($offen, $wert),
                default => null,
            };
            $offen['letzter'] = $tag;
        }

        if ($offen !== null) {
            $auszuege[] = $this->abschliessen($offen);
        }

        if ($auszuege === []) {
            throw new Mt940Exception('Die Datei enthält keinen MT940-Kontoauszug.');
        }

        return $auszuege;
    }

    private function zuUtf8(string $inhalt): string
    {
        if (str_starts_with($inhalt, "\xEF\xBB\xBF")) {
            return substr($inhalt, 3);
        }

        return mb_check_encoding($inhalt, 'UTF-8') ? $inhalt : mb_convert_encoding($inhalt, 'UTF-8', 'Windows-1252');
    }

    /**
     * @return list<string>
     */
    private function zeilen(string $text): array
    {
        $zeilen = preg_split('/\r\n|\r|\n/', $text) ?: [];
        if (count($zeilen) <= 2 && str_contains($text, '@@')) {
            $zeilen = explode('@@', trim($text));
        }

        return $zeilen;
    }

    /**
     * Groups lines into fields; "-" marks a statement's end.
     *
     * @param list<string> $zeilen
     *
     * @return list<array{string, string, int}> tag, value (continuation
     *         lines joined with "\n"), line number
     */
    private function felder(array $zeilen): array
    {
        $felder = [];
        foreach ($zeilen as $index => $zeile) {
            if (str_starts_with($zeile, '{')) {
                $start = strpos($zeile, '{4:');
                if ($start === false) {
                    continue;
                }
                $zeile = substr($zeile, $start + 3);
            }

            if (trim($zeile) === '') {
                continue;
            }

            if (in_array(trim($zeile), ['-', '-}'], true)) {
                $felder[] = ['-', '', $index + 1];
            } elseif (preg_match('/^:(\d{2}[A-Z]?|NS):(.*)$/s', $zeile, $treffer) === 1) {
                $felder[] = [$treffer[1], $treffer[2], $index + 1];
            } elseif ($felder !== [] && $felder[array_key_last($felder)][0] !== '-') {
                // Continuation of the previous field. Text before the first
                // tag (a bank's export header) is ignored.
                $felder[array_key_last($felder)][1] .= "\n" . $zeile;
            }
        }

        return $felder;
    }

    /**
     * @param array{zeile: int, referenz: string, konto: ?Kontoangabe, nummer: ?string, anfang: ?Saldo, schluss: ?Saldo, umsaetze: list<Umsatz>, letzter: string} $offen
     */
    private function abschliessen(array $offen): Kontoauszug
    {
        foreach (['konto' => ':25:', 'anfang' => ':60F:/:60M:', 'schluss' => ':62F:/:62M:'] as $schluessel => $feld) {
            if ($offen[$schluessel] === null) {
                throw new Mt940Exception(sprintf('Kontoauszug ab Zeile %d: Feld %s fehlt.', $offen['zeile'], $feld));
            }
        }

        return new Kontoauszug(
            $offen['referenz'],
            $offen['konto'],
            $offen['nummer'],
            $offen['anfang'],
            $offen['schluss'],
            $offen['umsaetze'],
        );
    }

    private function kontoangabe(string $wert): Kontoangabe
    {
        $roh = trim($wert);
        $kompakt = strtoupper(str_replace(' ', '', $roh));

        // An IBAN, possibly followed by the currency.
        foreach ([$kompakt, (string) preg_replace('/[A-Z]{3}$/', '', $kompakt)] as $kandidat) {
            if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $kandidat) === 1 && Iban::istGueltig($kandidat)) {
                return new Kontoangabe($roh, $kandidat, null, null);
            }
        }

        // "BLZ/Kontonummer" (the BLZ may also be a BIC), possibly followed by the currency.
        if (preg_match('/^([0-9A-Z]{8,11})\/(\d{1,23})(?:[A-Z]{3})?$/', $kompakt, $treffer) === 1) {
            return new Kontoangabe($roh, null, $treffer[1], $treffer[2]);
        }

        return new Kontoangabe($roh, null, null, null);
    }

    private function saldo(string $wert, string $tag, int $zeile): Saldo
    {
        if (preg_match('/^([CD])(\d{6})([A-Z]{3})(\d{1,15},\d{0,2})$/', trim($wert), $treffer) !== 1) {
            throw new Mt940Exception(sprintf('Zeile %d: Feld :%s: ist unlesbar.', $zeile, $tag));
        }

        $cent = $this->cent($treffer[4]);

        return new Saldo(
            $this->datum($treffer[2], $tag, $zeile),
            $treffer[1] === 'D' ? -$cent : $cent,
            $treffer[3],
            str_ends_with($tag, 'M'),
        );
    }

    private function umsatz(string $wert, int $zeile): Umsatz
    {
        $zeilen = explode("\n", $wert);
        $muster = '/^(\d{6})(\d{4})?(RC|RD|C|D)[A-Z]?(\d{1,15},\d{0,2})([NF][A-Z0-9]{3})((?:(?!\/\/).)*)(?:\/\/(.*))?$/';
        if (preg_match($muster, rtrim($zeilen[0]), $treffer) !== 1) {
            throw new Mt940Exception(sprintf('Zeile %d: Feld :61: ist unlesbar.', $zeile));
        }

        $valuta = $this->datum($treffer[1], '61', $zeile);
        $buchungsdatum = $treffer[2] !== '' ? $this->buchungsdatum($valuta, $treffer[2], $zeile) : $valuta;

        $cent = $this->cent($treffer[4]);
        // C and RD (a debit reversed) raise the balance, D and RC lower it.
        $vorzeichen = match ($treffer[3]) {
            'C', 'RD' => 1,
            'D', 'RC' => -1,
        };

        $bankreferenz = trim($treffer[7] ?? '');
        $zusatz = trim(implode(' ', array_map(trim(...), array_slice($zeilen, 1))));

        return new Umsatz(
            $valuta,
            $buchungsdatum,
            $vorzeichen * $cent,
            str_starts_with($treffer[3], 'R'),
            $treffer[5],
            trim($treffer[6]),
            $bankreferenz !== '' ? $bankreferenz : null,
            $zusatz !== '' ? $zusatz : null,
            null,
        );
    }

    /**
     * Attaches :86: to the transaction right before it. A :86: elsewhere
     * (information to the whole statement) is not needed and dropped.
     *
     * @param array{zeile: int, referenz: string, konto: ?Kontoangabe, nummer: ?string, anfang: ?Saldo, schluss: ?Saldo, umsaetze: list<Umsatz>, letzter: string} $offen
     */
    private function detailsAnhaengen(array &$offen, string $wert): void
    {
        if ($offen['letzter'] !== '61' || $offen['umsaetze'] === []) {
            return;
        }

        $letzter = array_key_last($offen['umsaetze']);
        $u = $offen['umsaetze'][$letzter];
        $offen['umsaetze'][$letzter] = new Umsatz(
            $u->valuta,
            $u->buchungsdatum,
            $u->cent,
            $u->storno,
            $u->buchungsschluessel,
            $u->kundenreferenz,
            $u->bankreferenz,
            $u->zusatz,
            $this->details($wert),
        );
    }

    private function details(string $wert): Umsatzdetails
    {
        $text = str_replace("\n", '', $wert);

        if (preg_match('/^(\d{3})([^0-9A-Za-z\s])\d{2}/', $text, $kopf) !== 1) {
            $klartext = trim((string) preg_replace('/\s*\n\s*/', ' ', $wert));

            return new Umsatzdetails(false, null, null, null, $klartext, $klartext, [], null, null, null, null);
        }

        $teile = preg_split('/' . preg_quote($kopf[2], '/') . '(\d{2})/', substr($text, 3), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $einzeln = [];
        $zweck = [];
        $name = [];
        for ($i = 1; $i + 1 < count($teile); $i += 2) {
            [$nummer, $inhalt] = [(int) $teile[$i], $teile[$i + 1]];
            match (true) {
                ($nummer >= 20 && $nummer <= 29) || ($nummer >= 60 && $nummer <= 63) => $zweck[] = $inhalt,
                $nummer === 32 || $nummer === 33 => $name[] = $inhalt,
                default => $einzeln[$nummer] = trim($inhalt),
            };
        }

        $sepa = $this->sepaSchluessel($zweck);
        $roh = trim($this->verbinde($zweck));
        $verwendungszweck = $sepa['SVWZ'] ?? ($sepa === [] ? $roh : trim($this->verbinde($this->vorSepaSchluessel($zweck))));
        $nameText = trim($this->verbinde($name));

        $einzelwert = static fn (int $nummer): ?string => ($einzeln[$nummer] ?? '') !== '' ? $einzeln[$nummer] : null;

        return new Umsatzdetails(
            true,
            $kopf[1],
            $einzelwert(0),
            $einzelwert(10),
            $verwendungszweck,
            $roh,
            $sepa,
            $einzelwert(30),
            $einzelwert(31),
            $nameText !== '' ? $nameText : null,
            $einzelwert(34),
        );
    }

    /**
     * @param list<string> $zweck purpose subfields in order
     *
     * @return array<string, string>
     */
    private function sepaSchluessel(array $zweck): array
    {
        $abschnitte = [];
        $aktuell = null;
        foreach ($zweck as $teil) {
            if (preg_match('/^(' . self::SEPA_SCHLUESSEL . ')\+/', $teil, $treffer) === 1) {
                $aktuell = $treffer[1];
                $abschnitte[] = [$aktuell, [$teil]];
            } elseif ($aktuell !== null) {
                $abschnitte[array_key_last($abschnitte)][1][] = $teil;
            }
        }

        $sepa = [];
        foreach ($abschnitte as [$schluessel, $teile]) {
            // Joined with the key still in front, so the first piece keeps
            // its real length for the 27-character rule.
            $wert = trim(substr($this->verbinde($teile), strlen($schluessel) + 1));
            $sepa[$schluessel] = isset($sepa[$schluessel]) ? $sepa[$schluessel] . ' ' . $wert : $wert;
        }

        return $sepa;
    }

    /**
     * @param list<string> $zweck
     *
     * @return list<string> the subfields before the first SEPA key
     */
    private function vorSepaSchluessel(array $zweck): array
    {
        $vorher = [];
        foreach ($zweck as $teil) {
            if (preg_match('/^(' . self::SEPA_SCHLUESSEL . ')\+/', $teil) === 1) {
                break;
            }
            $vorher[] = $teil;
        }

        return $vorher;
    }

    /**
     * @param list<string> $teile
     */
    private function verbinde(array $teile): string
    {
        $text = '';
        $vorher = null;
        foreach ($teile as $teil) {
            if ($vorher !== null && mb_strlen($vorher) < self::TEILFELD_LAENGE
                && !str_ends_with($text, ' ') && !str_starts_with($teil, ' ')) {
                $text .= ' ';
            }
            $text .= $teil;
            $vorher = $teil;
        }

        return $text;
    }

    private function cent(string $betrag): int
    {
        [$euro, $cent] = explode(',', $betrag);

        return (int) $euro * 100 + (int) str_pad($cent, 2, '0');
    }

    /** "YYMMDD"; a day past the month's end becomes the month's last day. */
    private function datum(string $jjmmtt, string $tag, int $zeile): \DateTimeImmutable
    {
        return $this->kalendertag(2000 + (int) substr($jjmmtt, 0, 2), (int) substr($jjmmtt, 2, 2), (int) substr($jjmmtt, 4, 2), $tag, $zeile);
    }

    /**
     * "MMDD" without a year: the year that puts it closest to the value
     * date, so a booking around New Year lands in the right year.
     */
    private function buchungsdatum(\DateTimeImmutable $valuta, string $mmtt, int $zeile): \DateTimeImmutable
    {
        $jahr = (int) $valuta->format('Y');
        $bester = null;
        foreach ([$jahr - 1, $jahr, $jahr + 1] as $kandidat) {
            $datum = $this->kalendertag($kandidat, (int) substr($mmtt, 0, 2), (int) substr($mmtt, 2, 2), '61', $zeile);
            $abstand = abs($datum->getTimestamp() - $valuta->getTimestamp());
            if ($bester === null || $abstand < $bester[0]) {
                $bester = [$abstand, $datum];
            }
        }

        return $bester[1];
    }

    private function kalendertag(int $jahr, int $monat, int $tagImMonat, string $tag, int $zeile): \DateTimeImmutable
    {
        if ($monat < 1 || $monat > 12 || $tagImMonat < 1 || $tagImMonat > 31) {
            throw new Mt940Exception(sprintf('Zeile %d: Feld :%s: enthält ein ungültiges Datum.', $zeile, $tag));
        }

        $ersterTag = new \DateTimeImmutable(sprintf('%04d-%02d-01', $jahr, $monat));

        return $ersterTag->setDate($jahr, $monat, min($tagImMonat, (int) $ersterTag->format('t')));
    }
}
