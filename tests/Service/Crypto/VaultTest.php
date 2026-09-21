<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto;

use App\Service\Crypto\CryptoException;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\Vault;
use PHPUnit\Framework\TestCase;

/**
 * Sealed data keys (docs/spec/01-sicherheit.md, "Pflicht-Tests": round trip
 * of a sealed DEK, and the property the whole design rests on - sealing needs
 * no secret, opening does).
 */
final class VaultTest extends TestCase
{
    public function testSealedDataKeyRoundTrip(): void
    {
        $vault = Vault::create();
        $key = DataKey::generate();

        $opened = $vault->openDataKey($vault->sealDataKey($key));

        self::assertTrue($opened->equals($key));
    }

    /**
     * The public submission encrypts without holding any secret: it seals
     * with the public key alone, and only a session that has unlocked the
     * vault can read the result back.
     */
    public function testALockedVaultCanSealButNotOpen(): void
    {
        $vault = Vault::create();
        $publicOnly = Vault::locked($vault->publicKey());
        $key = DataKey::generate();

        $sealed = $publicOnly->sealDataKey($key);
        $context = new FieldContext('submission', 1, 'payload_enc');
        $stored = FieldCipher::encrypt($key, 'Kassenbon 12,90', $context);

        self::assertFalse($publicOnly->isUnlocked());
        self::assertTrue($vault->openDataKey($sealed)->equals($key));
        self::assertSame('Kassenbon 12,90', FieldCipher::decrypt($vault->openDataKey($sealed), $stored, $context));

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('locked');

        $publicOnly->openDataKey($sealed);
    }

    public function testAnotherVaultCannotOpenTheDataKey(): void
    {
        $sealed = Vault::create()->sealDataKey(DataKey::generate());

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('could not be opened');

        Vault::create()->openDataKey($sealed);
    }

    public function testSealingTheSameKeyTwiceGivesDifferentValues(): void
    {
        $vault = Vault::create();
        $key = DataKey::generate();

        self::assertNotSame($vault->sealDataKey($key), $vault->sealDataKey($key));
    }

    /**
     * The path to a later key rotation: a sealed key says which vault
     * generation it belongs to.
     */
    public function testSealedKeyCarriesTheVaultVersion(): void
    {
        $vault = Vault::create(version: 2);

        $sealed = $vault->sealDataKey(DataKey::generate());

        self::assertSame(2, ord($sealed[0]));
    }

    public function testDataKeyOfAnotherVaultVersionIsRejected(): void
    {
        $vault = Vault::create(version: 1);
        $sealed = $vault->sealDataKey(DataKey::generate());
        $newGeneration = Vault::unlocked($vault->publicKey(), $vault->secretKey(), version: 2);

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('vault version 1');

        $newGeneration->openDataKey($sealed);
    }

    public function testLockedVaultHasNoSecretKey(): void
    {
        $this->expectException(CryptoException::class);

        Vault::locked(Vault::create()->publicKey())->secretKey();
    }

    public function testMismatchedKeyPairIsRejected(): void
    {
        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('do not belong together');

        Vault::unlocked(Vault::create()->publicKey(), Vault::create()->secretKey());
    }

    public function testKeysOfTheWrongLengthAreRejected(): void
    {
        $this->expectException(CryptoException::class);

        Vault::locked('zu kurz');
    }

    public function testVersionOutOfRangeIsRejected(): void
    {
        $this->expectException(CryptoException::class);

        Vault::create(version: 0);
    }

    public function testDebugOutputHidesTheSecretKey(): void
    {
        $vault = Vault::create();

        $dump = print_r($vault->__debugInfo(), true);

        self::assertStringNotContainsString(bin2hex($vault->secretKey()), $dump);
    }
}
