<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto;

use App\Service\Crypto\CryptoException;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Field round trip and the AAD binding (docs/spec/01-sicherheit.md,
 * "Pflicht-Tests": crypto round trips, a swapped AAD fails).
 */
final class FieldCipherTest extends TestCase
{
    private const string PLAINTEXT = '{"gross":1999,"currency":"EUR"}';

    private static function context(): FieldContext
    {
        return new FieldContext('invoice', 42, 'data_enc');
    }

    public function testRoundTrip(): void
    {
        $key = DataKey::generate();

        $stored = FieldCipher::encrypt($key, self::PLAINTEXT, self::context());

        self::assertSame(self::PLAINTEXT, FieldCipher::decrypt($key, $stored, self::context()));
    }

    public function testEmptyValueRoundTrips(): void
    {
        $key = DataKey::generate();

        self::assertSame('', FieldCipher::decrypt($key, FieldCipher::encrypt($key, '', self::context()), self::context()));
    }

    public function testCiphertextRevealsNothingAboutThePlaintext(): void
    {
        $stored = FieldCipher::encrypt(DataKey::generate(), self::PLAINTEXT, self::context());

        self::assertStringNotContainsString(self::PLAINTEXT, $stored);
        self::assertStringNotContainsString('{"gross":1999', $stored);
        self::assertSame(FieldCipher::VERSION, ord($stored[0]));
    }

    public function testSameValueEncryptsDifferentlyEveryTime(): void
    {
        $key = DataKey::generate();
        $context = self::context();

        self::assertNotSame(
            FieldCipher::encrypt($key, self::PLAINTEXT, $context),
            FieldCipher::encrypt($key, self::PLAINTEXT, $context),
            'a fresh nonce per call, otherwise equal values would be recognisable in the database',
        );
    }

    /**
     * A ciphertext is bound to its place: moving it to another row, column or
     * table must not decrypt, even under the same key.
     *
     * @return array<string, array{FieldContext}>
     */
    public static function swappedContexts(): array
    {
        return [
            'other row' => [new FieldContext('invoice', 43, 'data_enc')],
            'other column' => [new FieldContext('invoice', 42, 'notes_enc')],
            'other table' => [new FieldContext('supplier', 42, 'data_enc')],
        ];
    }

    #[DataProvider('swappedContexts')]
    public function testSwappedAadFails(FieldContext $swapped): void
    {
        $key = DataKey::generate();
        $stored = FieldCipher::encrypt($key, self::PLAINTEXT, self::context());

        $this->expectException(CryptoException::class);

        FieldCipher::decrypt($key, $stored, $swapped);
    }

    public function testAnotherKeyFails(): void
    {
        $stored = FieldCipher::encrypt(DataKey::generate(), self::PLAINTEXT, self::context());

        $this->expectException(CryptoException::class);

        FieldCipher::decrypt(DataKey::generate(), $stored, self::context());
    }

    public function testManipulatedCiphertextFails(): void
    {
        $key = DataKey::generate();
        $stored = FieldCipher::encrypt($key, self::PLAINTEXT, self::context());
        $stored[strlen($stored) - 1] = chr(ord($stored[strlen($stored) - 1]) ^ 0x01);

        $this->expectException(CryptoException::class);

        FieldCipher::decrypt($key, $stored, self::context());
    }

    public function testUnknownFormatVersionIsReportedAsSuch(): void
    {
        $key = DataKey::generate();
        $stored = FieldCipher::encrypt($key, self::PLAINTEXT, self::context());
        $stored[0] = chr(FieldCipher::VERSION + 1);

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('unknown format version');

        FieldCipher::decrypt($key, $stored, self::context());
    }

    public function testTruncatedValueFails(): void
    {
        $key = DataKey::generate();

        $this->expectException(CryptoException::class);

        FieldCipher::decrypt($key, substr(FieldCipher::encrypt($key, self::PLAINTEXT, self::context()), 0, 10), self::context());
    }

    public function testFailureMessageCarriesNoPlaintext(): void
    {
        $key = DataKey::generate();
        $stored = FieldCipher::encrypt($key, self::PLAINTEXT, self::context());

        try {
            FieldCipher::decrypt(DataKey::generate(), $stored, self::context());
            self::fail('expected a CryptoException');
        } catch (CryptoException $e) {
            self::assertStringContainsString('invoice|42|data_enc', $e->getMessage());
            self::assertStringNotContainsString('EUR', $e->getMessage());
        }
    }

    /**
     * Without this, ("a|b", 1, "c") and ("a", 1, "b|c") would share an AAD.
     *
     * @return array<string, array{string, int, string}>
     */
    public static function unusableContexts(): array
    {
        return [
            'separator in the table name' => ['invoice|x', 42, 'data_enc'],
            'separator in the column name' => ['invoice', 42, 'data|enc'],
            'empty table name' => ['', 42, 'data_enc'],
            'uppercase column' => ['invoice', 42, 'Data_enc'],
            'row id zero' => ['invoice', 0, 'data_enc'],
        ];
    }

    #[DataProvider('unusableContexts')]
    public function testUnusableContextIsRejected(string $table, int $id, string $column): void
    {
        $this->expectException(CryptoException::class);

        new FieldContext($table, $id, $column);
    }
}
