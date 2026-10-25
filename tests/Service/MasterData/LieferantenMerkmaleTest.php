<?php

declare(strict_types=1);

namespace App\Tests\Service\MasterData;

use App\Domain\SupplierData;
use App\Service\MasterData\LieferantenMerkmale;
use App\Service\MasterData\Merkmale;
use App\Service\MasterData\SupplierService;
use PHPUnit\Framework\TestCase;

/**
 * What a confirmed receipt teaches its supplier (issue #201/M7-6b,
 * docs/spec/03-erfassung-und-ki.md section 7 "Stand M7-6b"): new IBAN,
 * name as an alias, a missing creditor id and its mandate reference -
 * nothing it knows already, nothing invalid, nothing past the limits.
 */
final class LieferantenMerkmaleTest extends TestCase
{
    private const string IBAN_1 = 'DE89370400440532013000';
    private const string IBAN_2 = 'DE02120300000000202051';
    private const string GLAEUBIGER = 'DE98ZZZ09999999999';

    public function testANewIbanAndANewNameAreLearnt(): void
    {
        $merkmale = LieferantenMerkmale::neue(
            new SupplierData(name: 'Sporthaus Beispiel', ibans: [self::IBAN_1]),
            ['name' => '  SH   Beispiel ', 'iban' => 'DE02 1203 0000 0000 2020 51'],
            '',
        );

        self::assertSame(self::IBAN_2, $merkmale->iban, 'normalised');
        self::assertSame('SH Beispiel', $merkmale->alias, 'spaces collapsed');
        self::assertNull($merkmale->creditorId);
        self::assertNull($merkmale->mandateRef);
        self::assertSame(['aliases', 'iban'], $merkmale->felder(), 'the payload keys');
        self::assertSame(['Name als Alias', 'IBAN'], $merkmale->bezeichnungen());
        self::assertSame(['IBAN DE02 1203 0000 0000 2020 51', 'Name „SH Beispiel“ als Alias'], $merkmale->beschreibung());
        self::assertSame([], $merkmale->hinweise);
    }

    public function testWhatTheSupplierKnowsAlreadyIsNotLearnt(): void
    {
        $merkmale = LieferantenMerkmale::neue(
            new SupplierData(name: 'Getränke Müller', aliases: ['GM Getränke'], ibans: [self::IBAN_1]),
            // Legal form, case and umlauts aside the same name (step 4).
            ['name' => 'GETRAENKE MÜLLER GmbH & Co. KG', 'iban' => self::IBAN_1],
            '',
        );
        self::assertTrue($merkmale->leer());

        $alias = LieferantenMerkmale::neue(new SupplierData(name: 'Getränke Müller', aliases: ['GM Getränke']), ['name' => 'gm getraenke'], '');
        self::assertNull($alias->alias, 'an alias counts like the name');
    }

    public function testAnInvalidIbanOrAnEmptyNameTeachesNothing(): void
    {
        $merkmale = LieferantenMerkmale::neue(
            new SupplierData(name: 'Sporthaus Beispiel'),
            ['name' => '  -  ', 'iban' => 'DE89370400440532013001', 'creditor_id' => 'kein Gläubiger'],
            'M-1',
        );

        self::assertNull($merkmale->iban, 'wrong check sum');
        self::assertNull($merkmale->alias, 'no letters, no name');
        self::assertNull($merkmale->creditorId);
        self::assertNull($merkmale->mandateRef, 'no creditor id, no mandate reference');
        self::assertTrue($merkmale->leer());
    }

    public function testAMissingCreditorIdIsLearntWithItsMandateReference(): void
    {
        $merkmale = LieferantenMerkmale::neue(
            new SupplierData(name: 'Stadtwerke Musterstadt'),
            ['name' => 'Stadtwerke Musterstadt', 'creditor_id' => 'de98 zzz0 9999 9999 99'],
            'MANDAT-0815',
        );

        self::assertSame(self::GLAEUBIGER, $merkmale->creditorId);
        self::assertSame('MANDAT-0815', $merkmale->mandateRef);
        self::assertSame(['creditor_id', 'mandate_refs'], $merkmale->felder());
    }

    public function testAnotherCreditorIdIsReportedNotReplaced(): void
    {
        $merkmale = LieferantenMerkmale::neue(
            new SupplierData(name: 'Stadtwerke Musterstadt', creditorId: self::GLAEUBIGER),
            ['creditor_id' => 'DE02ZZZ00000000001'],
            'MANDAT-0815',
        );

        self::assertNull($merkmale->creditorId);
        self::assertNull($merkmale->mandateRef, 'the mandate belongs to the other creditor id');
        self::assertCount(1, $merkmale->hinweise);
        self::assertStringContainsString('nicht übernommen', $merkmale->hinweise[0]);
    }

    public function testANewMandateReferenceOfTheKnownCreditorIdIsLearnt(): void
    {
        $lieferant = new SupplierData(name: 'Stadtwerke Musterstadt', creditorId: self::GLAEUBIGER, mandateRefs: ['MANDAT-1']);

        self::assertSame('MANDAT-2', LieferantenMerkmale::neue($lieferant, ['creditor_id' => self::GLAEUBIGER], 'MANDAT-2')->mandateRef);
        self::assertNull(LieferantenMerkmale::neue($lieferant, ['creditor_id' => self::GLAEUBIGER], 'mandat-1')->mandateRef, 'compared like its key');
        self::assertNull(LieferantenMerkmale::neue($lieferant, [], 'MANDAT-2')->mandateRef, 'not without the creditor id');
    }

    public function testFullListsTeachNothingAndSaySo(): void
    {
        $voll = array_map(static fn(int $i): string => 'Alias ' . $i, range(1, SupplierService::LIST_MAX));
        $merkmale = LieferantenMerkmale::neue(new SupplierData(name: 'Sporthaus Beispiel', aliases: $voll), ['name' => 'Ganz anders'], '');

        self::assertNull($merkmale->alias);
        self::assertSame([sprintf('Höchstens %d Aliasse je Lieferant – nicht übernommen.', SupplierService::LIST_MAX)], $merkmale->hinweise);
    }

    public function testApplyingAddsTheValuesAndKeepsEverythingElse(): void
    {
        $vorher = new SupplierData(name: 'Sporthaus Beispiel', aliases: ['SH'], address: 'Musterstraße 1', ibans: [self::IBAN_1], email: 'a@example.org', notes: 'Notiz');
        $nachher = new Merkmale(iban: self::IBAN_2, alias: 'SH Beispiel', creditorId: self::GLAEUBIGER, mandateRef: 'M-1')->anwenden($vorher);

        self::assertSame(['SH', 'SH Beispiel'], $nachher->aliases);
        self::assertSame([self::IBAN_1, self::IBAN_2], $nachher->ibans);
        self::assertSame(self::GLAEUBIGER, $nachher->creditorId);
        self::assertSame(['M-1'], $nachher->mandateRefs);
        self::assertSame([...$vorher->toPayload(), 'aliases' => $nachher->aliases, 'iban' => $nachher->ibans, 'creditor_id' => self::GLAEUBIGER, 'mandate_refs' => ['M-1']], $nachher->toPayload());
    }

    public function testDroppingACreditorIdDropsItsMandateToo(): void
    {
        $merkmale = new Merkmale(alias: 'SH', creditorId: self::GLAEUBIGER, mandateRef: 'M-1')->ohne('creditor_id', 'vergeben');

        self::assertSame('SH', $merkmale->alias);
        self::assertNull($merkmale->creditorId);
        self::assertNull($merkmale->mandateRef);
        self::assertSame(['vergeben'], $merkmale->hinweise);
        self::assertSame([], $merkmale->konflikte, 'no supplier named, no conflict');
    }

    public function testAValueOfAnotherSupplierIsMarkedAsAConflict(): void
    {
        $merkmale = new Merkmale(iban: self::IBAN_2, alias: 'SH', vatId: 'DE123456789')
            ->ohne('iban', 'vergeben', 7)
            ->ohne('vat_id', 'vergeben', 9);

        self::assertSame(['iban' => 7, 'vat_id' => 9], $merkmale->konflikte, 'field => the other supplier');
        self::assertSame(['IBAN', 'USt-ID'], $merkmale->konfliktBezeichnungen());
        self::assertSame(['aliases'], $merkmale->felder(), 'only the rest is learnt');
        self::assertNull($merkmale->vatId);
    }

    public function testAMissingVatIdAndTaxNumberAreLearnt(): void
    {
        $merkmale = LieferantenMerkmale::neue(
            new SupplierData(name: 'Sporthaus Beispiel'),
            ['name' => 'Sporthaus Beispiel', 'vat_id' => 'de 123 456 789', 'tax_number' => ' 12/345/67890 '],
            '',
        );

        self::assertSame('DE123456789', $merkmale->vatId, 'normalised like the form');
        self::assertSame('12/345/67890', $merkmale->taxNumber);
        self::assertSame(['vat_id', 'tax_number'], $merkmale->felder());
        self::assertSame(['USt-ID', 'Steuernummer'], $merkmale->bezeichnungen());
        self::assertSame(['USt-ID DE123456789', 'Steuernummer 12/345/67890'], $merkmale->beschreibung());

        $nachher = $merkmale->anwenden(new SupplierData(name: 'Sporthaus Beispiel'));
        self::assertSame('DE123456789', $nachher->vatId);
        self::assertSame('12/345/67890', $nachher->taxNumber);
    }

    public function testAKnownOrDifferentVatIdOrTaxNumberIsNotReplaced(): void
    {
        $lieferant = new SupplierData(name: 'Sporthaus Beispiel', vatId: 'DE123456789', taxNumber: '12/345/67890');

        $gleich = LieferantenMerkmale::neue($lieferant, ['vat_id' => 'DE 123456789', 'tax_number' => '12 345 67890'], '');
        self::assertTrue($gleich->leer(), 'the same ids, written differently');
        self::assertSame([], $gleich->hinweise);

        $anders = LieferantenMerkmale::neue($lieferant, ['vat_id' => 'DE999999999', 'tax_number' => '99/999/99999'], '');
        self::assertTrue($anders->leer(), 'a different id is reported, not replaced');
        self::assertCount(2, $anders->hinweise);
    }

    public function testAnInvalidVatIdIsNotLearnt(): void
    {
        $merkmale = LieferantenMerkmale::neue(new SupplierData(name: 'Sporthaus Beispiel'), ['vat_id' => '123456789', 'tax_number' => '//'], '');

        self::assertNull($merkmale->vatId, 'no country code');
        self::assertNull($merkmale->taxNumber, 'no letters or digits');
        self::assertTrue($merkmale->leer());
    }

    public function testTooLongValuesAreLeftOutAndSaySo(): void
    {
        $merkmale = LieferantenMerkmale::neue(
            new SupplierData(name: 'Stadtwerke Musterstadt', creditorId: self::GLAEUBIGER),
            ['name' => str_repeat('x', SupplierService::NAME_MAX + 1), 'creditor_id' => self::GLAEUBIGER],
            str_repeat('M', SupplierService::MANDAT_MAX + 1),
        );

        self::assertTrue($merkmale->leer());
        self::assertCount(2, $merkmale->hinweise);
    }
}
