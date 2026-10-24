<?php

declare(strict_types=1);

namespace App\Tests\Service\Suche;

use App\Service\Suche\Extraktionstexte;
use PHPUnit\Framework\TestCase;

/**
 * The searchable strings of an `extract-v1` extraction (issue #94).
 */
final class ExtraktionstexteTest extends TestCase
{
    public function testFreeTextsNumbersAndTheAmountInGermanNotation(): void
    {
        $texte = Extraktionstexte::aus([
            'document_type' => 'rechnung',
            'direction' => 'ausgabe',
            'supplier' => ['name' => 'Getränke Becker', 'address' => 'Hauptstr. 1', 'iban' => 'DE89370400440532013000', 'bic' => '', 'vat_id' => '', 'tax_number' => '', 'email' => '', 'website' => '', 'creditor_id' => ''],
            'invoice_number' => 'RE-2026-17',
            'customer_number' => '',
            'contract_number' => null,
            'invoice_date' => '2026-05-01',
            'total_gross' => '1234.5',
            'payment' => ['already_paid' => true, 'method' => 'bar', 'mandate_reference' => 'M-9'],
            'purpose_short' => 'Getränke Sommerfest',
            'category_id' => 7,
            'recurring' => ['likely' => true, 'period' => 'monat', 'evidence' => 'Abschlag Mai'],
            'confidence' => ['supplier' => 0.9],
            'warnings' => ['Betrag schwer lesbar'],
        ]);

        self::assertSame(
            ['Getränke Becker', 'Hauptstr. 1', 'DE89370400440532013000', 'RE-2026-17', 'Getränke Sommerfest', 'M-9', 'Abschlag Mai', '1234,50'],
            $texte,
        );
    }

    public function testTheAmountAlwaysHasTwoDecimals(): void
    {
        self::assertSame(['12,00'], Extraktionstexte::aus(['total_gross' => '12']));
        self::assertSame(['-3,05'], Extraktionstexte::aus(['total_gross' => '-3.05']));
    }

    public function testAnythingMalformedIsSkipped(): void
    {
        self::assertSame([], Extraktionstexte::aus(['supplier' => 'kaputt', 'total_gross' => '1.234,50', 'payment' => 3, 'invoice_number' => ['x']]));
    }
}
