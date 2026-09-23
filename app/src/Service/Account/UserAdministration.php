<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Domain\Permission;
use App\Domain\User;
use App\Domain\UserStatus;
use App\Repository\UserAccessRepository;
use App\Repository\UserKeyRepository;
use App\Repository\UserRepository;
use App\Repository\VaultGrantRepository;
use App\Repository\VaultRepository;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Crypto\VaultGrant;

/**
 * What an admin does to an existing account (issue #20/M3-7,
 * docs/spec/01-sicherheit.md section 2 "Benutzer-Lebenszyklus"): lock,
 * unlock, rename - and the vault grants, which are the reason this is more
 * than a status column.
 *
 * - **Freigeben** seals VK_priv to the account's public key. That needs
 *   VK_priv, so it needs the admin's own unlocked vault - from the admin's
 *   session, handed in by the controller. A server that could grant without
 *   one would hold the vault key itself.
 * - **Entziehen** deletes the grant row, and raises `user.session_epoch`:
 *   a running session of that account still carries VK_priv (sealed to its
 *   cookie), and "entzogen" must not mean "from the next login on".
 * - **Sperren** sets the status, deletes the grant (spec: "Sperren/Entfernen:
 *   Grant-Zeile löschen") and ends every session. Unlocking brings the
 *   account back WITHOUT its grant - it is "Freigabe ausstehend" again, and
 *   an admin decides afresh.
 *
 * Two guards against locking the club out of itself: nobody locks or
 * revokes their own account from here, and the last active account that
 * can manage accounts (`admin.users`) or grant the vault
 * (`admin.vault_grant` with a grant of its own) stays as it is.
 *
 * Nothing about HTTP or mail here - the controllers send the notices.
 */
final readonly class UserAdministration
{
    private UserRepository $users;

    private UserKeyRepository $userKeys;

    private VaultGrantRepository $grants;

    private VaultRepository $vaults;

    private UserAccessRepository $zugriff;

    public function __construct(
        private \PDO $pdo,
        private ServerCrypto $crypto,
    ) {
        $this->users = new UserRepository($pdo);
        $this->userKeys = new UserKeyRepository($pdo);
        $this->grants = new VaultGrantRepository($pdo);
        $this->vaults = new VaultRepository($pdo);
        $this->zugriff = new UserAccessRepository($pdo);
    }

    /**
     * @throws UserRuleViolation
     */
    public function umbenennen(int $userId, string $anzeigename): void
    {
        $this->konto($userId);
        $this->users->updateDisplayName($userId, $this->crypto->encrypt(Invitation::pruefeName($anzeigename)));
    }

    /**
     * @throws UserRuleViolation
     */
    public function sperren(int $userId, int $durch, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();
        $konto = $this->konto($userId);
        if ($userId === $durch) {
            throw new UserRuleViolation('Das eigene Konto lässt sich nicht sperren.');
        }
        if ($konto->status === UserStatus::Gesperrt) {
            return;
        }
        $this->bleibtVerwaltbar($userId, $now);

        $this->pdo->beginTransaction();
        try {
            $this->users->updateStatus($userId, UserStatus::Gesperrt);
            $this->grants->deleteForUser($userId);
            $this->users->bumpSessionEpoch($userId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Back to `aktiv` - without the grant the lock took away.
     *
     * @throws UserRuleViolation
     */
    public function entsperren(int $userId): void
    {
        $konto = $this->konto($userId);
        if ($konto->status !== UserStatus::Gesperrt) {
            throw new UserRuleViolation('Dieses Konto ist nicht gesperrt.');
        }

        // An invitation that was locked before anybody answered it has no
        // password - it goes back to waiting for the invitation, not to a
        // login that could never succeed.
        $this->users->updateStatus(
            $userId,
            $this->userKeys->forUser($userId) === null ? UserStatus::Eingeladen : UserStatus::Aktiv,
        );
    }

    /**
     * Seals the vault to the account's public key.
     *
     * @param Vault $tresor the granting admin's unlocked vault, from their session
     * @throws UserRuleViolation
     */
    public function freigeben(int $userId, Vault $tresor, int $durch, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();
        $konto = $this->konto($userId);
        $aktuell = $this->vaults->current();

        if (!$tresor->isUnlocked() || $aktuell === null || !hash_equals($aktuell->publicKey(), $tresor->publicKey())) {
            throw new UserRuleViolation('Ihr Tresor ist in dieser Sitzung nicht entsperrt. Bitte melden Sie sich neu an.');
        }
        if (!$konto->mayLogIn($now)) {
            throw new UserRuleViolation('Gesperrte, eingeladene oder abgelaufene Konten können keine Freigabe erhalten.');
        }
        $schluessel = $this->userKeys->forUser($userId)
            ?? throw new UserRuleViolation('Dieses Konto hat noch kein Passwort festgelegt.');
        if (!in_array($userId, $this->grants->pendingUserIds($aktuell->version, $now), true)) {
            throw new UserRuleViolation('Dieses Konto ist bereits freigegeben.');
        }

        try {
            $grant = VaultGrant::seal($tresor, $schluessel->publicKey());
        } catch (CryptoException) {
            throw new UserRuleViolation('Die Freigabe konnte nicht erzeugt werden.');
        }

        $this->grants->insert($userId, $grant, $durch, $now);
    }

    /**
     * @throws UserRuleViolation
     */
    public function entziehen(int $userId, int $durch, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();
        $this->konto($userId);
        if ($userId === $durch) {
            throw new UserRuleViolation('Die eigene Freigabe lässt sich nicht entziehen.');
        }
        $this->bleibtVerwaltbar($userId, $now);

        $this->pdo->beginTransaction();
        try {
            $this->grants->deleteForUser($userId);
            $this->users->bumpSessionEpoch($userId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Accounts waiting for a grant of the current vault generation.
     *
     * @return list<int>
     */
    public function ausstehend(?\DateTimeImmutable $now = null): array
    {
        $aktuell = $this->vaults->current();

        return $aktuell === null ? [] : $this->grants->pendingUserIds($aktuell->version, $now ?? new \DateTimeImmutable());
    }

    /**
     * Who is told that a grant is waiting ("Admins bekommen dazu eine Mail",
     * docs/spec/01-sicherheit.md section 2): every active account that may
     * grant AND can, i.e. holds a grant itself.
     *
     * @return list<User>
     */
    public function freigeber(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $aktuell = $this->vaults->current();
        if ($aktuell === null) {
            return [];
        }
        $mitGrant = $this->grants->grantsFor($aktuell->version);

        return array_values(array_filter(
            $this->users->all(),
            fn(User $u): bool => $u->mayLogIn($now)
                && isset($mitGrant[$u->id])
                && $this->zugriff->berechtigungen($u->id)->darf(Permission::AdminVaultGrant),
        ));
    }

    /**
     * @throws UserRuleViolation
     */
    private function konto(int $userId): User
    {
        return $this->users->findById($userId) ?? throw new UserRuleViolation('Diesen Benutzer gibt es nicht.');
    }

    /**
     * Refuses when taking $userId out would leave no active account that can
     * manage accounts, or none that can still grant the vault.
     *
     * @throws UserRuleViolation
     */
    private function bleibtVerwaltbar(int $userId, \DateTimeImmutable $now): void
    {
        $aktuell = $this->vaults->current();
        $mitGrant = $aktuell === null ? [] : $this->grants->grantsFor($aktuell->version);

        $verwalter = false;
        $freigeber = false;
        foreach ($this->users->all() as $u) {
            if ($u->id === $userId || !$u->mayLogIn($now)) {
                continue;
            }
            $rechte = $this->zugriff->berechtigungen($u->id);
            $verwalter = $verwalter || $rechte->darf(Permission::AdminUsers);
            $freigeber = $freigeber || (isset($mitGrant[$u->id]) && $rechte->darf(Permission::AdminVaultGrant));
        }

        if (!$verwalter) {
            throw new UserRuleViolation('Mindestens ein aktives Konto muss Benutzer verwalten dürfen.');
        }
        if (!$freigeber && isset($mitGrant[$userId])) {
            throw new UserRuleViolation('Mindestens ein aktives Konto mit eigener Freigabe muss den Tresor freigeben dürfen.');
        }
    }
}
