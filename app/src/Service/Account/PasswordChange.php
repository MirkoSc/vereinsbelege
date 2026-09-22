<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Repository\UserKeyRepository;
use App\Repository\UserRepository;
use App\Service\Crypto\CryptoException;
use App\Service\RateLimiter;

/**
 * Password change with the old password known (docs/spec/01-sicherheit.md
 * section 2 "Benutzer-Lebenszyklus", issue #18/M3-5): the user's private
 * key is wrapped again under the new password (App\Service\Crypto\UserKey::
 * rewrap()), the key pair itself stays - so every vault grant sealed to it
 * keeps working and no admin has to approve anything again.
 *
 * Beyond the spec's "sonst nichts", and on purpose: the account's other
 * sessions end (`user.session_epoch`). Whoever changes a password because
 * the old one may be known to somebody else wants exactly that.
 *
 * Nothing about HTTP here, the same split as LoginService: the controller
 * turns the result into a page and adopts the new epoch in its own session.
 */
final readonly class PasswordChange
{
    /** Wrong old passwords, counted per account in `rate_limit`. */
    private const string LIMIT_ACCOUNT = 'passwort.account';

    private UserRepository $users;

    private UserKeyRepository $userKeys;

    public function __construct(
        private \PDO $pdo,
        private PasswordHasher $passwords,
        private PasswordPolicy $policy,
        private RateLimiter $limits,
    ) {
        $this->users = new UserRepository($pdo);
        $this->userKeys = new UserKeyRepository($pdo);
    }

    public function change(
        int $userId,
        #[\SensitiveParameter] string $altesPasswort,
        #[\SensitiveParameter] string $neuesPasswort,
        #[\SensitiveParameter] string $wiederholung,
        ?\DateTimeImmutable $now = null,
    ): PasswordResult {
        $now ??= new \DateTimeImmutable();
        $konto = (string) $userId;

        // A live session is not a licence to guess: somebody at an unlocked
        // computer could otherwise try old passwords here without limit.
        if ($this->limits->isBlocked(self::LIMIT_ACCOUNT, $konto, RateLimiter::LOGIN_LIMIT_PER_ACCOUNT, $now)) {
            return PasswordResult::fehler('Zu viele Versuche. Bitte warten Sie einige Minuten und versuchen Sie es erneut.');
        }

        $user = $this->users->findById($userId);
        if ($user === null || !$this->passwords->verify($altesPasswort, $user->passwordHash)) {
            $this->limits->registerFailure(self::LIMIT_ACCOUNT, $konto, $now);

            return PasswordResult::fehler('Das bisherige Passwort ist falsch.');
        }

        $fehler = self::pruefeNeu($this->policy, $neuesPasswort, $wiederholung);
        if ($fehler === [] && hash_equals($altesPasswort, $neuesPasswort)) {
            $fehler[] = 'Das neue Passwort muss sich vom bisherigen unterscheiden.';
        }
        if ($fehler !== []) {
            return PasswordResult::fehler(...$fehler);
        }

        $this->limits->reset(self::LIMIT_ACCOUNT, $konto);

        $userKey = $this->userKeys->forUser($userId);
        $neuerSchluessel = null;
        if ($userKey !== null) {
            try {
                $neuerSchluessel = $userKey->rewrap($altesPasswort, $neuesPasswort);
            } catch (CryptoException) {
                // The password hash says yes, the wrapping says no - the two
                // have drifted apart. Changing only the hash would make it
                // worse (the key would then open with no known password), so
                // nothing changes and a human has to look.
                return PasswordResult::fehler(
                    'Ihr Schlüssel ließ sich mit dem bisherigen Passwort nicht öffnen. '
                    . 'Das Passwort wurde nicht geändert – bitte wenden Sie sich an einen Administrator.',
                );
            }
        }

        $this->pdo->beginTransaction();
        try {
            if ($neuerSchluessel !== null) {
                $this->userKeys->replace($userId, $neuerSchluessel);
            }
            $this->users->updatePasswordHash($userId, $this->passwords->hash($neuesPasswort));
            $epoch = $this->users->bumpSessionEpoch($userId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return PasswordResult::erfolg($userId, $epoch);
    }

    /**
     * The checks every new password goes through, change and reset alike:
     * both fields the same, and the policy of docs/spec/01-sicherheit.md
     * section 3.
     *
     * @return list<string>
     */
    public static function pruefeNeu(
        PasswordPolicy $policy,
        #[\SensitiveParameter] string $neuesPasswort,
        #[\SensitiveParameter] string $wiederholung,
    ): array {
        if (!hash_equals($neuesPasswort, $wiederholung)) {
            return ['Die beiden neuen Passwörter stimmen nicht überein.'];
        }

        return $policy->violations($neuesPasswort);
    }
}
