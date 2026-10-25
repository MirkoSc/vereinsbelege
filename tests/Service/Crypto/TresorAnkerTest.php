<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto;

use App\Service\Crypto\CryptoException;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\TresorAnker;
use App\Service\Crypto\Vault;
use PHPUnit\Framework\TestCase;

/** Issue #240: the MAC that anchors the vault public key to the server key. */
final class TresorAnkerTest extends TestCase
{
    private function anker(string $serverKey = ''): TresorAnker
    {
        return new ServerCrypto($serverKey === '' ? str_repeat('s', ServerCrypto::KEY_BYTES) : $serverKey)->tresorAnker();
    }

    public function testTheMacIsDeterministicAndVerifies(): void
    {
        $key = Vault::create()->publicKey();
        $mac = $this->anker()->fuer(1, $key);

        self::assertSame(TresorAnker::BYTES, strlen($mac));
        self::assertSame($mac, $this->anker()->fuer(1, $key));
        self::assertTrue($this->anker()->passt(1, $key, $mac));
    }

    public function testItBindsVersionKeyAndServerKey(): void
    {
        $key = Vault::create()->publicKey();
        $mac = $this->anker()->fuer(1, $key);

        self::assertFalse($this->anker()->passt(2, $key, $mac), 'another generation');
        self::assertFalse($this->anker()->passt(1, Vault::create()->publicKey(), $mac), 'another key');
        self::assertFalse($this->anker(str_repeat('x', ServerCrypto::KEY_BYTES))->passt(1, $key, $mac), 'another server key');
        self::assertFalse($this->anker()->passt(1, $key, null), 'no MAC');
        self::assertFalse($this->anker()->passt(1, $key, substr($mac, 0, 31)), 'cut short');
    }

    public function testTheDerivedKeyIsNeitherTheServerKeyNorTheBlindIndexKey(): void
    {
        $serverKey = str_repeat('s', ServerCrypto::KEY_BYTES);
        $key = Vault::create()->publicKey();
        $naiv = hash_hmac('sha256', "vault-anchor-v1\x00" . pack('N', 1) . $key, $serverKey, true);

        self::assertNotSame($naiv, $this->anker($serverKey)->fuer(1, $key));
    }

    public function testAKeyOfTheWrongLengthIsRefused(): void
    {
        $this->expectException(CryptoException::class);
        new TresorAnker('zu kurz');
    }

    public function testDebugOutputHidesTheKey(): void
    {
        self::assertStringNotContainsString(str_repeat('s', 8), print_r($this->anker(), true));
    }
}
