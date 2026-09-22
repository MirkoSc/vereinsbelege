<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Domain\User;
use App\Repository\UserKeyRepository;
use App\Repository\UserRepository;
use App\Repository\VaultGrantRepository;
use App\Repository\VaultRepository;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\RateLimiter;

/**
 * Login with email and password, and the vault unlock that comes with it
 * (docs/spec/01-sicherheit.md section 2 "Session-Entsperrung", section 3
 * "Anmeldung").
 *
 * The chain, all in this one place:
 *
 *   password -> KEK (Argon2id, `user_key.kdf_*`) -> U_priv
 *            -> box_seal_open(vault_grant.sealed_private_key) -> VK_priv
 *
 * Nothing about HTTP lives here - no session, no cookie, no redirect. What
 * comes back is a value (LoginResult); putting VK_priv into the session and
 * the session key into `__Host-vk` is App\App\AuthController's job. That
 * split is what makes the whole chain testable without a web server, and it
 * is the same rule the processing services follow (CLAUDE.md section 6a).
 *
 * Two things this class is careful about, both from section 3:
 *
 *   - **No user enumeration.** Unknown address, wrong password, locked
 *     account and expired external account produce the same LoginFailure
 *     with the same sentence, and the unknown-address branch burns a dummy
 *     verification so that it takes the same time as a real one.
 *   - **Brute force.** Every failure counts against the IP *and* against the
 *     account; a success clears both. The check itself never counts, so
 *     asking the question does not use up an attempt.
 */
final readonly class LoginService
{
    /** Namespaces of the two counters in `rate_limit`. */
    private const string LIMIT_IP = 'login.ip';

    private const string LIMIT_ACCOUNT = 'login.account';

    public function __construct(
        private UserRepository $users,
        private UserKeyRepository $userKeys,
        private VaultGrantRepository $grants,
        private VaultRepository $vaults,
        private ServerCrypto $crypto,
        private RateLimiter $limits,
        private PasswordHasher $passwords,
    ) {
    }

    public function attempt(
        string $email,
        #[\SensitiveParameter] string $password,
        string $ip,
        ?\DateTimeImmutable $now = null,
    ): LoginResult {
        $now ??= new \DateTimeImmutable();
        $email = trim($email);

        if ($this->limits->isBlocked(self::LIMIT_IP, $ip, RateLimiter::LOGIN_LIMIT_PER_IP, $now)) {
            return LoginResult::fehler(LoginFailure::ZuVieleVersuche);
        }

        // The blind index is the only way to find an account before a
        // session exists: `user.email_bi` is keyed from the server key, not
        // from the vault (docs/spec/01-sicherheit.md section 2).
        $emailBi = $this->crypto->blindIndex()->forValue('user.email', $email);
        $konto = bin2hex($emailBi);

        if ($this->limits->isBlocked(self::LIMIT_ACCOUNT, $konto, RateLimiter::LOGIN_LIMIT_PER_ACCOUNT, $now)) {
            return LoginResult::fehler(LoginFailure::ZuVieleVersuche);
        }

        $user = $email === '' ? null : $this->users->findByEmailBlindIndex($emailBi);

        // Both branches below end in the same answer; they differ only in
        // how the time is spent, and that is the point.
        if ($user === null || !$user->mayLogIn($now)) {
            $this->passwords->verifyDummy($password);

            return $this->fehlversuch($ip, $konto, $now);
        }

        if (!$this->passwords->verify($password, $user->passwordHash)) {
            return $this->fehlversuch($ip, $konto, $now);
        }

        // From here on the credentials are right. The second factor of M3-4
        // hooks in exactly here, between "password correct" and "session
        // unlocked": `user.mfa_required` decides, and the vault must stay
        // locked until it is answered (docs/spec/01-sicherheit.md section 3).

        $this->limits->reset(self::LIMIT_IP, $ip);
        $this->limits->reset(self::LIMIT_ACCOUNT, $konto);

        if ($this->passwords->needsRehash($user->passwordHash)) {
            // The one moment the plaintext is in hand anyway, so raised cost
            // factors reach an old account without anybody changing anything.
            $this->users->updatePasswordHash($user->id, $this->passwords->hash($password));
        }

        $this->users->touchLastLogin($user->id, $now);

        return $this->entsperren($user, $password);
    }

    /**
     * Opens the vault for this user, or explains why it stayed closed. A
     * missing grant is a normal state of the user lifecycle, not an error:
     * an invited user and a user who just reset their password both have a
     * key pair and no grant until an admin releases the vault to them
     * (docs/spec/01-sicherheit.md section 2, M3-7).
     */
    private function entsperren(User $user, #[\SensitiveParameter] string $password): LoginResult
    {
        $userKey = $this->userKeys->forUser($user->id);
        $grant = $this->grants->forUser($user->id);
        $vault = $this->vaults->current();

        if ($userKey === null || $grant === null || $vault === null) {
            return LoginResult::erfolg($user, null, VaultAccess::KeineFreigabe);
        }

        try {
            $entsperrt = $grant->open($userKey->unwrap($password), $vault->publicKey(), $vault->version);
        } catch (CryptoException) {
            // The password verified but does not open the wrapping, or the
            // grant belongs to another key pair or another generation. Not a
            // failed login - and not something a second attempt fixes, so it
            // is said plainly instead of being answered with "wrong
            // password" (which would send the user chasing their password).
            return LoginResult::erfolg($user, null, VaultAccess::Fehlgeschlagen);
        }

        return LoginResult::erfolg($user, $entsperrt, VaultAccess::Entsperrt);
    }

    private function fehlversuch(string $ip, string $konto, \DateTimeImmutable $now): LoginResult
    {
        $this->limits->registerFailure(self::LIMIT_IP, $ip, $now);
        $this->limits->registerFailure(self::LIMIT_ACCOUNT, $konto, $now);

        return LoginResult::fehler(LoginFailure::Zugangsdaten);
    }
}
