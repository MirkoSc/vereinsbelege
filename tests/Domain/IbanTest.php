<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\Iban;
use PHPUnit\Framework\TestCase;

/**
 * IBAN check (Pflicht-Test "IBAN-Validierung", docs/spec/03-erfassung-und-ki.md
 * section 1, issue #24/M4-2). App\Tests\js\iban.test.js mirrors these cases
 * for the client-side check.
 */
final class IbanTest extends TestCase
{
    public function testAKnownGoodIbanIsValidWithOrWithoutSpacesAndCase(): void
    {
        self::assertTrue(Iban::istGueltig('DE89370400440532013000'));
        self::assertTrue(Iban::istGueltig('DE89 3704 0044 0532 0130 00'));
        self::assertTrue(Iban::istGueltig('de89 3704 0044 0532 0130 00'));
    }

    public function testOtherSepaCountriesValidate(): void
    {
        self::assertTrue(Iban::istGueltig('AT611904300234573201'));
        self::assertTrue(Iban::istGueltig('NL91ABNA0417164300'));
        self::assertTrue(Iban::istGueltig('FR1420041010050500013M02606'));
    }

    public function testAWrongCheckDigitIsRejected(): void
    {
        self::assertFalse(Iban::istGueltig('DE89370400440532013001'));
    }

    public function testALengthThatDoesNotMatchTheCountryIsRejected(): void
    {
        self::assertFalse(Iban::istGueltig('DE8937040044053201300'), 'one digit short');
        self::assertFalse(Iban::istGueltig('DE893704004405320130000'), 'one digit long');
    }

    public function testACountryOutsideTheKnownTableStillGetsTheChecksumCheck(): void
    {
        self::assertTrue(Iban::istGueltig('XK221212012345678'));
        self::assertFalse(Iban::istGueltig('XK231212012345678'), 'wrong check digits');
    }

    public function testGarbageIsRejectedWithoutThrowing(): void
    {
        self::assertFalse(Iban::istGueltig(''));
        self::assertFalse(Iban::istGueltig('not an iban'));
        self::assertFalse(Iban::istGueltig('DE'));
        self::assertFalse(Iban::istGueltig('123456789012345678'));
    }

    public function testNormalisingStripsWhitespaceAndUppercases(): void
    {
        self::assertSame('DE89370400440532013000', Iban::normalisieren(' de89 3704 0044 0532 0130 00 '));
    }

    public function testFormattingGroupsTheNormalisedValueInFours(): void
    {
        self::assertSame('DE89 3704 0044 0532 0130 00', Iban::formatieren('de89370400440532013000'));
    }
}
