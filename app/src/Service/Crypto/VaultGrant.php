<?php

declare(strict_types=1);

namespace App\Service\Crypto;

/**
 * A `vault_grant` row (docs/spec/02-datenmodell.md): the vault private key,
 * sealed to one user's public key (docs/spec/01-sicherheit.md section 2).
 *
 * Stored form of `sealed_private_key`:
 *
 *     vault version (1 byte) | box_seal(VK_priv, U_pub)
 *
 * This is the hinge of the whole vault model (E-01): the private key exists
 * on the server only in this form, readable by nobody but the user whose key
 * pair it was sealed to - and that key pair in turn only opens with their
 * password. Somebody who reads the database or the FTP space gets sealed
 * boxes and nothing else.
 *
 * Granting is an admin action with an unlocked vault; revoking a user is
 * deleting their row here, which costs nothing and takes effect at once.
 */
final readonly class VaultGrant
{
    private function __construct(
        private string $sealed,
        public int $vaultVersion,
    ) {
    }

    /**
     * Seals the vault private key to a user. Needs an unlocked vault, so only
     * an admin who is logged in can hand out access.
     *
     * @param string $userPublicKey `user_key.public_key` of the user being granted access
     */
    public static function seal(Vault $vault, string $userPublicKey): self
    {
        if (strlen($userPublicKey) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new CryptoException('Not a user public key.');
        }

        return new self(
            chr($vault->version) . sodium_crypto_box_seal($vault->secretKey(), $userPublicKey),
            $vault->version,
        );
    }

    /**
     * The row as read back from the database.
     */
    public static function fromStorage(string $sealed): self
    {
        if (strlen($sealed) <= 1) {
            throw new CryptoException('The sealed vault key is too short.');
        }

        return new self($sealed, ord($sealed[0]));
    }

    /**
     * The `sealed_private_key` column. The `vault_version` column mirrors the
     * leading byte so that SQL can find the grants of one generation.
     */
    public function sealed(): string
    {
        return $this->sealed;
    }

    /**
     * Opens the grant with the user's key pair - what happens right after a
     * successful login (docs/spec/01-sicherheit.md, "Session-Entsperrung").
     *
     * @param string $vaultPublicKey `vault.public_key`, checked against the key that comes out
     *
     * @throws CryptoException when the pair is locked, the grant belongs to
     *                         another generation or another user, or the key
     *                         does not belong to this vault
     */
    public function open(UserKeyPair $user, string $vaultPublicKey, int $expectedVaultVersion = Vault::FIRST_VERSION): Vault
    {
        if ($this->vaultVersion !== $expectedVaultVersion) {
            throw new CryptoException(sprintf(
                'The grant belongs to vault version %d, this vault is version %d.',
                $this->vaultVersion,
                $expectedVaultVersion,
            ));
        }

        $secretKey = sodium_crypto_box_seal_open(
            substr($this->sealed, 1),
            sodium_crypto_box_keypair_from_secretkey_and_publickey($user->secretKey(), $user->publicKey()),
        );

        if ($secretKey === false) {
            throw new CryptoException('The sealed vault key could not be opened.');
        }

        if (strlen($secretKey) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
            throw new CryptoException('The grant does not contain a vault secret key.');
        }

        if (!hash_equals($vaultPublicKey, sodium_crypto_box_publickey_from_secretkey($secretKey))) {
            throw new CryptoException('The grant belongs to another vault.');
        }

        return Vault::unlocked($vaultPublicKey, $secretKey, $this->vaultVersion);
    }

    /**
     * @return array<string, string|int>
     */
    public function __debugInfo(): array
    {
        return [
            'vaultVersion' => $this->vaultVersion,
            'sealed' => '*** sealed vault secret key ***',
        ];
    }
}
