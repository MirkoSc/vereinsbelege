<?php

declare(strict_types=1);

namespace App\Tests\Service\MasterData;

use App\Service\MasterData\SupplierRuleViolation;
use App\Service\MasterData\Vereinskennung;
use PHPUnit\Framework\TestCase;

/**
 * The club's own VAT id and tax number (issue #245, E-67): checked like the
 * supplier form, compared like a supplier key.
 */
final class VereinskennungTest extends TestCase
{
    public function testTheFormIsNormalisedAndCompared(): void
    {
        $kennung = Vereinskennung::pruefen(' de 123 456 789 ', ' 12/345/67890 ');

        self::assertSame('DE123456789', $kennung->ustId);
        self::assertSame('12/345/67890', $kennung->steuernummer);
        self::assertTrue($kennung->istUstId('DE123456789'));
        self::assertTrue($kennung->istUstId('de 123456789'));
        self::assertFalse($kennung->istUstId('DE987654321'));
        self::assertTrue($kennung->istSteuernummer('12 345 67890'));
        self::assertTrue($kennung->istSteuernummer('1234567890'));
        self::assertFalse($kennung->istSteuernummer('99/999/99999'));
    }

    public function testNoIdMatchesNothing(): void
    {
        $keine = Vereinskennung::pruefen('', '');

        self::assertFalse($keine->istUstId(''));
        self::assertFalse($keine->istUstId('DE123456789'));
        self::assertFalse($keine->istSteuernummer(''));
        self::assertSame(['ust_id' => '', 'steuernummer' => ''], $keine->alsDaten());
    }

    public function testAnInvalidVatIdIsRefused(): void
    {
        $this->expectException(SupplierRuleViolation::class);
        Vereinskennung::pruefen('123456789', '');
    }

    public function testATaxNumberWithoutDigitsIsRefused(): void
    {
        $this->expectException(SupplierRuleViolation::class);
        Vereinskennung::pruefen('', '//');
    }
}
