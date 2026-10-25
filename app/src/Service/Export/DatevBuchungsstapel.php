<?php

declare(strict_types=1);

namespace App\Service\Export;

/**
 * Writes a DATEV "Buchungsstapel" (DATEV-Format EXTF, Formatkategorie 21,
 * Formatversion 13) for the tax advisor (issue #93, E-49,
 * docs/spec/05-auswertung-und-export.md section 4). Pure: no database, no
 * session, no vault - the caller hands over decrypted rows and gets the
 * file's bytes back, built in memory.
 *
 * Format as DATEV publishes it on developer.datev.de ("DATEV-Format",
 * Formatbeschreibung Buchungsstapel):
 * - Windows-1252, semicolon, CRLF. A character Windows-1252 cannot hold
 *   becomes "?".
 * - Line 1: the header with 31 fields, line 2: the 125 column headings,
 *   then one line per booking with 125 fields.
 * - Text fields in double quotes, numbers and dates without. A double
 *   quote inside a text becomes "'", line breaks and tabs become spaces.
 * - Umsatz without sign, decimal comma, no thousands separator ("1234,56");
 *   the sign is the Soll/Haben-Kennzeichen, which refers to "Konto".
 * - Belegdatum as TTMM, the year comes from the header (Datum vom/bis lie in
 *   one calendar year = financial year, E-16).
 * - Belegfeld 1: at most 36 characters out of A-Z a-z 0-9 $ & % * + - /.
 * - Buchungstext: at most 60 characters.
 */
final class DatevBuchungsstapel
{
    public const int FORMATVERSION = 13;

    /** DATEV accepts at most this many bookings per batch. */
    public const int MAX_ZEILEN = 99_999;

    public const int BELEGFELD_MAX = 36;

    public const int TEXT_MAX = 60;

    public const int BEZEICHNUNG_MAX = 30;

    public const string ZEILENENDE = "\r\n";

    /**
     * Line 2, in this order - DATEV reads the fields by position
     * (Buchungsstapel, Formatversion 13: 125 columns).
     */
    public const array SPALTEN = [
        'Umsatz (ohne Soll/Haben-Kz)', 'Soll/Haben-Kennzeichen', 'WKZ Umsatz', 'Kurs', 'Basis-Umsatz',
        'WKZ Basis-Umsatz', 'Konto', 'Gegenkonto (ohne BU-Schlüssel)', 'BU-Schlüssel', 'Belegdatum',
        'Belegfeld 1', 'Belegfeld 2', 'Skonto', 'Buchungstext', 'Postensperre', 'Diverse Adressnummer',
        'Geschäftspartnerbank', 'Sachverhalt', 'Zinssperre', 'Beleglink',
        'Beleginfo - Art 1', 'Beleginfo - Inhalt 1', 'Beleginfo - Art 2', 'Beleginfo - Inhalt 2',
        'Beleginfo - Art 3', 'Beleginfo - Inhalt 3', 'Beleginfo - Art 4', 'Beleginfo - Inhalt 4',
        'Beleginfo - Art 5', 'Beleginfo - Inhalt 5', 'Beleginfo - Art 6', 'Beleginfo - Inhalt 6',
        'Beleginfo - Art 7', 'Beleginfo - Inhalt 7', 'Beleginfo - Art 8', 'Beleginfo - Inhalt 8',
        'KOST1 - Kostenstelle', 'KOST2 - Kostenstelle', 'Kost-Menge', 'EU-Land u. UStID (Bestimmung)',
        'EU-Steuersatz (Bestimmung)', 'Abw. Versteuerungsart', 'Sachverhalt L+L', 'Funktionsergänzung L+L',
        'BU 49 Hauptfunktionstyp', 'BU 49 Hauptfunktionsnummer', 'BU 49 Funktionsergänzung',
        'Zusatzinformation - Art 1', 'Zusatzinformation- Inhalt 1', 'Zusatzinformation - Art 2', 'Zusatzinformation- Inhalt 2',
        'Zusatzinformation - Art 3', 'Zusatzinformation- Inhalt 3', 'Zusatzinformation - Art 4', 'Zusatzinformation- Inhalt 4',
        'Zusatzinformation - Art 5', 'Zusatzinformation- Inhalt 5', 'Zusatzinformation - Art 6', 'Zusatzinformation- Inhalt 6',
        'Zusatzinformation - Art 7', 'Zusatzinformation- Inhalt 7', 'Zusatzinformation - Art 8', 'Zusatzinformation- Inhalt 8',
        'Zusatzinformation - Art 9', 'Zusatzinformation- Inhalt 9', 'Zusatzinformation - Art 10', 'Zusatzinformation- Inhalt 10',
        'Zusatzinformation - Art 11', 'Zusatzinformation- Inhalt 11', 'Zusatzinformation - Art 12', 'Zusatzinformation- Inhalt 12',
        'Zusatzinformation - Art 13', 'Zusatzinformation- Inhalt 13', 'Zusatzinformation - Art 14', 'Zusatzinformation- Inhalt 14',
        'Zusatzinformation - Art 15', 'Zusatzinformation- Inhalt 15', 'Zusatzinformation - Art 16', 'Zusatzinformation- Inhalt 16',
        'Zusatzinformation - Art 17', 'Zusatzinformation- Inhalt 17', 'Zusatzinformation - Art 18', 'Zusatzinformation- Inhalt 18',
        'Zusatzinformation - Art 19', 'Zusatzinformation- Inhalt 19', 'Zusatzinformation - Art 20', 'Zusatzinformation- Inhalt 20',
        'Stück', 'Gewicht', 'Zahlweise', 'Forderungsart', 'Veranlagungsjahr', 'Zugeordnete Fälligkeit', 'Skontotyp',
        'Auftragsnummer', 'Buchungstyp', 'USt-Schlüssel (Anzahlungen)', 'EU-Land (Anzahlungen)',
        'Sachverhalt L+L (Anzahlungen)', 'EU-Steuersatz (Anzahlungen)', 'Erlöskonto (Anzahlungen)', 'Herkunft-Kz',
        'Buchungs GUID', 'KOST-Datum', 'SEPA-Mandatsreferenz', 'Skontosperre', 'Gesellschaftername',
        'Beteiligtennummer', 'Identifikationsnummer', 'Zeichnernummer', 'Postensperre bis',
        'Bezeichnung SoBil-Sachverhalt', 'Kennzeichen SoBil-Buchung', 'Festschreibung', 'Leistungsdatum',
        'Datum Zuord. Steuerperiode', 'Fälligkeit', 'Generalumkehr (GU)', 'Steuersatz', 'Land',
        'Abrechnungsreferenz', 'BVV-Position', 'EU-Land u. UStID (Ursprung)', 'EU-Steuersatz (Ursprung)',
        'Abw. Skontokonto',
    ];

    private function __construct()
    {
    }

    /**
     * The file as DATEV reads it: Windows-1252 bytes.
     *
     * @param list<DatevZeile> $zeilen
     */
    public static function datei(DatevKopf $kopf, array $zeilen): string
    {
        return mb_convert_encoding(self::text($kopf, $zeilen), 'Windows-1252', 'UTF-8');
    }

    /**
     * The same as UTF-8 text - what datei() encodes, for the tests.
     *
     * @param list<DatevZeile> $zeilen
     */
    public static function text(DatevKopf $kopf, array $zeilen): string
    {
        if (count($zeilen) > self::MAX_ZEILEN) {
            throw new \LengthException('A DATEV Buchungsstapel holds at most ' . self::MAX_ZEILEN . ' bookings.');
        }

        $text = self::kopfzeile($kopf) . self::ZEILENENDE . implode(';', self::SPALTEN) . self::ZEILENENDE;
        foreach ($zeilen as $zeile) {
            $text .= self::zeile($zeile) . self::ZEILENENDE;
        }

        return $text;
    }

    /** Line 1: the 31 header fields. */
    public static function kopfzeile(DatevKopf $kopf): string
    {
        return implode(';', [
            self::textfeld('EXTF'),                     // 1 Kennzeichen
            '700',                                      // 2 Versionsnummer
            '21',                                       // 3 Formatkategorie
            self::textfeld('Buchungsstapel'),           // 4 Formatname
            (string) self::FORMATVERSION,               // 5 Formatversion
            $kopf->erzeugt->format('YmdHisv'),          // 6 Erzeugt am
            '',                                         // 7 Importiert
            self::textfeld('RE'),                       // 8 Herkunft
            self::textfeld(''),                         // 9 Exportiert von - no name of a person
            self::textfeld(''),                         // 10 Importiert von
            $kopf->beraternummer,                       // 11 Beraternummer
            $kopf->mandantennummer,                     // 12 Mandantennummer
            $kopf->von->format('Y') . '0101',           // 13 WJ-Beginn (calendar year, E-16)
            (string) $kopf->sachkontenlaenge,           // 14 Sachkontenlänge
            $kopf->von->format('Ymd'),                  // 15 Datum vom
            $kopf->bis->format('Ymd'),                  // 16 Datum bis
            self::textfeld(self::bereinigt($kopf->bezeichnung, self::BEZEICHNUNG_MAX)), // 17 Bezeichnung
            self::textfeld(''),                         // 18 Diktatkürzel
            '1',                                        // 19 Buchungstyp: Finanzbuchführung
            '0',                                        // 20 Rechnungslegungszweck
            '0',                                        // 21 Festschreibung: no - the advisor reviews first
            self::textfeld('EUR'),                      // 22 WKZ
            '',                                         // 23 reserviert
            self::textfeld(''),                         // 24 Derivatskennzeichen
            '',                                         // 25 reserviert
            '',                                         // 26 reserviert
            self::textfeld($kopf->kontenrahmen),        // 27 Sachkontenrahmen
            '',                                         // 28 ID der Branchenlösung
            '',                                         // 29 reserviert
            self::textfeld(''),                         // 30 reserviert
            self::textfeld(''),                         // 31 Anwendungsinformation
        ]);
    }

    /** One booking: 125 fields, only the first fourteen can be filled. */
    public static function zeile(DatevZeile $zeile): string
    {
        $felder = array_fill(0, count(self::SPALTEN), '');
        $felder[0] = self::betrag($zeile->betragCent);
        $felder[1] = self::textfeld($zeile->betragCent < 0 ? 'H' : 'S');
        $felder[2] = self::textfeld('EUR');
        $felder[6] = $zeile->konto;
        $felder[7] = $zeile->gegenkonto;
        $felder[9] = $zeile->belegdatum->format('dm');
        $felder[10] = self::textfeld(self::belegfeld($zeile->belegfeld1));
        $felder[13] = self::textfeld(self::bereinigt($zeile->buchungstext, self::TEXT_MAX));

        return implode(';', $felder);
    }

    /**
     * The amount without sign, two decimals, decimal comma, no thousands
     * separator - integer arithmetic, never float.
     */
    public static function betrag(int $cent): string
    {
        $betrag = abs($cent);

        return intdiv($betrag, 100) . ',' . str_pad((string) ($betrag % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Belegfeld 1 keeps only what DATEV allows there; of a longer value the
     * end is kept, where invoice numbers usually differ.
     */
    public static function belegfeld(string $wert): string
    {
        $erlaubt = (string) preg_replace('~[^A-Za-z0-9$&%*+\-/]~', '', $wert);

        return strlen($erlaubt) > self::BELEGFELD_MAX ? substr($erlaubt, -self::BELEGFELD_MAX) : $erlaubt;
    }

    /** A text for a quoted field: no quote, no control character, at most $max characters. */
    public static function bereinigt(string $wert, int $max): string
    {
        $wert = str_replace('"', "'", mb_scrub($wert, 'UTF-8'));
        $wert = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $wert);
        $wert = trim((string) preg_replace('/ {2,}/', ' ', $wert));

        return rtrim(mb_substr($wert, 0, $max));
    }

    private static function textfeld(string $wert): string
    {
        return '"' . $wert . '"';
    }
}
