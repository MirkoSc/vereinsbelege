<?php

declare(strict_types=1);

namespace App\Tests\Service\MasterData;

use App\Domain\Supplier;
use App\Domain\SupplierData;
use App\Domain\SupplierOrigin;
use App\Domain\SupplierRole;
use App\Service\MasterData\SupplierMerge;
use App\Service\MasterData\SupplierRuleViolation;
use App\Service\MasterData\SupplierService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a merged supplier looks like (M6-5, issue #39,
 * docs/spec/03-erfassung-und-ki.md section 7): nothing of the source gets
 * lost, conflicting identifiers are refused.
 */
final class SupplierMergeTest extends TestCase
{
    private const string IBAN_1 = 'DE89370400440532013000';
    private const string IBAN_2 = 'DE02120300000000202051';

    public function testNamesIbansAndMandatesAreUnited(): void
    {
        $plan = SupplierMerge::plane(
            self::lieferant(1, new SupplierData(name: 'Getränke Müller', aliases: ['GM Getränke', 'Müller GmbH'], ibans: [self::IBAN_1], mandateRefs: ['m-1'])),
            self::lieferant(2, new SupplierData(name: 'Müller GmbH', aliases: ['gm getränke'], ibans: [self::IBAN_2, self::IBAN_1], mandateRefs: ['M-1', 'M-2'])),
        );

        self::assertSame('Müller GmbH', $plan->data->name, 'the target keeps its name');
        self::assertSame(['gm getränke', 'Getränke Müller'], $plan->data->aliases, 'source name becomes an alias, no duplicate in another case, not the own name');
        self::assertSame([self::IBAN_2, self::IBAN_1], $plan->data->ibans);
        self::assertSame(['M-1', 'M-2'], $plan->data->mandateRefs, 'mandate references compared like their keys');
        self::assertSame(['aliases'], $plan->geaenderteFelder);
    }

    public function testTheTargetTakesTheSourcesIdentifiersWhenItHasNone(): void
    {
        $plan = SupplierMerge::plane(
            self::lieferant(1, new SupplierData(name: 'A', vatId: 'DE123456789', taxNumber: '12/345/67890', creditorId: 'DE98ZZZ09999999999', bic: 'COBADEFFXXX')),
            self::lieferant(2, new SupplierData(name: 'B', taxNumber: '12 345 67890')),
        );

        self::assertSame('DE123456789', $plan->data->vatId);
        self::assertSame('12 345 67890', $plan->data->taxNumber, 'the same number in another spelling is no conflict');
        self::assertSame('DE98ZZZ09999999999', $plan->data->creditorId);
        self::assertSame('COBADEFFXXX', $plan->data->bic);
        self::assertSame('', $plan->data->notes);
        self::assertSame(['aliases', 'bic', 'vat_id', 'creditor_id'], $plan->geaenderteFelder);
    }

    /**
     * @return iterable<string, array{SupplierData, SupplierData, string}>
     */
    public static function widersprueche(): iterable
    {
        yield 'VAT id' => [new SupplierData(name: 'A', vatId: 'DE111111111'), new SupplierData(name: 'B', vatId: 'DE222222222'), 'USt-IDs'];
        yield 'tax number' => [new SupplierData(name: 'A', taxNumber: '12/345/1'), new SupplierData(name: 'B', taxNumber: '12/345/2'), 'Steuernummern'];
        yield 'creditor id' => [new SupplierData(name: 'A', creditorId: 'DE98ZZZ09999999999'), new SupplierData(name: 'B', creditorId: 'DE71ZZZ00000000001'), 'Gläubiger-IDs'];
    }

    #[DataProvider('widersprueche')]
    public function testDifferentIdentifiersAreRefused(SupplierData $quelle, SupplierData $ziel, string $was): void
    {
        $this->expectException(SupplierRuleViolation::class);
        $this->expectExceptionMessage('unterschiedliche ' . $was);

        SupplierMerge::plane(self::lieferant(1, $quelle), self::lieferant(2, $ziel));
    }

    public function testConflictingSingleValuesOfTheSourceGoIntoTheNotes(): void
    {
        $plan = SupplierMerge::plane(
            self::lieferant(1, new SupplierData(
                name: 'Alt',
                address: "Hauptstr. 1\n12345 Musterstadt",
                email: 'alt@example.org',
                website: 'example.org',
                customerNumber: 'K-7',
                notes: 'Liefert dienstags.',
            )),
            self::lieferant(2, new SupplierData(name: 'Neu', email: 'neu@example.org', website: 'example.org', customerNumber: 'K-8', notes: 'Stammlieferant.')),
        );

        self::assertSame("Hauptstr. 1\n12345 Musterstadt", $plan->data->address, 'an empty target value takes the source one');
        self::assertSame('neu@example.org', $plan->data->email, 'the target wins');
        self::assertSame('K-8', $plan->data->customerNumber);
        self::assertSame(
            "Stammlieferant.\n\nÜbernommen von „Alt“:\nE-Mail: alt@example.org\nKundennummer: K-7\nLiefert dienstags.",
            $plan->data->notes,
            'the same website is no conflict',
        );
    }

    public function testRolesAndDefaultCategory(): void
    {
        $gleich = SupplierMerge::plane(self::lieferant(1, new SupplierData(name: 'A'), kategorie: 5), self::lieferant(2, new SupplierData(name: 'B')));
        self::assertSame(SupplierRole::Lieferant, $gleich->role);
        self::assertSame(5, $gleich->defaultCategoryId, 'the source category when the target has none');

        $verschieden = SupplierMerge::plane(
            self::lieferant(1, new SupplierData(name: 'A'), SupplierRole::Zahler, 5),
            self::lieferant(2, new SupplierData(name: 'B'), SupplierRole::Lieferant, 7),
        );
        self::assertSame(SupplierRole::Beide, $verschieden->role, 'a supplier that is also a payer');
        self::assertSame(7, $verschieden->defaultCategoryId, 'the target keeps its own');
        self::assertSame(['role', 'aliases'], $verschieden->geaenderteFelder);
    }

    public function testAResultBeyondTheFormsLimitsIsRefused(): void
    {
        $ibans = static fn(int $von): array => array_map(static fn(int $i): string => sprintf('DE%020d', $i), range($von, $von + 11));
        try {
            SupplierMerge::plane(self::lieferant(1, new SupplierData(name: 'A', ibans: $ibans(0))), self::lieferant(2, new SupplierData(name: 'B', ibans: $ibans(100))));
            self::fail('24 IBANs');
        } catch (SupplierRuleViolation $e) {
            self::assertStringContainsString('mehr als ' . SupplierService::LIST_MAX . ' IBANs', $e->getMessage());
        }

        $this->expectExceptionMessage('länger als ' . SupplierService::NOTES_MAX . ' Zeichen');
        SupplierMerge::plane(
            self::lieferant(1, new SupplierData(name: 'A', notes: str_repeat('x', 1500))),
            self::lieferant(2, new SupplierData(name: 'B', notes: str_repeat('y', 1500))),
        );
    }

    private static function lieferant(int $id, SupplierData $daten, SupplierRole $rolle = SupplierRole::Lieferant, ?int $kategorie = null): Supplier
    {
        $jetzt = new \DateTimeImmutable('2026-03-01 10:00:00');

        return new Supplier($id, $rolle, $daten, $kategorie, SupplierOrigin::Manuell, false, $jetzt, $jetzt);
    }
}
