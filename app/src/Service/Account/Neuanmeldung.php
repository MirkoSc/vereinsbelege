<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Repository\UserRepository;
use App\Service\RateLimiter;

/**
 * Re-authentication before the second factor changes (security review N-08,
 * issue #246, ASVS 3.7.1; docs/spec/01-sicherheit.md section 3): the
 * account's password AND its current second factor - a TOTP code, an
 * e-mailed code or a backup code, the same three ways the login accepts.
 * A live session alone is not enough: somebody at an unlocked computer
 * must not be able to move the second factor to their own phone.
 *
 * No HTTP, no session: App\App\SecurityController keeps the result
 * (App\Http\Session::bestaetigeNeuanmeldung()) for GUELTIG_SEKUNDEN.
 *
 * Both checks count against the limits that already exist for them - the
 * password against the per-account counter of the password change
 * (App\Service\Account\PasswordChange::LIMIT_ACCOUNT), the code against the
 * second factor's own (App\Service\Account\MfaService) - counted before the
 * check (RateLimiter::versuch(), N-09). The code is only tried once the
 * password is right, so a mistyped password neither spends a code nor uses
 * up the second factor's attempts.
 */
final readonly class Neuanmeldung
{
    /**
     * How long a confirmation holds: enough to scan a QR code and type the
     * first code, short enough that a session left open is not a licence.
     */
    public const int GUELTIG_SEKUNDEN = 600;

    public function __construct(
        private UserRepository $users,
        private PasswordHasher $passwords,
        private MfaService $mfa,
        private RateLimiter $limits,
    ) {
    }

    public function pruefe(
        int $userId,
        #[\SensitiveParameter] string $passwort,
        #[\SensitiveParameter] string $code,
        string $ip,
        ?\DateTimeImmutable $now = null,
    ): NeuanmeldungErgebnis {
        $now ??= new \DateTimeImmutable();
        $konto = (string) $userId;

        if (!$this->limits->versuch(PasswordChange::LIMIT_ACCOUNT, $konto, RateLimiter::LOGIN_LIMIT_PER_ACCOUNT, $now)) {
            return NeuanmeldungErgebnis::ZuVieleVersuche;
        }

        $user = $this->users->findById($userId);
        if ($user === null || !$this->passwords->verify($passwort, $user->passwordHash)) {
            return NeuanmeldungErgebnis::PasswortFalsch;
        }
        $this->limits->reset(PasswordChange::LIMIT_ACCOUNT, $konto);

        if (!$this->mfa->versuch($ip, $userId, $now)) {
            return NeuanmeldungErgebnis::ZuVieleVersuche;
        }

        $code = trim($code);
        $bestaetigt = $code !== '' && (
            $this->mfa->verifyTotp($userId, $code, $now)
            || $this->mfa->verifyEmailCode($userId, $code, $now)
            || $this->mfa->verifyBackupCode($userId, $code, $now)
        );
        if (!$bestaetigt) {
            return NeuanmeldungErgebnis::CodeFalsch;
        }

        $this->mfa->resetLimit($ip, $userId);

        return NeuanmeldungErgebnis::Bestaetigt;
    }
}
