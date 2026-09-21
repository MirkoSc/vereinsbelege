<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto;

use App\Service\Crypto\CryptoException;
use App\Service\Crypto\ServerCrypto;
use PHPUnit\Framework\TestCase;

/**
 * The server key level (docs/spec/01-sicherheit.md section 2): operating data
 * that has to be readable without a logged-in user.
 */
final class ServerCryptoTest extends TestCase
{
    private const string PLAINTEXT = 'kassier@example.org';

    private static function crypto(): ServerCrypto
    {
        return new ServerCrypto(random_bytes(ServerCrypto::KEY_BYTES));
    }

    public function testRoundTrip(): void
    {
        $crypto = self::crypto();

        self::assertSame(self::PLAINTEXT, $crypto->decrypt($crypto->encrypt(self::PLAINTEXT)));
    }

    public function testCiphertextRevealsNothingAboutThePlaintext(): void
    {
        $stored = self::crypto()->encrypt(self::PLAINTEXT);

        self::assertStringNotContainsString(self::PLAINTEXT, $stored);
        self::assertSame(ServerCrypto::VERSION, ord($stored[0]));
    }

    public function testSameValueEncryptsDifferentlyEveryTime(): void
    {
        $crypto = self::crypto();

        self::assertNotSame($crypto->encrypt(self::PLAINTEXT), $crypto->encrypt(self::PLAINTEXT));
    }

    public function testAnotherKeyCannotDecrypt(): void
    {
        $stored = self::crypto()->encrypt(self::PLAINTEXT);

        $this->expectException(CryptoException::class);

        self::crypto()->decrypt($stored);
    }

    public function testManipulatedCiphertextFails(): void
    {
        $crypto = self::crypto();
        $stored = $crypto->encrypt(self::PLAINTEXT);
        $stored[strlen($stored) - 1] = chr(ord($stored[strlen($stored) - 1]) ^ 0x01);

        $this->expectException(CryptoException::class);

        $crypto->decrypt($stored);
    }

    public function testUnknownFormatVersionIsReportedAsSuch(): void
    {
        $crypto = self::crypto();
        $stored = $crypto->encrypt(self::PLAINTEXT);
        $stored[0] = chr(ServerCrypto::VERSION + 1);

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('unknown format version');

        $crypto->decrypt($stored);
    }

    public function testTruncatedValueFails(): void
    {
        $crypto = self::crypto();

        $this->expectException(CryptoException::class);

        $crypto->decrypt(substr($crypto->encrypt(self::PLAINTEXT), 0, 8));
    }

    /**
     * The login has to find an account before anybody is logged in, so this
     * index hangs off the server key, not off the vault.
     */
    public function testEmailBlindIndexIsDeterministicAndCaseInsensitive(): void
    {
        $key = random_bytes(ServerCrypto::KEY_BYTES);
        $index = (new ServerCrypto($key))->blindIndex();

        self::assertSame(
            $index->forValue('user.email', 'Kassier@Example.org'),
            (new ServerCrypto($key))->blindIndex()->forValue('user.email', 'kassier@example.org'),
        );
    }

    public function testAnotherServerKeyGivesAnotherEmailIndex(): void
    {
        self::assertNotSame(
            self::crypto()->blindIndex()->forValue('user.email', self::PLAINTEXT),
            self::crypto()->blindIndex()->forValue('user.email', self::PLAINTEXT),
        );
    }

    public function testKeyOfTheWrongLengthIsRejected(): void
    {
        $this->expectException(CryptoException::class);

        new ServerCrypto('zu kurz');
    }

    public function testDebugOutputHidesTheKey(): void
    {
        $key = random_bytes(ServerCrypto::KEY_BYTES);

        $dump = print_r((new ServerCrypto($key))->__debugInfo(), true);

        self::assertStringNotContainsString($key, $dump);
    }
}
