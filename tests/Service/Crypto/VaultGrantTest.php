<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto;

use App\Service\Crypto\CryptoException;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\UserKeyPair;
use App\Service\Crypto\Vault;
use App\Service\Crypto\VaultGrant;
use PHPUnit\Framework\TestCase;

/**
 * Vault grants (docs/spec/01-sicherheit.md section 2): the vault private key
 * sealed to one user, the only form in which it exists on the server.
 */
final class VaultGrantTest extends TestCase
{
    public function testGrantOpensTheVaultForTheUser(): void
    {
        $vault = Vault::create();
        $user = UserKeyPair::create();
        $sealedKey = $vault->sealDataKey($key = DataKey::generate());

        $unlocked = VaultGrant::seal($vault, $user->publicKey())->open($user, $vault->publicKey());

        self::assertTrue($unlocked->isUnlocked());
        self::assertTrue($unlocked->openDataKey($sealedKey)->equals($key));
    }

    /**
     * An admin needs nothing of the user but their public key - the grant is
     * sealed, so the admin does not learn the user's private key, and the
     * user does not learn the admin's.
     */
    public function testSealingNeedsOnlyTheUserPublicKey(): void
    {
        $vault = Vault::create();
        $user = UserKeyPair::create();

        $grant = VaultGrant::seal($vault, UserKeyPair::locked($user->publicKey())->publicKey());

        self::assertSame($vault->secretKey(), $grant->open($user, $vault->publicKey())->secretKey());
    }

    public function testAnotherUserCannotOpenTheGrant(): void
    {
        $vault = Vault::create();
        $grant = VaultGrant::seal($vault, UserKeyPair::create()->publicKey());

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('could not be opened');

        $grant->open(UserKeyPair::create(), $vault->publicKey());
    }

    public function testALockedUserKeyPairCannotOpenTheGrant(): void
    {
        $vault = Vault::create();
        $user = UserKeyPair::create();
        $grant = VaultGrant::seal($vault, $user->publicKey());

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('locked');

        $grant->open(UserKeyPair::locked($user->publicKey()), $vault->publicKey());
    }

    public function testSealingTheSameKeyTwiceGivesDifferentValues(): void
    {
        $vault = Vault::create();
        $user = UserKeyPair::create();

        self::assertNotSame(
            VaultGrant::seal($vault, $user->publicKey())->sealed(),
            VaultGrant::seal($vault, $user->publicKey())->sealed(),
        );
    }

    /**
     * Like a sealed data key, a grant says which vault generation it belongs
     * to - the path left open for a later key rotation.
     */
    public function testGrantCarriesTheVaultVersion(): void
    {
        $vault = Vault::create(version: 2);
        $user = UserKeyPair::create();

        $grant = VaultGrant::fromStorage(VaultGrant::seal($vault, $user->publicKey())->sealed());

        self::assertSame(2, $grant->vaultVersion);
        self::assertSame(2, $grant->open($user, $vault->publicKey(), expectedVaultVersion: 2)->version);
    }

    public function testGrantOfAnotherVaultVersionIsRejected(): void
    {
        $vault = Vault::create();
        $user = UserKeyPair::create();
        $grant = VaultGrant::seal($vault, $user->publicKey());

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('vault version 1');

        $grant->open($user, $vault->publicKey(), expectedVaultVersion: 2);
    }

    /**
     * A grant row swapped into another installation's database has to be
     * noticed, not silently used against the wrong vault public key.
     */
    public function testGrantOfAnotherVaultIsRejected(): void
    {
        $user = UserKeyPair::create();
        $grant = VaultGrant::seal(Vault::create(), $user->publicKey());

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('another vault');

        $grant->open($user, Vault::create()->publicKey());
    }

    public function testALockedVaultCannotHandOutGrants(): void
    {
        $vault = Vault::create();

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('locked');

        VaultGrant::seal(Vault::locked($vault->publicKey()), UserKeyPair::create()->publicKey());
    }

    public function testAPublicKeyOfTheWrongLengthIsRejected(): void
    {
        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('user public key');

        VaultGrant::seal(Vault::create(), 'zu kurz');
    }

    public function testTruncatedGrantIsRejected(): void
    {
        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('too short');

        VaultGrant::fromStorage("\x01");
    }

    /**
     * The acceptance criterion of the issue: what the database holds never
     * contains the vault private key itself.
     */
    public function testStoredValueDoesNotContainTheVaultSecretKey(): void
    {
        $vault = Vault::create();

        $grant = VaultGrant::seal($vault, UserKeyPair::create()->publicKey());

        self::assertStringNotContainsString($vault->secretKey(), $grant->sealed());
    }

    public function testDebugOutputHidesTheSealedKey(): void
    {
        $vault = Vault::create();
        $grant = VaultGrant::seal($vault, UserKeyPair::create()->publicKey());

        $dump = print_r($grant->__debugInfo(), true);

        self::assertStringNotContainsString(bin2hex($grant->sealed()), $dump);
    }
}
