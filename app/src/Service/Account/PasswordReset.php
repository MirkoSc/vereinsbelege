<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Repository\AuthTokenRepository;
use App\Repository\UserKeyRepository;
use App\Repository\UserRepository;
use App\Repository\VaultGrantRepository;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\UserKey;
use App\Service\Crypto\UserKeyPair;
use App\Service\RateLimiter;

/**
 * "Passwort vergessen" (docs/spec/01-sicherheit.md section 2
 * "Benutzer-Lebenszyklus", section 3 "Passwort-Reset", issue #18/M3-5).
 *
 * The one thing that makes this different from every other reset flow: the
 * old password was the only way to the user's private key, so a reset cannot
 * keep it. The account gets a NEW key pair, its vault grants are deleted,
 * and until an admin seals the vault to the new public key again the user
 * signs in and sees nothing (VaultAccess::KeineFreigabe). That is the whole
 * point of the vault model - a mailbox is enough to get an account back, it
 * is not enough to read the receipts - and the pages say so before anybody
 * commits to it.
 *
 * The link token: 32 random bytes as hex, 30 minutes, one use, only the
 * blind-index HMAC (purpose `auth_token.reset`) in the database. Completing
 * a reset ends every session of the account (`user.session_epoch`).
 *
 * Nothing about HTTP or mail here - the controller sends the link, the same
 * split as LoginService.
 */
final readonly class PasswordReset
{
    public const string TYP = 'reset';

    public const int TTL_SECONDS = 1800;

    /** Blind-index purpose of `auth_token.token_hash` for this type. */
    private const string HASH_PURPOSE = 'auth_token.reset';

    private const string LIMIT_IP = 'reset.ip';

    private const string LIMIT_ACCOUNT = 'reset.account';

    /** Requests per IP per window - generous for a club's shared connection. */
    public const int LIMIT_PER_IP = 10;

    /** Requests per address per window: enough for a lost mail, not for a mail bomb. */
    public const int LIMIT_PER_ACCOUNT = 3;

    private UserRepository $users;

    private UserKeyRepository $userKeys;

    private VaultGrantRepository $grants;

    private AuthTokenRepository $tokens;

    public function __construct(
        private \PDO $pdo,
        private ServerCrypto $crypto,
        private PasswordHasher $passwords,
        private PasswordPolicy $policy,
        private RateLimiter $limits,
    ) {
        $this->users = new UserRepository($pdo);
        $this->userKeys = new UserKeyRepository($pdo);
        $this->grants = new VaultGrantRepository($pdo);
        $this->tokens = new AuthTokenRepository($pdo);
    }

    /**
     * Issues a link for the account behind $email, if there is one that may
     * log in at all. Every request counts against the limits - not only
     * "failures", as for the login: here each request can send a mail, and
     * that is what needs bounding.
     */
    public function request(string $email, string $ip, ?\DateTimeImmutable $now = null): PasswordResetAnfrage
    {
        $now ??= new \DateTimeImmutable();
        $email = trim($email);
        $emailBi = $this->crypto->blindIndex()->forValue('user.email', $email);
        $konto = bin2hex($emailBi);

        if (
            $this->limits->isBlocked(self::LIMIT_IP, $ip, self::LIMIT_PER_IP, $now)
            || $this->limits->isBlocked(self::LIMIT_ACCOUNT, $konto, self::LIMIT_PER_ACCOUNT, $now)
        ) {
            return PasswordResetAnfrage::gesperrt();
        }
        $this->limits->registerFailure(self::LIMIT_IP, $ip, $now);
        $this->limits->registerFailure(self::LIMIT_ACCOUNT, $konto, $now);

        $user = $email === '' ? null : $this->users->findByEmailBlindIndex($emailBi);
        if ($user === null || !$user->mayLogIn($now)) {
            // A locked or expired account gets no link either: the reset
            // would only hand it a password the login then refuses anyway.
            return PasswordResetAnfrage::keinKonto();
        }

        $token = bin2hex(random_bytes(32));

        // One valid link per account: a new request replaces the old one
        // instead of opening a second door.
        $this->tokens->deleteForUser($user->id, self::TYP);
        $this->tokens->insert(
            $user->id,
            self::TYP,
            $this->hash($token),
            $now->modify('+' . self::TTL_SECONDS . ' seconds'),
            $now,
        );

        return PasswordResetAnfrage::link($user->id, $token, $user->emailEnc);
    }

    /**
     * Whether a link is still good - for showing the form at all. Does not
     * spend the token.
     */
    public function isUsable(#[\SensitiveParameter] string $token, ?\DateTimeImmutable $now = null): bool
    {
        return $this->find($token, $now ?? new \DateTimeImmutable()) !== null;
    }

    public function complete(
        #[\SensitiveParameter] string $token,
        #[\SensitiveParameter] string $neuesPasswort,
        #[\SensitiveParameter] string $wiederholung,
        ?\DateTimeImmutable $now = null,
    ): PasswordResult {
        $now ??= new \DateTimeImmutable();
        $gefunden = $this->find($token, $now);
        if ($gefunden === null) {
            return PasswordResult::fehler(self::linkUngueltig());
        }

        // Checked before the token is spent: a password the policy rejects
        // must not cost the user their link.
        $fehler = PasswordChange::pruefeNeu($this->policy, $neuesPasswort, $wiederholung);
        if ($fehler !== []) {
            return PasswordResult::fehler(...$fehler);
        }

        $userId = $gefunden['user_id'];
        $user = $this->users->findById($userId);
        if ($user === null || !$user->mayLogIn($now)) {
            return PasswordResult::fehler(self::linkUngueltig());
        }

        // The old private key is out of reach - that is what "forgotten"
        // means here. A fresh pair replaces it, public key included, so no
        // grant sealed to the old one can ever be opened again.
        $neuerSchluessel = UserKey::wrap(UserKeyPair::create(), $neuesPasswort);

        $this->pdo->beginTransaction();
        try {
            if (!$this->tokens->markUsed($gefunden['id'], $now)) {
                // Another request with the same link got here first.
                $this->pdo->rollBack();

                return PasswordResult::fehler(self::linkUngueltig());
            }

            if ($this->userKeys->forUser($userId) === null) {
                $this->userKeys->insert($userId, $neuerSchluessel, $now);
            } else {
                $this->userKeys->replace($userId, $neuerSchluessel);
            }
            $this->grants->deleteForUser($userId);
            $this->users->updatePasswordHash($userId, $this->passwords->hash($neuesPasswort));
            $this->tokens->deleteForUser($userId, self::TYP);
            $epoch = $this->users->bumpSessionEpoch($userId);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return PasswordResult::erfolg($userId, $epoch);
    }

    public static function linkUngueltig(): string
    {
        return 'Dieser Link ist ungültig oder abgelaufen. Bitte fordern Sie einen neuen an.';
    }

    /**
     * @return array{id: int, user_id: int}|null
     */
    private function find(#[\SensitiveParameter] string $token, \DateTimeImmutable $now): ?array
    {
        // Anything that is not the exact shape request() hands out is not
        // worth a database query.
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return null;
        }

        return $this->tokens->findUsable(self::TYP, $this->hash($token), $now);
    }

    private function hash(#[\SensitiveParameter] string $token): string
    {
        return $this->crypto->blindIndex()->forValue(self::HASH_PURPOSE, $token);
    }
}
