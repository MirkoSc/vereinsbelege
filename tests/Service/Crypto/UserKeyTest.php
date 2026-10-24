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
 * the MODERATE ones the spec asks for (issue #100), and that a row of the
 * older INTERACTIVE profile still opens, is checked separately.
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
     * docs/spec/01-sicherheit.md section 2 (issue #100): MODERATE - 256 MiB,
     * 3 passes, Argon2id v1.3. The hosting check measured 309-321 ms for it
     * on the target host.
     */
    public function testDefaultsAreTheModerateArgon2idParameters(): void
    {
        $defaults = KdfParameters::defaults();

        self::assertSame(SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE, $defaults->opsLimit);
        self::assertSame(SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE, $defaults->memLimit);
        self::assertSame(3, $defaults->opsLimit);
        self::assertSame(256 * 1024 * 1024, $defaults->memLimit);
        self::assertSame(SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13, $defaults->algorithm);
        self::assertSame(KdfParameters::SALT_BYTES, strlen($defaults->salt));
        self::assertNotSame($defaults->salt, KdfParameters::defaults()->salt);
        self::assertFalse($defaults->isWeakerThanDefaults());
    }

    /**
     * The acceptance criterion of issue #100: a row written with the
     * INTERACTIVE profile that was the default before still unwraps - with
     * the real parameters, not the cheap ones, read back as the repository
     * does.
     */
    public function testARowOfTheOlderInteractiveProfileStillUnwraps(): void
    {
        $pair = UserKeyPair::create();
        $old = UserKey::wrap($pair, self::PASSWORD, self::interactiveKdf());

        $fromDatabase = UserKey::fromStorage(
            $old->publicKey(),
            $old->wrappedPrivateKey(),
            KdfParameters::fromStorage(
                $old->kdf()->salt,
                SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
                SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
                SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
            ),
        );

        self::assertTrue($fromDatabase->kdf()->isWeakerThanDefaults());
        self::assertSame($pair->secretKey(), $fromDatabase->unwrap(self::PASSWORD)->secretKey());
    }

    /**
     * And the KEK really depends on the stored parameters: the same salt
     * with the current profile instead of the stored one does not open it.
     * That is why they have to be stored per row at all.
     */
    public function testAnOldRowDoesNotOpenWithTheCurrentProfile(): void
    {
        $old = UserKey::wrap(UserKeyPair::create(), self::PASSWORD, self::interactiveKdf());
        $misread = UserKey::fromStorage(
            $old->publicKey(),
            $old->wrappedPrivateKey(),
            KdfParameters::fromStorage($old->kdf()->salt, KdfParameters::DEFAULT_OPSLIMIT, KdfParameters::DEFAULT_MEMLIMIT),
        );

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('could not be unwrapped');

        $misread->unwrap(self::PASSWORD);
    }

    /**
     * The login lifts an old row (issue #100): same key pair - so the vault
     * grant keeps working -, same password, fresh salt, current profile.
     */
    public function testUpgradedWrappingLiftsAnOldRowToTheCurrentProfile(): void
    {
        $pair = UserKeyPair::create();
        $old = UserKey::wrap($pair, self::PASSWORD, self::interactiveKdf());

        $upgraded = $old->upgradedWrapping($old->unwrap(self::PASSWORD), self::PASSWORD);

        self::assertNotNull($upgraded);
        self::assertSame($pair->publicKey(), $upgraded->publicKey());
        self::assertSame(KdfParameters::DEFAULT_OPSLIMIT, $upgraded->kdf()->opsLimit);
        self::assertSame(KdfParameters::DEFAULT_MEMLIMIT, $upgraded->kdf()->memLimit);
        self::assertSame(KdfParameters::ALGORITHM, $upgraded->kdf()->algorithm);
        self::assertNotSame($old->kdf()->salt, $upgraded->kdf()->salt);
        self::assertFalse($upgraded->kdf()->isWeakerThanDefaults());
        self::assertSame($pair->secretKey(), $upgraded->unwrap(self::PASSWORD)->secretKey());
    }

    public function testUpgradedWrappingLeavesACurrentRowAlone(): void
    {
        $pair = UserKeyPair::create();
        $current = KdfParameters::fromStorage(
            random_bytes(KdfParameters::SALT_BYTES),
            KdfParameters::DEFAULT_OPSLIMIT,
            KdfParameters::DEFAULT_MEMLIMIT,
        );
        // No derivation needed to decide this - the pair is passed in.
        $key = UserKey::fromStorage($pair->publicKey(), str_repeat("\x01", 73), $current);

        self::assertNull($key->upgradedWrapping($pair, self::PASSWORD));
    }

    /**
     * Lowering the profile in code must not weaken accounts that already
     * have more.
     */
    public function testStrongerThanCurrentParametersAreNotWeaker(): void
    {
        $stronger = KdfParameters::fromStorage(
            random_bytes(KdfParameters::SALT_BYTES),
            KdfParameters::DEFAULT_OPSLIMIT + 1,
            KdfParameters::DEFAULT_MEMLIMIT * 2,
        );

        self::assertFalse($stronger->isWeakerThanDefaults());
        self::assertTrue(KdfParameters::fromStorage(
            random_bytes(KdfParameters::SALT_BYTES),
            KdfParameters::DEFAULT_OPSLIMIT + 1,
            KdfParameters::DEFAULT_MEMLIMIT - 1,
        )->isWeakerThanDefaults(), 'more passes do not make up for less memory');
    }

    public function testUpgradedWrappingRejectsAForeignKeyPair(): void
    {
        $old = UserKey::wrap(UserKeyPair::create(), self::PASSWORD, self::cheapKdf());

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('does not belong');

        $old->upgradedWrapping(UserKeyPair::create(), self::PASSWORD);
    }

    public function testTheAlgorithmIsReadBackFromStorage(): void
    {
        $kdf = KdfParameters::fromStorage(
            random_bytes(KdfParameters::SALT_BYTES),
            KdfParameters::OPSLIMIT_MIN,
            KdfParameters::MEMLIMIT_MIN,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );

        self::assertSame(SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13, $kdf->algorithm);
        self::assertSame(SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13, $kdf->raisedToDefaults()->algorithm);
    }

    /**
     * Raising works per factor: what a row lacks is raised, what it has more
     * of is kept - an upgrade never lowers a cost factor.
     */
    public function testRaisingKeepsTheStrongerFactor(): void
    {
        $mehrDurchlaeufe = KdfParameters::fromStorage(
            random_bytes(KdfParameters::SALT_BYTES),
            KdfParameters::DEFAULT_OPSLIMIT + 1,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
        )->raisedToDefaults();
        $mehrSpeicher = KdfParameters::fromStorage(
            random_bytes(KdfParameters::SALT_BYTES),
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            KdfParameters::DEFAULT_MEMLIMIT * 2,
        )->raisedToDefaults();

        self::assertSame(KdfParameters::DEFAULT_OPSLIMIT + 1, $mehrDurchlaeufe->opsLimit);
        self::assertSame(KdfParameters::DEFAULT_MEMLIMIT, $mehrDurchlaeufe->memLimit);
        self::assertSame(KdfParameters::DEFAULT_OPSLIMIT, $mehrSpeicher->opsLimit);
        self::assertSame(KdfParameters::DEFAULT_MEMLIMIT * 2, $mehrSpeicher->memLimit);
        self::assertFalse($mehrDurchlaeufe->isWeakerThanDefaults());
        self::assertFalse($mehrSpeicher->isWeakerThanDefaults());
    }

    public function testRaisingAlwaysTakesAFreshSalt(): void
    {
        $kdf = KdfParameters::defaults();

        self::assertNotSame($kdf->salt, $kdf->raisedToDefaults()->salt);
        self::assertSame($kdf->opsLimit, $kdf->raisedToDefaults()->opsLimit);
        self::assertSame($kdf->memLimit, $kdf->raisedToDefaults()->memLimit);
    }

    /**
     * Argon2i would derive a different key and look like a wrong password;
     * an unknown identifier is said as such instead.
     */
    public function testAnUnknownAlgorithmIsRejected(): void
    {
        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('Unknown KDF algorithm');

        KdfParameters::fromStorage(
            random_bytes(KdfParameters::SALT_BYTES),
            KdfParameters::OPSLIMIT_MIN,
            KdfParameters::MEMLIMIT_MIN,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2I13,
        );
    }

    /**
     * A password change also lifts an INTERACTIVE row, not only the cheap
     * test parameters below.
     */
    public function testRewrapRaisesAnInteractiveRowToTheCurrentProfile(): void
    {
        $old = UserKey::wrap(UserKeyPair::create(), self::PASSWORD, self::interactiveKdf());

        $rewrapped = $old->rewrap(self::PASSWORD, 'neues-passwort-mit-genug-laenge');

        self::assertFalse($rewrapped->kdf()->isWeakerThanDefaults());
        self::assertSame(KdfParameters::DEFAULT_MEMLIMIT, $rewrapped->kdf()->memLimit);
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

    /**
     * The profile every wrapping was written with before issue #100.
     */
    private static function interactiveKdf(): KdfParameters
    {
        return KdfParameters::fromStorage(
            random_bytes(KdfParameters::SALT_BYTES),
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
        );
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
