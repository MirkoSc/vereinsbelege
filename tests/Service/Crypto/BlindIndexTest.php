<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto;

use App\Service\Crypto\BlindIndex;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Blind indexes (docs/spec/01-sicherheit.md, "Pflicht-Tests": a blind index
 * is deterministic) - otherwise no search and no duplicate detection on
 * encrypted values would work.
 */
final class BlindIndexTest extends TestCase
{
    public function testSameValueGivesSameIndex(): void
    {
        $index = Vault::create()->blindIndex();

        self::assertSame(
            $index->forValue('supplier.name', 'Muster GmbH'),
            $index->forValue('supplier.name', 'Muster GmbH'),
        );
    }

    public function testIndexFitsTheBinaryColumn(): void
    {
        $index = Vault::create()->blindIndex();

        self::assertSame(BlindIndex::BYTES, strlen($index->forValue('supplier.name', 'Muster GmbH')));
    }

    public function testDifferentValuesGiveDifferentIndexes(): void
    {
        $index = Vault::create()->blindIndex();

        self::assertNotSame(
            $index->forValue('supplier.name', 'Muster GmbH'),
            $index->forValue('supplier.name', 'Muster AG'),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function equalAfterNormalisation(): array
    {
        return [
            'case' => ['Muster GmbH', 'muster gmbh'],
            'surrounding whitespace' => ['Muster GmbH', "  Muster GmbH\n"],
            'repeated whitespace' => ['Muster GmbH', "Muster \t GmbH"],
            'umlaut case' => ['Bäckerei Öhler', 'bäckerei öhler'],
        ];
    }

    #[DataProvider('equalAfterNormalisation')]
    public function testNormalisationMakesEqualValuesMatch(string $a, string $b): void
    {
        $index = Vault::create()->blindIndex();

        self::assertSame($index->forValue('supplier.name', $a), $index->forValue('supplier.name', $b));
    }

    /**
     * The same IBAN in two columns must not be recognisable as the same value
     * by somebody who only reads the database.
     */
    public function testTheSameValueUnderAnotherPurposeGivesAnotherIndex(): void
    {
        $index = Vault::create()->blindIndex();

        self::assertNotSame(
            $index->forValue('supplier.iban', 'DE02120300000000202051'),
            $index->forValue('bank_account.iban', 'DE02120300000000202051'),
        );
    }

    /**
     * The purpose is separated from the value by a NUL byte, so no pair of
     * ("a", "bc") and ("ab", "c") can collide.
     */
    public function testPurposeAndValueCannotBeShiftedIntoEachOther(): void
    {
        $index = Vault::create()->blindIndex();

        self::assertNotSame(
            $index->forValue('supplier.name', 'x_muster'),
            $index->forValue('supplier.name_x', 'muster'),
        );
    }

    public function testAnotherVaultGivesAnotherIndex(): void
    {
        self::assertNotSame(
            Vault::create()->blindIndex()->forValue('supplier.name', 'Muster GmbH'),
            Vault::create()->blindIndex()->forValue('supplier.name', 'Muster GmbH'),
        );
    }

    public function testALockedVaultCannotComputeIndexes(): void
    {
        $locked = Vault::locked(Vault::create()->publicKey());

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('locked');

        $locked->blindIndex();
    }

    public function testUnusablePurposeIsRejected(): void
    {
        $index = Vault::create()->blindIndex();

        $this->expectException(CryptoException::class);

        $index->forValue("supplier\0name", 'Muster GmbH');
    }

    public function testKeyOfTheWrongLengthIsRejected(): void
    {
        $this->expectException(CryptoException::class);

        new BlindIndex('zu kurz');
    }
}
