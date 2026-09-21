<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto;

use App\Service\Crypto\CryptoException;
use App\Service\Crypto\KdfParameters;
use App\Service\Crypto\UserKey;
use App\Service\Crypto\UserKeyPair;
use PHPUnit\Framework\TestCase;

/**
 * Wrapping a user's private key with a KEK derived from their password
 * (docs/spec/01-sicherheit.md section 2).
 *
 * Most tests use the cheapest KDF parameters libsodium allows: what they are
 * about is the wrapping, not the cost factor. That the shipped defaults are
 * the INTERACTIVE ones the spec asks for is checked separately, once.
 */
final class UserKeyTest extends TestCase
{
    private const string PASSWORD = 'korrekt-pferd-batterie-heftklammer';

    public function testWrapAndUnwrapRoundTrip(): void
    {
        $pair = UserKeyPair::create();

        $unwrapped = UserKey::wrap($pair, self::PASSWORD, self::cheapKdf())->unwrap(self::PASSWORD);

        self::assertTrue($unwrapped->isUnlocked());
        self::assertSame($pair->publicKey(), $unwrapped->publicKey());
        self::assertSame($pair->secretKey(), $unwrapped->secretKey());
    }

    public function testWrongPasswordDoesNotUnwrap(): void
    {
        $key = UserKey::wrap(UserKeyPair::create(), self::PASSWORD, self::cheapKdf());

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('could not be unwrapped');

        $key->unwrap(self::PASSWORD . '!');
    }

    /**
     * The KDF parameters live in their own columns, so a row written with
     * other cost factors still opens - that is what allows the defaults to be
     * raised later without locking anybody out.
     */
    public function testUnwrapsWithTheParametersStoredNextToTheKey(): void
    {
        $pair = UserKeyPair::create();
        $key = UserKey::wrap($pair, self::PASSWORD, self::cheapKdf());

        $fromDatabase = UserKey::fromStorage(
            $key->publicKey(),
            $key->wrappedPrivateKey(),
            KdfParameters::fromStorage($key->kdf()->salt, $key->kdf()->opsLimit, $key->kdf()->memLimit),
        );

        self::assertSame($pair->secretKey(), $fromDatabase->unwrap(self::PASSWORD)->secretKey());
    }

    public function testAnotherSaltDoesNotUnwrap(): void
    {
        $key = UserKey::wrap(UserKeyPair::create(), self::PASSWORD, self::cheapKdf());
        $otherSalt = UserKey::fromStorage(
            $key->publicKey(),
            $key->wrappedPrivateKey(),
            KdfParameters::fromStorage(random_bytes(KdfParameters::SALT_BYTES), $key->kdf()->opsLimit, $key->kdf()->memLimit),
        );

        $this->expectException(CryptoException::class);

        $otherSalt->unwrap(self::PASSWORD);
    }

    /**
     * "Passwort ändern (altes bekannt): U_priv neu wrappen, sonst nichts" -
     * the key pair survives, so the vault grant of that user keeps working.
     */
    public function testRewrapKeepsTheKeyPairAndChangesTheSalt(): void
    {
        $pair = UserKeyPair::create();
        $key = UserKey::wrap($pair, self::PASSWORD, self::cheapKdf());

        $rewrapped = $key->rewrap(self::PASSWORD, 'neues-passwort-mit-genug-laenge');

        self::assertSame($pair->publicKey(), $rewrapped->publicKey());
        self::assertSame($pair->secretKey(), $rewrapped->unwrap('neues-passwort-mit-genug-laenge')->secretKey());
        self::assertNotSame($key->kdf()->salt, $rewrapped->kdf()->salt);
        self::assertNotSame($key->wrappedPrivateKey(), $rewrapped->wrappedPrivateKey());
    }

    public function testRewrapNeedsTheOldPassword(): void
    {
        $key = UserKey::wrap(UserKeyPair::create(), self::PASSWORD, self::cheapKdf());

        $this->expectException(CryptoException::class);

        $key->rewrap('falsches-altes-passwort', 'neues-passwort-mit-genug-laenge');
    }

    /**
     * A row written when the defaults were weaker is re-wrapped at the
     * current cost, not at the old one.
     */
    public function testRewrapRaisesWeakParametersToTheDefaults(): void
    {
        $key = UserKey::wrap(UserKeyPair::create(), self::PASSWORD, self::cheapKdf());

        $rewrapped = $key->rewrap(self::PASSWORD, self::PASSWORD);

        self::assertSame(KdfParameters::defaults()->opsLimit, $rewrapped->kdf()->opsLimit);
        self::assertSame(KdfParameters::defaults()->memLimit, $rewrapped->kdf()->memLimit);
    }

    public function testManipulatedValueIsRejected(): void
    {
        $key = UserKey::wrap(UserKeyPair::create(), self::PASSWORD, self::cheapKdf());
        $wrapped = $key->wrappedPrivateKey();
        $wrapped[30] = $wrapped[30] === "\x00" ? "\x01" : "\x00";

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('could not be unwrapped');

        UserKey::fromStorage($key->publicKey(), $wrapped, $key->kdf())->unwrap(self::PASSWORD);
    }

    public function testUnknownFormatVersionIsRejected(): void
    {
        $key = UserKey::wrap(UserKeyPair::create(), self::PASSWORD, self::cheapKdf());
        $wrapped = $key->wrappedPrivateKey();
        $wrapped[0] = chr(UserKey::VERSION + 1);

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('unknown format version');

        UserKey::fromStorage($key->publicKey(), $wrapped, $key->kdf())->unwrap(self::PASSWORD);
    }

    public function testTruncatedValueIsRejected(): void
    {
        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('too short');

        UserKey::fromStorage(UserKeyPair::create()->publicKey(), 'zu kurz', self::cheapKdf())->unwrap(self::PASSWORD);
    }

    /**
     * The acceptance criterion of the issue, for the user level: what is
     * stored is the wrapped key and nothing else.
     */
    public function testStoredValueDoesNotContainTheSecretKey(): void
    {
        $pair = UserKeyPair::create();

        $key = UserKey::wrap($pair, self::PASSWORD, self::cheapKdf());

        self::assertStringNotContainsString($pair->secretKey(), $key->wrappedPrivateKey());
        self::assertStringNotContainsString($pair->secretKey(), $key->kdf()->salt);
    }

    public function testDebugOutputHidesTheSecrets(): void
    {
        $pair = UserKeyPair::create();
        $key = UserKey::wrap($pair, self::PASSWORD, self::cheapKdf());

        $dump = print_r([$pair->__debugInfo(), $key->__debugInfo()], true);

        self::assertStringNotContainsString(bin2hex($pair->secretKey()), $dump);
        self::assertStringNotContainsString(bin2hex($key->wrappedPrivateKey()), $dump);
    }

    public function testMismatchedKeyPairIsRejected(): void
    {
        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('do not belong together');

        UserKeyPair::unlocked(UserKeyPair::create()->publicKey(), UserKeyPair::create()->secretKey());
    }

    public function testLockedPairHasNoSecretKey(): void
    {
        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('locked');

        UserKeyPair::locked(UserKeyPair::create()->publicKey())->secretKey();
    }

    public function testKeysOfTheWrongLengthAreRejected(): void
    {
        $this->expectException(CryptoException::class);

        UserKeyPair::locked('zu kurz');
    }

    /**
     * docs/spec/01-sicherheit.md section 2: INTERACTIVE / 64 MiB, and the
     * hosting check confirmed the target host manages it in ~55 ms.
     */
    public function testDefaultsAreTheInteractiveArgon2idParameters(): void
    {
        $defaults = KdfParameters::defaults();

        self::assertSame(SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, $defaults->opsLimit);
        self::assertSame(SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE, $defaults->memLimit);
        self::assertSame(64 * 1024 * 1024, $defaults->memLimit);
        self::assertSame(KdfParameters::SALT_BYTES, strlen($defaults->salt));
        self::assertNotSame($defaults->salt, KdfParameters::defaults()->salt);
    }

    /**
     * The real parameters, once end to end - a login has to survive them.
     */
    public function testWrappingWithTheDefaultsWorks(): void
    {
        $pair = UserKeyPair::create();

        $key = UserKey::wrap($pair, self::PASSWORD);

        self::assertSame($pair->secretKey(), $key->unwrap(self::PASSWORD)->secretKey());
    }

    public function testParametersBelowTheLibsodiumMinimumAreRejected(): void
    {
        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('ops limit');

        KdfParameters::fromStorage(random_bytes(KdfParameters::SALT_BYTES), 0, KdfParameters::MEMLIMIT_MIN);
    }

    public function testASaltOfTheWrongLengthIsRejected(): void
    {
        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('salt');

        KdfParameters::fromStorage('zu kurz', KdfParameters::OPSLIMIT_MIN, KdfParameters::MEMLIMIT_MIN);
    }

    private static function cheapKdf(): KdfParameters
    {
        return KdfParameters::fromStorage(
            random_bytes(KdfParameters::SALT_BYTES),
            KdfParameters::OPSLIMIT_MIN,
            KdfParameters::MEMLIMIT_MIN,
        );
    }
}
