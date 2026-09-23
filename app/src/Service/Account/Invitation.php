<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Domain\UserStatus;
use App\Repository\AuthTokenRepository;
use App\Repository\UserKeyRepository;
use App\Repository\UserRepository;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\UserKey;
use App\Service\Crypto\UserKeyPair;

/**
 * Inviting an account (docs/spec/01-sicherheit.md section 2
 * "Benutzer-Lebenszyklus", issue #20/M3-7):
 *
 *   admin enters address, name, roles -> invitation mail (link, 72 h)
 *   -> the invited person sets a password -> key pair is created
 *   -> "Tresor-Freigabe ausstehend" until an admin grants the vault.
 *
 * The account row exists from the invitation on, with status `eingeladen`
 * and no usable password hash - the login refuses it like a locked one
 * (User::mayLogIn()). The key pair is created only when the password is
 * set, because the password is what wraps it: nobody, the inviting admin
 * included, ever holds a private key for somebody else. The second factor
 * is set up at the first login - App\Http\LoginGuard forces it, as for
 * every account with `mfa_required`.
 *
 * The link token: 32 random bytes as hex, 72 hours, one use, only the
 * blind-index HMAC (purpose `auth_token.invite`) in the database - the same
 * shape as App\Service\Account\PasswordReset, in the same `auth_token`
 * table with type `invite`.
 *
 * Nothing about HTTP or mail here - the controller sends the link.
 */
final readonly class Invitation
{
    public const string TYP = 'invite';

    public const int TTL_SECONDS = 72 * 3600;

    public const int NAME_MAX = 100;

    /** Blind-index purpose of `auth_token.token_hash` for this type. */
    private const string HASH_PURPOSE = 'auth_token.invite';

    private UserRepository $users;

    private UserKeyRepository $userKeys;

    private AuthTokenRepository $tokens;

    public function __construct(
        private \PDO $pdo,
        private ServerCrypto $crypto,
        private PasswordHasher $passwords,
        private PasswordPolicy $policy,
        private AccessAssignment $zuweisung,
    ) {
        $this->users = new UserRepository($pdo);
        $this->userKeys = new UserKeyRepository($pdo);
        $this->tokens = new AuthTokenRepository($pdo);
    }

    /**
     * Creates the invited account with its roles and scopes and issues the
     * link. The roles go through AccessAssignment - the same rules as
     * changing them later (external accounts: end date, 2FA).
     *
     * @param list<int> $roleIds
     * @param list<int> $kostenstellen
     * @return array{userId: int, token: string}
     * @throws UserRuleViolation|RoleRuleViolation
     */
    public function invite(
        string $email,
        string $anzeigename,
        array $roleIds,
        array $kostenstellen = [],
        ?\DateTimeImmutable $von = null,
        ?\DateTimeImmutable $bis = null,
        ?\DateTimeImmutable $ablauf = null,
        ?\DateTimeImmutable $now = null,
    ): array {
        $now ??= new \DateTimeImmutable();
        $email = trim($email);
        $anzeigename = self::pruefeName($anzeigename);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255) {
            throw new UserRuleViolation('Bitte eine gültige E-Mail-Adresse angeben.');
        }
        if ($roleIds === []) {
            throw new UserRuleViolation('Bitte mindestens eine Rolle wählen.');
        }

        $emailBi = $this->crypto->blindIndex()->forValue('user.email', $email);
        if ($this->users->findByEmailBlindIndex($emailBi) !== null) {
            // The admin already has `admin.users` - telling them the address
            // exists enumerates nothing they could not see in the list.
            throw new UserRuleViolation('Für diese E-Mail-Adresse gibt es bereits einen Benutzer.');
        }

        // No password yet: an empty hash verifies against nothing, and the
        // status keeps the login away before it would even try.
        $userId = $this->users->insert(
            $this->crypto->encrypt($email),
            $emailBi,
            $this->crypto->encrypt($anzeigename),
            '',
            UserStatus::Eingeladen,
            now: $now,
        );

        // AccessAssignment runs its own transaction, so it cannot share one
        // with the insert above; a refused assignment takes the fresh row
        // back out instead (nothing else references it yet).
        try {
            $this->zuweisung->zuweisen($userId, $roleIds, $kostenstellen, $von, $bis, $ablauf, $now);
        } catch (\Throwable $e) {
            $this->users->delete($userId);
            throw $e;
        }

        return ['userId' => $userId, 'token' => $this->neuerLink($userId, $now)];
    }

    /**
     * A new link for an invitation nobody answered (lost mail, 72 hours
     * over). Replaces the old link instead of opening a second door.
     *
     * @throws UserRuleViolation
     */
    public function erneutSenden(int $userId, ?\DateTimeImmutable $now = null): string
    {
        $user = $this->users->findById($userId);
        if ($user === null || $user->status !== UserStatus::Eingeladen) {
            throw new UserRuleViolation('Dieser Benutzer wartet nicht auf eine Einladung.');
        }

        return $this->neuerLink($userId, $now ?? new \DateTimeImmutable());
    }

    /**
     * Whether a link is still good - for showing the form at all. Does not
     * spend the token.
     */
    public function isUsable(#[\SensitiveParameter] string $token, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();

        return $this->gueltigerEingeladener($token, $now) !== null;
    }

    /**
     * Sets the password, creates the key pair and activates the account.
     * The link is spent only once the password passes the policy.
     */
    public function complete(
        #[\SensitiveParameter] string $token,
        #[\SensitiveParameter] string $passwort,
        #[\SensitiveParameter] string $wiederholung,
        ?\DateTimeImmutable $now = null,
    ): PasswordResult {
        $now ??= new \DateTimeImmutable();
        $gefunden = $this->gueltigerEingeladener($token, $now);
        if ($gefunden === null) {
            return PasswordResult::fehler(self::linkUngueltig());
        }

        $fehler = PasswordChange::pruefeNeu($this->policy, $passwort, $wiederholung);
        if ($fehler !== []) {
            return PasswordResult::fehler(...$fehler);
        }

        [$tokenId, $user] = $gefunden;
        $schluessel = UserKey::wrap(UserKeyPair::create(), $passwort);

        $this->pdo->beginTransaction();
        try {
            if (!$this->tokens->markUsed($tokenId, $now)) {
                // Another request with the same link got here first.
                $this->pdo->rollBack();

                return PasswordResult::fehler(self::linkUngueltig());
            }

            if ($this->userKeys->forUser($user->id) === null) {
                $this->userKeys->insert($user->id, $schluessel, $now);
            } else {
                $this->userKeys->replace($user->id, $schluessel);
            }
            $this->users->updatePasswordHash($user->id, $this->passwords->hash($passwort));
            $this->users->updateStatus($user->id, UserStatus::Aktiv);
            $this->tokens->deleteForUser($user->id, self::TYP);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return PasswordResult::erfolg($user->id, $user->sessionEpoch);
    }

    public static function linkUngueltig(): string
    {
        return 'Dieser Einladungslink ist ungültig oder abgelaufen. Bitte bitten Sie die Vereinsverwaltung um eine neue Einladung.';
    }

    /**
     * @throws UserRuleViolation
     */
    public static function pruefeName(string $anzeigename): string
    {
        $anzeigename = trim($anzeigename);
        if ($anzeigename === '') {
            throw new UserRuleViolation('Bitte einen Namen angeben.');
        }
        if (mb_strlen($anzeigename) > self::NAME_MAX) {
            throw new UserRuleViolation(sprintf('Der Name darf höchstens %d Zeichen lang sein.', self::NAME_MAX));
        }

        return $anzeigename;
    }

    private function neuerLink(int $userId, \DateTimeImmutable $now): string
    {
        $token = bin2hex(random_bytes(32));
        $this->tokens->deleteForUser($userId, self::TYP);
        $this->tokens->insert(
            $userId,
            self::TYP,
            $this->hash($token),
            $now->modify('+' . self::TTL_SECONDS . ' seconds'),
            $now,
        );

        return $token;
    }

    /**
     * The token row and its account - only while the account is still
     * invited and has not run past an end date (an external invitation
     * could outlive its account).
     *
     * @return array{0: int, 1: \App\Domain\User}|null
     */
    private function gueltigerEingeladener(#[\SensitiveParameter] string $token, \DateTimeImmutable $now): ?array
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return null;
        }

        $zeile = $this->tokens->findUsable(self::TYP, $this->hash($token), $now);
        if ($zeile === null) {
            return null;
        }

        $user = $this->users->findById($zeile['user_id']);
        if (
            $user === null
            || $user->status !== UserStatus::Eingeladen
            || ($user->expiresAt !== null && $user->expiresAt <= $now)
        ) {
            return null;
        }

        return [$zeile['id'], $user];
    }

    private function hash(#[\SensitiveParameter] string $token): string
    {
        return $this->crypto->blindIndex()->forValue(self::HASH_PURPOSE, $token);
    }
}
