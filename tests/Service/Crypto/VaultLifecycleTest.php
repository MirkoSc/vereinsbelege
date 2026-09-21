<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto;

use App\Service\Crypto\CryptoException;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\KdfParameters;
use App\Service\Crypto\RecoveryKey;
use App\Service\Crypto\UserKey;
use App\Service\Crypto\UserKeyPair;
use App\Service\Crypto\Vault;
use App\Service\Crypto\VaultGrant;
use PHPUnit\Framework\TestCase;

/**
 * The user lifecycle of docs/spec/01-sicherheit.md section 2 ("Pflicht-Tests":
 * invitation -> grant -> password change -> reset -> new grant, including
 * "no grant, no decrypting"), played through on the service layer alone -
 * the tables behind it (`user_key`, `vault`, `vault_grant`) arrive with M3.
 *
 * The array in this test is what the database would hold. Everything in it is
 * either public (a public key), sealed or wrapped; nothing in it can be read
 * without a password.
 */
final class VaultLifecycleTest extends TestCase
{
    private const string PASSWORD = 'erstes-passwort-der-kassiererin';

    public function testFromInvitationToRecovery(): void
    {
        // The installation: a vault, its public key stored in the clear.
        $vault = Vault::create();
        $vaultPublicKey = $vault->publicKey();
        $recoveryKey = RecoveryKey::forVault($vault)->formatted();

        // A receipt comes in through the public submission: sealing the data
        // key needs the vault public key only, no secret at all.
        $context = new FieldContext('document', 42, 'total_enc');
        $dataKey = DataKey::generate();
        $row = [
            'dek_sealed' => Vault::locked($vaultPublicKey)->sealDataKey($dataKey),
            'total_enc' => FieldCipher::encrypt($dataKey, '1290', $context),
        ];

        // Invitation: the user sets a password, their key pair is created and
        // stored wrapped. Status: "Tresor-Freigabe ausstehend".
        $pair = UserKeyPair::create();
        $userKey = UserKey::wrap($pair, self::PASSWORD, self::cheapKdf());
        unset($pair);

        // Without a grant there is nothing to unlock the vault with, however
        // correct the password is: the user is logged in and still reads
        // nothing.
        $loggedIn = $userKey->unwrap(self::PASSWORD);
        self::assertTrue($loggedIn->isUnlocked());

        try {
            Vault::locked($vaultPublicKey)->openDataKey($row['dek_sealed']);
            self::fail('Without a grant the vault stays locked.');
        } catch (CryptoException $e) {
            self::assertStringContainsString('locked', $e->getMessage());
        }

        // An admin approves: the vault private key is sealed to the user.
        $grant = VaultGrant::seal($vault, $userKey->publicKey());

        $unlockedVault = $grant->open($userKey->unwrap(self::PASSWORD), $vaultPublicKey);
        self::assertSame(
            '1290',
            FieldCipher::decrypt($unlockedVault->openDataKey($row['dek_sealed']), $row['total_enc'], $context),
        );
        // The blind index key hangs off the vault too, so the same value is
        // found again only from inside an unlocked session.
        self::assertSame(
            $vault->blindIndex()->forValue('supplier.iban', 'DE02 1203 0000 0000 2020 51'),
            $unlockedVault->blindIndex()->forValue('supplier.iban', 'DE02 1203 0000 0000 2020 51'),
        );

        // Password change with the old password known: only the wrapping is
        // renewed, the key pair and therefore the grant stay valid.
        $changed = $userKey->rewrap(self::PASSWORD, 'zweites-passwort-der-kassiererin');
        self::assertSame($userKey->publicKey(), $changed->publicKey());
        self::assertSame(
            $vault->secretKey(),
            $grant->open($changed->unwrap('zweites-passwort-der-kassiererin'), $vaultPublicKey)->secretKey(),
        );

        // Password forgotten: the reset creates a new key pair, the old grant
        // is deleted - and the old grant cannot help the new pair either.
        $afterReset = UserKey::wrap(UserKeyPair::create(), 'drittes-passwort-nach-reset', self::cheapKdf());
        $newPair = $afterReset->unwrap('drittes-passwort-nach-reset');

        try {
            $grant->open($newPair, $vaultPublicKey);
            self::fail('The grant of the old key pair must not open for the new one.');
        } catch (CryptoException $e) {
            self::assertStringContainsString('could not be opened', $e->getMessage());
        }

        // An admin approves again, and the user is back in.
        $newGrant = VaultGrant::seal($unlockedVault, $afterReset->publicKey());
        self::assertSame(
            '1290',
            FieldCipher::decrypt(
                $newGrant->open($newPair, $vaultPublicKey)->openDataKey($row['dek_sealed']),
                $row['total_enc'],
                $context,
            ),
        );

        // Every admin has forgotten their password: the paper key still opens
        // the vault, without any grant at all.
        self::assertSame(
            '1290',
            FieldCipher::decrypt(
                RecoveryKey::parse($recoveryKey)->openVault($vaultPublicKey)->openDataKey($row['dek_sealed']),
                $row['total_enc'],
                $context,
            ),
        );
    }

    /**
     * The acceptance criterion of the issue, end to end: none of the values
     * that go to disk or into the database contains the vault private key,
     * the user private key or the password.
     */
    public function testNothingStorableContainsAKeyInTheClear(): void
    {
        $vault = Vault::create();
        $pair = UserKeyPair::create();
        $userKey = UserKey::wrap($pair, self::PASSWORD, self::cheapKdf());
        $grant = VaultGrant::seal($vault, $userKey->publicKey());
        $dataKey = DataKey::generate();

        $stored = [
            'vault.public_key' => $vault->publicKey(),
            'user_key.public_key' => $userKey->publicKey(),
            'user_key.wrapped_private_key' => $userKey->wrappedPrivateKey(),
            'user_key.kdf_salt' => $userKey->kdf()->salt,
            'vault_grant.sealed_private_key' => $grant->sealed(),
            'document.dek_sealed' => $vault->sealDataKey($dataKey),
            'document.total_enc' => FieldCipher::encrypt($dataKey, '1290', new FieldContext('document', 1, 'total_enc')),
        ];

        foreach ($stored as $column => $value) {
            self::assertStringNotContainsString($vault->secretKey(), $value, $column);
            self::assertStringNotContainsString($pair->secretKey(), $value, $column);
            self::assertStringNotContainsString($dataKey->raw(), $value, $column);
            self::assertStringNotContainsString(self::PASSWORD, $value, $column);
        }
    }

    /**
     * What a database reader holds in their hands: public keys, sealed boxes,
     * wrapped keys - and no way from any of them to the plaintext.
     */
    public function testADatabaseReaderCannotDecryptAnything(): void
    {
        $vault = Vault::create();
        $context = new FieldContext('document', 7, 'total_enc');
        $dataKey = DataKey::generate();
        $sealedDataKey = Vault::locked($vault->publicKey())->sealDataKey($dataKey);
        $encrypted = FieldCipher::encrypt($dataKey, '1290', $context);

        // A wrong data key does not open the field either - the reader cannot
        // work around the vault by guessing at the row.
        try {
            FieldCipher::decrypt(DataKey::generate(), $encrypted, $context);
            self::fail('A foreign data key must not decrypt the field.');
        } catch (CryptoException $e) {
            self::assertStringContainsString('could not be decrypted', $e->getMessage());
        }

        // All the reader can rebuild from the `vault` row is a locked vault.
        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('locked');

        Vault::locked($vault->publicKey())->openDataKey($sealedDataKey);
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
