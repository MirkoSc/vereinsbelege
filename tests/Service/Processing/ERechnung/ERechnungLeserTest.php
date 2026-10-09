<?php

declare(strict_types=1);

namespace App\Tests\Service\Processing\ERechnung;

use App\Domain\InvoiceType;
use App\Service\Processing\ERechnung\ERechnung;
use App\Service\Processing\ERechnung\ERechnungBefund;
use App\Service\Processing\ERechnung\ERechnungLeser;
use App\Service\Processing\ERechnung\ERechnungSyntax;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Structured e-invoices read without AI (issue #46/M7-4,
 * docs/spec/03-erfassung-und-ki.md section 3): XRechnung in CII and UBL
 * syntax, credit notes, the fields the review page takes over - and never a
 * guess, an exception or a libxml warning for XML that is broken, hostile
 * or simply no invoice.
 */
final class ERechnungLeserTest extends TestCase
{
    public const string FIXTURES = __DIR__ . '/../../../fixtures/erechnung';

    public function testXRechnungInCiiSyntax(): void
    {
        $rechnung = self::gelesen(self::fixture('xrechnung-cii.xml'));

        self::assertSame(ERechnungSyntax::Cii, $rechnung->syntax);
        self::assertSame('urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0', $rechnung->profil);
        self::assertSame(InvoiceType::Rechnung, $rechnung->typ);
        self::assertSame('RE-2026-0042', $rechnung->nummer);
        self::assertSame('2026-09-15', $rechnung->datum);
        self::assertSame('2026-10-15', $rechnung->faellig);
        self::assertSame('2026-09-01', $rechnung->leistungVon);
        self::assertSame('2026-09-30', $rechnung->leistungBis);
        self::assertSame('EUR', $rechnung->waehrung);
        self::assertSame(17250, $rechnung->brutto);
        self::assertSame(15000, $rechnung->netto);
        self::assertSame([['rate' => '19', 'amount' => 1900], ['rate' => '7', 'amount' => 350]], $rechnung->steuern);
        self::assertSame('Muster Sportartikel GmbH', $rechnung->lieferantName);
        self::assertSame('Musterstraße 1, 12345 Musterstadt, DE', $rechnung->lieferantAnschrift);
        self::assertSame('DE123456789', $rechnung->ustId);
        self::assertSame('12/345/67890', $rechnung->steuernummer);
        self::assertSame('rechnung@example.org', $rechnung->email);
        self::assertSame('DE02120300000000202051', $rechnung->iban, 'spaces of the printed form removed');
        self::assertSame('BYLADEM1001', $rechnung->bic);
        self::assertSame('KD-4711', $rechnung->kundennummer);
        self::assertSame('V-2026-07', $rechnung->vertragsnummer);
        self::assertSame('ueberweisung', $rechnung->zahlart);
        self::assertFalse($rechnung->bezahlt);
        self::assertSame([], $rechnung->warnungen);
    }

    public function testXRechnungInUblSyntax(): void
    {
        $rechnung = self::gelesen(self::fixture('xrechnung-ubl.xml'));

        self::assertSame(ERechnungSyntax::Ubl, $rechnung->syntax);
        self::assertSame(InvoiceType::Rechnung, $rechnung->typ);
        self::assertSame('2026/1234', $rechnung->nummer);
        self::assertSame('2026-09-20', $rechnung->datum);
        self::assertSame('2026-10-04', $rechnung->faellig);
        self::assertSame('2026-09-18', $rechnung->leistungVon, 'the delivery date stands in for a missing period');
        self::assertSame('2026-09-18', $rechnung->leistungBis);
        self::assertSame(29750, $rechnung->brutto);
        self::assertSame(25000, $rechnung->netto);
        self::assertSame([['rate' => '19', 'amount' => 4750]], $rechnung->steuern);
        self::assertSame('Hallenservice Beispiel e. K.', $rechnung->lieferantName);
        self::assertSame('Am Beispielhof 7, 98765 Exempelheim, DE', $rechnung->lieferantAnschrift);
        self::assertSame('DE987654321', $rechnung->ustId);
        self::assertSame('', $rechnung->steuernummer);
        self::assertSame('buchhaltung@example.net', $rechnung->email, 'the contact address before the electronic address');
        self::assertSame('DE02500105170137075030', $rechnung->iban);
        self::assertSame('INGDDEFFXXX', $rechnung->bic);
        self::assertSame('10077', $rechnung->kundennummer);
        self::assertSame('HALLE-2026', $rechnung->vertragsnummer);
    }

    public function testACreditNoteInTheDefaultNamespace(): void
    {
        $rechnung = self::gelesen(self::fixture('gutschrift-ubl.xml'));

        self::assertSame(ERechnungSyntax::Ubl, $rechnung->syntax);
        self::assertSame(InvoiceType::Gutschrift, $rechnung->typ);
        self::assertSame('GS-77', $rechnung->nummer);
        self::assertSame('2026-10-01', $rechnung->faellig, 'the due date of the payment means');
        self::assertNull($rechnung->leistungVon);
        self::assertSame('Muster Sportartikel GmbH', $rechnung->lieferantName, 'registration name without a trading name');
        self::assertSame([['rate' => '19', 'amount' => 190]], $rechnung->steuern, 'the tax total without subtotals is ignored');
        self::assertTrue($rechnung->bezahlt, 'prepaid in full');
        self::assertSame('ueberweisung', $rechnung->zahlart);
    }

    public function testACiiCreditNoteByItsTypeCode(): void
    {
        $xml = str_replace('<ram:TypeCode>380</ram:TypeCode>', '<ram:TypeCode>381</ram:TypeCode>', self::fixture('xrechnung-cii.xml'));

        self::assertSame(InvoiceType::Gutschrift, self::gelesen($xml)->typ);
    }

    public function testTheExtractV1Shape(): void
    {
        $extraktion = self::gelesen(self::fixture('xrechnung-cii.xml'))->alsExtraktion();

        self::assertSame('rechnung', $extraktion['document_type']);
        self::assertSame('ausgabe', $extraktion['direction']);
        self::assertSame('172.50', $extraktion['total_gross']);
        self::assertSame('150.00', $extraktion['total_net']);
        self::assertSame([['rate' => '19', 'amount' => '19.00'], ['rate' => '7', 'amount' => '3.50']], $extraktion['taxes']);
        self::assertSame(['from' => '2026-09-01', 'to' => '2026-09-30'], $extraktion['service_period']);
        self::assertSame('DE123456789', $extraktion['supplier']['vat_id']);
        self::assertSame('DE02120300000000202051', $extraktion['supplier']['iban']);
        self::assertSame('', $extraktion['purpose_short'], 'left to the AI');
        self::assertNull($extraktion['category_id']);
        self::assertSame(1.0, $extraktion['confidence']['total_gross']);
        self::assertSame(
            ['document_type', 'direction', 'supplier', 'invoice_number', 'customer_number', 'contract_number', 'invoice_date',
                'due_date', 'service_period', 'currency', 'total_gross', 'total_net', 'taxes', 'payment', 'purpose_short',
                'category_id', 'sphere', 'cost_center_id', 'recurring', 'confidence', 'warnings'],
            array_keys($extraktion),
        );
    }

    public function testASumThatDoesNotAddUpIsReadWithAWarning(): void
    {
        $xml = str_replace('<ram:GrandTotalAmount>172.50</ram:GrandTotalAmount>', '<ram:GrandTotalAmount>180.00</ram:GrandTotalAmount>', self::fixture('xrechnung-cii.xml'));

        $rechnung = self::gelesen($xml);

        self::assertSame(18000, $rechnung->brutto, 'taken as written - the person decides');
        self::assertSame(['Netto und Steuern ergeben laut E-Rechnung nicht den Bruttobetrag.'], $rechnung->warnungen);
    }

    public function testAmountsAreCentsNeverFloats(): void
    {
        self::assertSame(12345, ERechnungLeser::cent('123.45'));
        self::assertSame(1200, ERechnungLeser::cent('12'));
        self::assertSame(-550, ERechnungLeser::cent('-5.5'));
        self::assertSame(1990, ERechnungLeser::cent('19.9000'));
        self::assertSame(1, ERechnungLeser::cent('0.005', $gerundet));
        self::assertTrue($gerundet);
        self::assertSame(-1, ERechnungLeser::cent('-0.0050'));
        self::assertNull(ERechnungLeser::cent('1,50'), 'a comma is no XML decimal');
        self::assertNull(ERechnungLeser::cent('1.234.56'));
        self::assertNull(ERechnungLeser::cent('1e3'));
        self::assertNull(ERechnungLeser::cent(''));
        self::assertNull(ERechnungLeser::cent('9999999999999'));
    }

    public function testAnAmountBelowACentIsRoundedWithAWarning(): void
    {
        $xml = str_replace('<ram:CalculatedAmount>3.50</ram:CalculatedAmount>', '<ram:CalculatedAmount>3.4951</ram:CalculatedAmount>', self::fixture('xrechnung-cii.xml'));

        $rechnung = self::gelesen($xml);

        self::assertSame(350, $rechnung->steuern[1]['amount']);
        self::assertContains('Ein Betrag der E-Rechnung hat mehr als zwei Nachkommastellen und wurde auf Cent gerundet.', $rechnung->warnungen);
    }

    public function testAnIncompleteTaxLineIsLeftOutWithAWarning(): void
    {
        $xml = str_replace('<ram:RateApplicablePercent>7.00</ram:RateApplicablePercent>', '', self::fixture('xrechnung-cii.xml'));

        $rechnung = self::gelesen($xml);

        self::assertSame([['rate' => '19', 'amount' => 1900]], $rechnung->steuern);
        self::assertContains('Eine Steuerzeile der E-Rechnung ist unvollständig und wurde nicht übernommen.', $rechnung->warnungen);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pflichtfelder(): iterable
    {
        yield 'no number' => ['<ram:ID>RE-2026-0042</ram:ID>', ''];
        yield 'no date' => ['<udt:DateTimeString format="102">20260915</udt:DateTimeString>', ''];
        yield 'a date that is no day' => ['<udt:DateTimeString format="102">20260915</udt:DateTimeString>', '<udt:DateTimeString format="102">20260231</udt:DateTimeString>'];
        yield 'a month instead of a day' => ['<udt:DateTimeString format="102">20260915</udt:DateTimeString>', '<udt:DateTimeString format="610">202609</udt:DateTimeString>'];
        yield 'no currency' => ['<ram:InvoiceCurrencyCode>EUR</ram:InvoiceCurrencyCode>', ''];
        yield 'no gross amount' => ['<ram:GrandTotalAmount>172.50</ram:GrandTotalAmount>', ''];
        yield 'a gross amount that is none' => ['<ram:GrandTotalAmount>172.50</ram:GrandTotalAmount>', '<ram:GrandTotalAmount>viel</ram:GrandTotalAmount>'];
    }

    /**
     * Never a guess: without these there is nothing to take over.
     */
    #[DataProvider('pflichtfelder')]
    public function testAMissingMandatoryFieldIsDamagedNotGuessed(string $suche, string $ersatz): void
    {
        $xml = str_replace($suche, $ersatz, self::fixture('xrechnung-cii.xml'));

        $ergebnis = ERechnungLeser::lesen($xml);

        self::assertSame(ERechnungBefund::Defekt, $ergebnis->befund);
        self::assertNull($ergebnis->rechnung);
    }

    public function testZugferd1IsNotSupported(): void
    {
        self::assertSame(ERechnungBefund::NichtUnterstuetzt, ERechnungLeser::lesen(self::fixture('zugferd-1.xml'))->befund);
    }

    public function testXmlThatIsNoInvoice(): void
    {
        self::assertSame(ERechnungBefund::Keine, ERechnungLeser::lesen('<?xml version="1.0"?><rechnung><nr>1</nr></rechnung>')->befund);
        self::assertSame(ERechnungBefund::Keine, ERechnungLeser::lesen('<svg xmlns="http://www.w3.org/2000/svg"/>')->befund);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function kaputt(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ["  \n"];
        yield 'not XML' => ['%PDF-1.7 nonsense'];
        yield 'truncated' => [substr(self::fixture('xrechnung-cii.xml'), 0, 2000)];
        yield 'not UTF-8' => ["<?xml version=\"1.0\" encoding=\"UTF-8\"?><a>\xFF\xFE</a>"];
    }

    #[DataProvider('kaputt')]
    public function testBrokenXmlIsDamagedWithoutAWarning(string $xml): void
    {
        self::assertSame(ERechnungBefund::Defekt, ERechnungLeser::lesen($xml)->befund);
        self::assertSame([], libxml_get_errors(), 'no libxml error left behind');
    }

    /**
     * Entity expansion (billion laughs) and external entities (XXE) need a
     * DOCTYPE - no e-invoice has one, so none gets near libxml.
     */
    public function testADoctypeIsRefusedBeforeParsing(): void
    {
        $datei = tempnam(sys_get_temp_dir(), 'xxe');
        self::assertIsString($datei);
        file_put_contents($datei, 'GEHEIM');
        try {
            $xxe = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file://' . $datei . '">]>'
                . str_replace('<ram:ID>RE-2026-0042</ram:ID>', '<ram:ID>&x;</ram:ID>', (string) preg_replace('/^<\?xml[^>]*>/', '', self::fixture('xrechnung-cii.xml')));
            $lol = '<?xml version="1.0"?><!doctype lolz [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">]><lolz>&b;</lolz>';

            self::assertSame(ERechnungBefund::Defekt, ERechnungLeser::lesen($xxe)->befund);
            self::assertSame(ERechnungBefund::Defekt, ERechnungLeser::lesen($lol)->befund);
            self::assertNull(ERechnungLeser::wurzel($xxe));
        } finally {
            unlink($datei);
        }
    }

    public function testTooLarge(): void
    {
        self::assertSame(ERechnungBefund::ZuGross, ERechnungLeser::lesen(str_repeat(' ', ERechnungLeser::MAX_BYTES + 1))->befund);
    }

    public function testTheRootOfTheFirstBytesNamesTheSyntax(): void
    {
        self::assertSame(ERechnungSyntax::Cii, ERechnungLeser::wurzel(substr(self::fixture('xrechnung-cii.xml'), 0, 2000)), 'a truncated file is enough');
        self::assertSame(ERechnungSyntax::Ubl, ERechnungLeser::wurzel(self::fixture('xrechnung-ubl.xml')));
        self::assertSame(ERechnungSyntax::Ubl, ERechnungLeser::wurzel("\xEF\xBB\xBF" . self::fixture('gutschrift-ubl.xml')), 'with byte order mark');
        self::assertNull(ERechnungLeser::wurzel(self::fixture('zugferd-1.xml')));
        self::assertNull(ERechnungLeser::wurzel('<html><body>Hallo</body></html>'));
        self::assertNull(ERechnungLeser::wurzel('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'));
        self::assertNull(ERechnungLeser::wurzel('<?xml version="1.0"?><Invoice/>'), 'the namespace decides, not the name');
        self::assertNull(ERechnungLeser::wurzel(''));
        self::assertNull(ERechnungLeser::wurzel("\xFF\xD8\xFF\xE0"));
        self::assertSame([], libxml_get_errors());
    }

    public function testDecimalStrings(): void
    {
        self::assertSame('123.45', ERechnung::dezimal(12345));
        self::assertSame('0.05', ERechnung::dezimal(5));
        self::assertSame('-0.05', ERechnung::dezimal(-5));
        self::assertSame('1000.00', ERechnung::dezimal(100000));
    }

    public static function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . '/' . $name);
    }

    private static function gelesen(string $xml): ERechnung
    {
        $ergebnis = ERechnungLeser::lesen($xml);
        self::assertSame(ERechnungBefund::Gelesen, $ergebnis->befund);
        self::assertNotNull($ergebnis->rechnung);

        return $ergebnis->rechnung;
    }
}
