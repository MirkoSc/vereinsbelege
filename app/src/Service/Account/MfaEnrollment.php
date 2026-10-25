<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Repository\MfaBackupCodeRepository;
use App\Repository\MfaTotpRepository;
use App\Repository\UserRepository;
use App\Service\Crypto\ServerCrypto;

/**
 * Setting a second factor up (docs/spec/01-sicherheit.md section 3, issue
 * #17/M3-4) - as opposed to App\Service\Account\MfaService, which checks one
 * that is already in place. No HTTP, no session, no mail: App\App\
 * SecurityController drives the confirmation step (via MfaService) and sends
 * the "2FA geändert" notice (App\Service\Mail\Mailer::sendeSicherheitshinweis())
 * once a method actually commits here.
 *
 * `user.mfa_method` only ever changes in this class, and only once a factor
 * has proven it works: a freshly generated TOTP secret is not even stored
 * until a real code from it has been confirmed (issue #246).
 */
final readonly class MfaEnrollment
{
    public function __construct(
        private MfaTotpRepository $totp,
        private MfaBackupCodeRepository $backupCodes,
        private UserRepository $users,
        private ServerCrypto $crypto,
    ) {
    }

    /**
     * Starts (or restarts) TOTP setup with a fresh secret. Nothing is
     * stored: the caller keeps it until confirmTotp() (App\App\
     * SecurityController holds it, as server-key ciphertext, in the session
     * - App\Http\Session::merkeTotpEinrichtung()). That way an abandoned
     * setup - a closed tab, a QR code nobody scanned - leaves the account's
     * current authenticator working (security review N-08, issue #246);
     * before, starting already overwrote the confirmed secret.
     *
     * @return string the raw secret, for the QR code / manual entry
     *                (App\Service\Account\Totp::uri()/base32Encode())
     */
    public function startTotp(): string
    {
        return Totp::generateSecret();
    }

    /**
     * Confirms the secret startTotp() generated and, only on success,
     * stores it as the account's secret, commits TOTP as the method and
     * issues fresh backup codes.
     *
     * @return list<string>|null the ten backup codes on success, null when
     *                           the code does not verify
     */
    public function confirmTotp(
        int $userId,
        #[\SensitiveParameter] string $secret,
        string $code,
        ?\DateTimeImmutable $now = null,
    ): ?array {
        if ($secret === '') {
            return null;
        }

        $step = Totp::matchingStep($secret, $code, $now);
        if ($step === null) {
            return null;
        }

        // Stored, confirmed and with the confirming code spent in one go:
        // seen over a shoulder during setup, that code must not open a login
        // within its ~90 s (issue #78).
        $this->totp->putConfirmed($userId, $this->crypto->encrypt($secret), $step, $now);
        $this->users->updateMfaMethod($userId, MfaMethod::Totp);

        return $this->regenerateBackupCodes($userId, $now);
    }

    /**
     * Commits e-mail as the account's method. The caller has already proven
     * the mailbox is reachable (App\Service\Account\MfaService::
     * requestEmailCode()/verifyEmailCode(), the same check the login page
     * uses) - there is nothing left to verify here.
     *
     * A TOTP secret from before goes: the login accepts a TOTP code whatever
     * the method (App\App\MfaController), so an authenticator the user
     * just switched away from - perhaps because the phone is gone - would
     * otherwise keep opening the account (issue #246).
     *
     * @return list<string> the ten backup codes
     */
    public function commitEmailMethod(int $userId, ?\DateTimeImmutable $now = null): array
    {
        $this->users->updateMfaMethod($userId, MfaMethod::EMail);
        $this->totp->delete($userId);

        return $this->regenerateBackupCodes($userId, $now);
    }

    /**
     * Replaces the whole set of ten - available any time a factor is
     * already confirmed, not only at setup (App\App\SecurityController's
     * "Codes neu erzeugen").
     *
     * @return list<string>
     */
    public function regenerateBackupCodes(int $userId, ?\DateTimeImmutable $now = null): array
    {
        $codes = BackupCodes::generate();

        $this->backupCodes->replaceAll(
            $userId,
            array_map(
                fn(string $code): string => $this->crypto->blindIndex()->forValue(
                    'mfa.backup_code',
                    $userId . ':' . BackupCodes::normalize($code),
                ),
                $codes,
            ),
            $now,
        );

        return $codes;
    }
}
