<?php

declare(strict_types=1);

namespace App\Tests\Service\Bank;

use App\Service\Bank\GiroCode;
use App\Support\QrCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * App\Service\Bank\GiroCode (issue #86, EPC069-12 version 002, E-40). Test
 * data only: a well-known example IBAN, made-up names.
 */
final class GiroCodeTest extends TestCase
{
    private const string IBAN = 'DE89370400440532013000';

    public function testThePayloadIsExactlyTheEpcFormat(): void
    {
        $code = GiroCode::erstellen('Müller & Söhne Sportartikel GmbH', 'DE89 3704 0044 0532 0130 00', 'cobadeffxxx', 123456, 'RE-2026-0042');

        self::assertSame(
            "BCD\n002\n1\nSCT\nCOBADEFFXXX\nMüller & Söhne Sportartikel GmbH\nDE89370400440532013000\nEUR1234.56\n\n\nRE-2026-0042",
            $code->payload(),
        );
        // The same payload the reference encoder rendered and zxing-cpp
        // decoded back (tests/Support/QrCodeTest::referenzMatrizen()).
        self::assertSame(
            '094c4b759356708144c402db4ef85426700374edb3708e8aaa8739aa1e687b96',
            hash('sha256', implode("\n", array_map(
                static fn(array $row): string => implode('', array_map(static fn(bool $v): string => $v ? '1' : '0', $row)),
                QrCode::matrix($code->payload()),
            ))),
        );
    }

    public function testWithoutBicAndInvoiceNumberTheTrailingLinesAreLeftOut(): void
    {
        $code = GiroCode::erstellen('Sporthaus', self::IBAN, '', 5000, '');

        self::assertSame("BCD\n002\n1\nSCT\n\nSporthaus\n" . self::IBAN . "\nEUR50.00", $code->payload());
    }

    public function testAMalformedBicIsLeftOutRatherThanRefused(): void
    {
        self::assertSame('', GiroCode::erstellen('Sporthaus', self::IBAN, 'COBA DE', 100, '')->bic);
        self::assertSame('COBADEFF', GiroCode::erstellen('Sporthaus', self::IBAN, ' cobadeff ', 100, '')->bic);
    }

    #[DataProvider('betraege')]
    public function testTheAmountIsWrittenWithADecimalPointAndTwoDecimals(int $cent, string $erwartet): void
    {
        self::assertSame($erwartet, GiroCode::betrag($cent));
        self::assertStringContainsString("\nEUR" . $erwartet, GiroCode::erstellen('Sporthaus', self::IBAN, '', $cent, '')->payload());
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function betraege(): iterable
    {
        yield 'ein Cent' => [1, '0.01'];
        yield 'zehn Cent' => [10, '0.10'];
        yield 'ein Euro' => [100, '1.00'];
        yield 'ohne Tausenderpunkt' => [123456789, '1234567.89'];
        yield 'Höchstbetrag' => [GiroCode::BETRAG_MAX, '999999999.99'];
    }

    #[DataProvider('ungueltig')]
    public function testWhatWouldTransferWronglyIsRefused(string $name, string $iban, int $cent, string $meldung): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($meldung);

        GiroCode::erstellen($name, $iban, '', $cent, '');
    }

    /**
     * @return iterable<string, array{string, string, int, string}>
     */
    public static function ungueltig(): iterable
    {
        yield 'Prüfziffer falsch' => ['Sporthaus', 'DE88370400440532013000', 100, 'IBAN'];
        yield 'zu kurz' => ['Sporthaus', 'DE8937040044053201300', 100, 'IBAN'];
        yield 'keine IBAN' => ['Sporthaus', '', 100, 'IBAN'];
        yield 'nichts offen' => ['Sporthaus', self::IBAN, 0, 'Betrag'];
        yield 'negativ' => ['Sporthaus', self::IBAN, -500, 'Betrag'];
        yield 'zu groß' => ['Sporthaus', self::IBAN, GiroCode::BETRAG_MAX + 1, 'Betrag'];
        yield 'ohne Namen' => [" \n\t ", self::IBAN, 100, 'Name'];
    }

    public function testNameAndRemittanceAreCutToTheEpcLengths(): void
    {
        $code = GiroCode::erstellen(str_repeat('N', 80), self::IBAN, '', 100, str_repeat('Z', 150));

        self::assertSame(str_repeat('N', GiroCode::NAME_MAX), $code->name);
        self::assertSame(str_repeat('Z', GiroCode::ZWECK_MAX), $code->zweck);
    }

    public function testLineBreaksAndControlCharactersCannotShiftAField(): void
    {
        $code = GiroCode::erstellen("Sport\nhaus\r\tGmbH\u{2028}", self::IBAN, '', 100, "RE-1\n\nEUR999.00\x00");

        self::assertSame('Sport haus GmbH', $code->name);
        self::assertSame('RE-1 EUR999.00', $code->zweck);
        self::assertCount(11, explode("\n", $code->payload()));
    }

    public function testSpecialCharactersStayUtf8(): void
    {
        $code = GiroCode::erstellen('Café Größe – Ærø', self::IBAN, '', 100, 'Rechnung № 5 / Ü');

        self::assertStringContainsString("\nCafé Größe – Ærø\n", $code->payload());
        self::assertStringEndsWith("\nRechnung № 5 / Ü", $code->payload());
        self::assertTrue(mb_check_encoding($code->payload(), 'UTF-8'));
    }

    public function testInvalidUtf8IsScrubbed(): void
    {
        $code = GiroCode::erstellen("Sport\xC3haus", self::IBAN, '', 100, '');

        self::assertTrue(mb_check_encoding($code->payload(), 'UTF-8'));
    }

    public function testMultiByteTextIsShortenedUntilThePayloadFits(): void
    {
        // 50 + 140 four-byte characters would be 760 bytes.
        $code = GiroCode::erstellen(str_repeat('😀', 50), self::IBAN, 'COBADEFFXXX', GiroCode::BETRAG_MAX, str_repeat('😀', 150));

        self::assertLessThanOrEqual(GiroCode::PAYLOAD_MAX, strlen($code->payload()));
        self::assertGreaterThan(GiroCode::PAYLOAD_MAX - 4, strlen($code->payload()), 'shortened no more than needed');
        self::assertSame(str_repeat('😀', 50), $code->name, 'the remittance gives way first');
        self::assertNotSame('', $code->zweck);
        self::assertTrue(mb_check_encoding($code->payload(), 'UTF-8'));
        self::assertCount(69, QrCode::matrix($code->payload()), 'still fits version 13');

        // 70 such characters alone are too long: remittance and name both give way.
        $nurName = GiroCode::erstellen(str_repeat('😀', 80), self::IBAN, 'COBADEFFXXX', GiroCode::BETRAG_MAX, '😀');
        self::assertSame('', $nurName->zweck);
        self::assertNotSame('', $nurName->name);
        self::assertLessThanOrEqual(GiroCode::PAYLOAD_MAX, strlen($nurName->payload()));
    }

    public function testTheSvgIsTheCodeOfThePayload(): void
    {
        $code = GiroCode::erstellen('Sporthaus', self::IBAN, '', 5000, 'RE-1');

        self::assertSame(QrCode::svg($code->payload(), 4, 4, 'GiroCode für die Überweisung'), $code->svg());
    }
}
