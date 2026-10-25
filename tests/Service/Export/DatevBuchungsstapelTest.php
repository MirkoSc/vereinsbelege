<?php

declare(strict_types=1);

namespace App\Tests\Service\Export;

use App\Service\Export\DatevBuchungsstapel;
use App\Service\Export\DatevKopf;
use App\Service\Export\DatevZeile;
use PHPUnit\Framework\TestCase;

/**
 * The DATEV Buchungsstapel writer (issue #93, E-49) against the public
 * DATEV format description (developer.datev.de, "DATEV-Format",
 * Buchungsstapel Formatversion 13): header with 31 fields, 125 column
 * headings, field rules per booking, Windows-1252, CRLF. A real import into
 * DATEV cannot run here - E-49 lists what stays open.
 */
final class DatevBuchungsstapelTest extends TestCase
{
    public function testTheHeaderLineFollowsTheFormatDescription(): void
    {
        $felder = self::felder(DatevBuchungsstapel::kopfzeile(self::kopf()));

        self::assertCount(31, $felder);
        self::assertSame('"EXTF"', $felder[0]);
        self::assertSame('700', $felder[1]);
        self::assertSame('21', $felder[2]);
        self::assertSame('"Buchungsstapel"', $felder[3]);
        self::assertSame('13', $felder[4]);
        self::assertSame('20260315143005123', $felder[5], 'Erzeugt am: JJJJMMTTHHMMSSFFF');
        self::assertSame('"RE"', $felder[7]);
        self::assertSame('""', $felder[8], 'Exportiert von: no name of a person');
        self::assertSame('29098', $felder[10]);
        self::assertSame('55003', $felder[11]);
        self::assertSame('20260101', $felder[12], 'WJ-Beginn = 1 January (E-16)');
        self::assertSame('4', $felder[13]);
        self::assertSame('20260101', $felder[14]);
        self::assertSame('20260331', $felder[15]);
        self::assertSame('"Vereinsbelege"', $felder[16]);
        self::assertSame('1', $felder[18], 'Buchungstyp Finanzbuchführung');
        self::assertSame('0', $felder[20], 'not festgeschrieben');
        self::assertSame('"EUR"', $felder[21]);
        self::assertSame('"49"', $felder[26]);
        self::assertTrue(self::kopfIstGueltig($felder));
    }

    public function testTheSecondLineHoldsTheHeadingsOfFormatVersion13(): void
    {
        $zeilen = explode("\r\n", DatevBuchungsstapel::text(self::kopf(), []));
        $spalten = explode(';', $zeilen[1]);

        self::assertCount(125, $spalten);
        self::assertSame('Umsatz (ohne Soll/Haben-Kz)', $spalten[0]);
        self::assertSame('Konto', $spalten[6]);
        self::assertSame('Gegenkonto (ohne BU-Schlüssel)', $spalten[7]);
        self::assertSame('Belegdatum', $spalten[9]);
        self::assertSame('Belegfeld 1', $spalten[10]);
        self::assertSame('Buchungstext', $spalten[13]);
        self::assertSame('Festschreibung', $spalten[113]);
        self::assertSame('Abw. Skontokonto', $spalten[124]);
        self::assertSame('', $zeilen[2], 'no bookings: the file ends after the headings');
    }

    public function testABookingIsWrittenFieldByField(): void
    {
        $einnahme = self::felder(DatevBuchungsstapel::zeile(new DatevZeile(123456, '1800', '2110', new \DateTimeImmutable('2026-03-05'), 'R-2026/0042', 'Mitgliedsbeiträge')));
        $ausgabe = self::felder(DatevBuchungsstapel::zeile(new DatevZeile(-1999, '1000', '6815', new \DateTimeImmutable('2026-12-31'), '', 'Büro & Verwaltung')));

        self::assertCount(125, $einnahme);
        self::assertSame(['1234,56', '"S"', '"EUR"', '', '', '', '1800', '2110', '', '0503', '"R-2026/0042"', '', '', '"Mitgliedsbeiträge"'], array_slice($einnahme, 0, 14));
        self::assertSame(['19,99', '"H"', '"EUR"', '', '', '', '1000', '6815', '', '3112', '""', '', '', '"Büro & Verwaltung"'], array_slice($ausgabe, 0, 14));
        self::assertSame(array_fill(0, 111, ''), array_slice($ausgabe, 14), 'every other field stays empty');
        self::assertTrue(self::buchungIstGueltig($einnahme, 4));
        self::assertTrue(self::buchungIstGueltig($ausgabe, 4));
    }

    public function testAmountsAreIntegerCentsWithADecimalComma(): void
    {
        self::assertSame('0,01', DatevBuchungsstapel::betrag(1));
        self::assertSame('0,10', DatevBuchungsstapel::betrag(-10));
        self::assertSame('12345678,90', DatevBuchungsstapel::betrag(1234567890));
        self::assertSame('1000,00', DatevBuchungsstapel::betrag(-100000), 'no thousands separator, no sign');
    }

    public function testBelegfeld1KeepsOnlyWhatDatevAllows(): void
    {
        self::assertSame('RE-2026/17', DatevBuchungsstapel::belegfeld('RE-2026/17'));
        self::assertSame('Nr12345', DatevBuchungsstapel::belegfeld('Nr. 12 345'));
        self::assertSame('ABmmerung', DatevBuchungsstapel::belegfeld('AB_ü"mmerung;'), 'underscore, umlaut, quote, semicolon go');
        self::assertSame('$&%*+-/', DatevBuchungsstapel::belegfeld('$&%*+-/'));
        $lang = str_repeat('A', 30) . '0123456789';
        self::assertSame(substr($lang, -36), DatevBuchungsstapel::belegfeld($lang), 'at most 36, the end is kept');
    }

    public function testTextsCannotBreakTheFile(): void
    {
        $felder = self::felder(DatevBuchungsstapel::zeile(new DatevZeile(500, '1800', '2110', new \DateTimeImmutable('2026-01-02'), '', "Sag \"Hallo\";\r\nWelt\tund mehr " . str_repeat('x', 80))));

        self::assertCount(125, $felder, 'the semicolon stays inside the quoted field');
        self::assertStringStartsWith('"Sag \'Hallo\'; Welt und mehr x', $felder[13]);
        self::assertSame(60, mb_strlen(trim($felder[13], '"')));
    }

    public function testTheFileIsWindows1252WithCrlf(): void
    {
        $datei = DatevBuchungsstapel::datei(self::kopf(), [new DatevZeile(-2500, '1000', '4980', new \DateTimeImmutable('2026-02-01'), '', 'Büro – Grüße €10 ☃')]);

        self::assertStringNotContainsString('ü', $datei, 'not UTF-8');
        self::assertStringNotContainsString("\xEF\xBB\xBF", $datei, 'no BOM');
        self::assertStringContainsString("\"B\xFCro \x96 Gr\xFC\xDFe \x8010 ?\"", $datei, 'umlaut, dash and euro in CP1252, the snowman as "?"');
        self::assertStringContainsString("Gegenkonto (ohne BU-Schl\xFCssel)", $datei);
        self::assertSame(3, substr_count($datei, "\r\n"));
        self::assertSame(0, preg_match("/(?<!\r)\n/", $datei), 'no bare LF');
        self::assertStringEndsWith("\r\n", $datei);
    }

    public function testTheHeaderTakesTheDescriptionAndChartAsGiven(): void
    {
        $kopf = new DatevKopf('1001', '1', 6, '', new \DateTimeImmutable('2027-04-01'), new \DateTimeImmutable('2027-04-30'), new \DateTimeImmutable('2027-05-02 08:00:00'), str_repeat('Lang', 10));
        $felder = self::felder(DatevBuchungsstapel::kopfzeile($kopf));

        self::assertSame('20270101', $felder[12]);
        self::assertSame('6', $felder[13]);
        self::assertSame('""', $felder[26]);
        self::assertSame(30, strlen(trim($felder[16], '"')));
        self::assertTrue(self::kopfIstGueltig($felder));
    }

    public function testMoreBookingsThanDatevTakesAreRefused(): void
    {
        $zeile = new DatevZeile(100, '1800', '2110', new \DateTimeImmutable('2026-01-02'), '', 'x');

        $this->expectException(\LengthException::class);
        DatevBuchungsstapel::text(self::kopf(), array_fill(0, DatevBuchungsstapel::MAX_ZEILEN + 1, $zeile));
    }

    private static function kopf(): DatevKopf
    {
        return new DatevKopf('29098', '55003', 4, '49', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-03-31'), new \DateTimeImmutable('2026-03-15 14:30:05.123'));
    }

    /**
     * Splits a line at the semicolons outside quotes; the quotes stay, so
     * the tests see which fields are text.
     *
     * @return list<string>
     */
    private static function felder(string $zeile): array
    {
        $felder = [''];
        $inText = false;
        foreach (str_split($zeile) as $zeichen) {
            if ($zeichen === '"') {
                $inText = !$inText;
            }
            if ($zeichen === ';' && !$inText) {
                $felder[] = '';
                continue;
            }
            $felder[count($felder) - 1] .= $zeichen;
        }

        return $felder;
    }

    /**
     * The header rules of the format description.
     *
     * @param list<string> $f
     */
    private static function kopfIstGueltig(array $f): bool
    {
        $text = static fn(int $i): bool => preg_match('/^"[^"]*"$/', $f[$i]) === 1;
        foreach ([0, 3, 7, 8, 9, 16, 17, 21, 26, 30] as $i) {
            if (!$text($i)) {
                return false;
            }
        }

        return count($f) === 31
            && preg_match('/^20\d{15}$/', $f[5]) === 1
            && preg_match('/^\d{4,7}$/', $f[10]) === 1 && (int) $f[10] >= 1001
            && preg_match('/^\d{1,5}$/', $f[11]) === 1 && (int) $f[11] >= 1
            && preg_match('/^20\d{2}0101$/', $f[12]) === 1
            && preg_match('/^[4-8]$/', $f[13]) === 1
            && preg_match('/^20\d{6}$/', $f[14]) === 1 && preg_match('/^20\d{6}$/', $f[15]) === 1
            && substr($f[14], 0, 4) === substr($f[15], 0, 4) && $f[14] <= $f[15]
            && in_array($f[20], ['', '0', '1'], true)
            && mb_strlen(trim($f[16], '"')) <= 30
            && preg_match('/^"(\d{2})?"$/', $f[26]) === 1;
    }

    /**
     * The field rules of a booking line.
     *
     * @param list<string> $f
     */
    private static function buchungIstGueltig(array $f, int $sachkontenlaenge): bool
    {
        $konto = static fn(string $wert): bool => preg_match('/^\d{1,9}$/', $wert) === 1 && (int) $wert > 0 && strlen($wert) <= $sachkontenlaenge + 1;

        return count($f) === 125
            && preg_match('/^\d{1,10},\d{2}$/', $f[0]) === 1 && $f[0] !== '0,00'
            && in_array($f[1], ['"S"', '"H"'], true)
            && preg_match('/^"[A-Z]{3}"$/', $f[2]) === 1
            && $konto($f[6]) && $konto($f[7])
            && preg_match('/^(0[1-9]|[12]\d|3[01])(0[1-9]|1[0-2])$/', $f[9]) === 1
            && preg_match('~^"[A-Za-z0-9$&%*+\-/]{0,36}"$~', $f[10]) === 1
            && preg_match('/^"[^"\r\n\t]{0,60}"$/u', $f[13]) === 1;
    }
}
