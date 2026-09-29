<?php

declare(strict_types=1);

namespace App\Tests\Service\MasterData;

use App\Domain\SupplierData;
use App\Domain\SupplierKeyKind;
use App\Service\MasterData\SupplierKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which values of a supplier become blind index keys, normalised how
 * (M6-2, issue #36, docs/spec/03-erfassung-und-ki.md section 7 step 4:
 * "Kleinschreibung, Rechtsform entfernt, Umlaute vereinheitlicht").
 */
final class SupplierKeysTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function namen(): iterable
    {
        yield 'GmbH & Co. KG' => ['Getränke Müller GmbH & Co. KG', 'getraenke mueller'];
        yield 'GmbH und Co. KG' => ['Muster GmbH und Co. KG', 'muster'];
        yield 'plain GmbH' => ['Muster GmbH', 'muster'];
        yield 'e. V. with spaces' => ['TSV Beispielstadt e. V.', 'tsv beispielstadt'];
        yield 'e.V. without spaces' => ['TSV Beispielstadt e.V.', 'tsv beispielstadt'];
        yield 'e.K.' => ['Bäckerei Schmidt e.K.', 'baeckerei schmidt'];
        yield 'UG (haftungsbeschränkt)' => ['Rasen-Profi UG (haftungsbeschränkt)', 'rasen profi'];
        yield 'AG with ß' => ['Straßenbau Weiß AG', 'strassenbau weiss'];
        yield 'legal form only at the end' => ['Sport AG Nord', 'sport ag nord'];
        yield 'accents' => ['Café Olé', 'cafe ole'];
        yield 'case and whitespace' => ['  GETRÄNKE   müller  ', 'getraenke mueller'];
        yield 'nothing but a legal form stays' => ['GmbH', 'gmbh'];
        yield 'word starting like a form' => ['Event GmbH', 'event'];
    }

    #[DataProvider('namen')]
    public function testTheNameIsNormalisedLikeTheResolutionCompares(string $name, string $erwartet): void
    {
        self::assertSame($erwartet, SupplierKeys::name($name));
    }

    public function testSpellingsOfTheSameCompanyGiveTheSameKey(): void
    {
        self::assertSame(
            SupplierKeys::name('Getränke Müller GmbH'),
            SupplierKeys::name('Getraenke Mueller GmbH & Co. KG'),
        );
    }

    public function testEveryIdentifyingValueBecomesAKey(): void
    {
        $keys = SupplierKeys::fuer(new SupplierData(
            name: 'Getränke Müller GmbH',
            aliases: ['Mueller Getraenkehandel', 'Getränke Müller'],
            ibans: ['DE89370400440532013000', 'de02 1203 0000 0000 2020 51'],
            vatId: 'de 123456789',
            taxNumber: '12/345/67890',
            creditorId: 'DE98ZZZ09999999999',
            mandateRefs: ['m-2024 001'],
        ));

        self::assertSame([
            [SupplierKeyKind::Name, 'getraenke mueller'],
            [SupplierKeyKind::Name, 'mueller getraenkehandel'],
            [SupplierKeyKind::Iban, 'DE89370400440532013000'],
            [SupplierKeyKind::Iban, 'DE02120300000000202051'],
            [SupplierKeyKind::VatId, 'DE123456789'],
            [SupplierKeyKind::TaxNumber, '1234567890'],
            [SupplierKeyKind::CreditorId, 'DE98ZZZ09999999999'],
            [SupplierKeyKind::Mandate, 'M-2024001'],
        ], $keys, 'the alias "Getränke Müller" is the name again and gives no second key');
    }

    public function testEmptyFieldsGiveNoKey(): void
    {
        self::assertSame([[SupplierKeyKind::Name, 'muster']], SupplierKeys::fuer(new SupplierData(name: 'Muster')));
    }

    public function testTaxNumberSpellingsMatch(): void
    {
        self::assertSame(SupplierKeys::steuernummer('12/345/67890'), SupplierKeys::steuernummer('12 345 67890'));
    }

    public function testEveryKindHasItsOwnPurposeAndOnlyIdentifiersAreUnique(): void
    {
        $purposes = array_map(static fn(SupplierKeyKind $k): string => $k->purpose(), SupplierKeyKind::cases());
        self::assertSame(array_unique($purposes), $purposes);
        self::assertSame('supplier.iban', SupplierKeyKind::Iban->purpose(), 'the purpose named in docs/spec/01-sicherheit.md');

        $eindeutig = array_values(array_filter(SupplierKeyKind::cases(), static fn(SupplierKeyKind $k): bool => $k->eindeutig()));
        self::assertSame([SupplierKeyKind::Iban, SupplierKeyKind::VatId, SupplierKeyKind::TaxNumber, SupplierKeyKind::CreditorId], $eindeutig);
    }
}
