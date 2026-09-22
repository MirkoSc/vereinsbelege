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
 * has proven it works: a freshly generated TOTP secret is not the account's
 * method until a real code from it has been confirmed, exactly the
 * distinction `mfa_totp.confirmed_at` exists for.
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
     * Starts (or restarts) TOTP setup with a fresh, unconfirmed secret -
     * restarting is deliberate: a secret nobody has scanned yet is not worth
     * keeping around, and switching back to TOTP later means scanning a new
     * code rather than trusting whatever the phone still remembers.
     *
     * @return string the raw secret, for the QR code / manual entry
     *                (App\Service\Account\Totp::uri()/base32Encode()) - never
     *                stored anywhere outside this call's caller and the
     *                server-key ciphertext this method writes
     */
    public function startTotp(int $userId, ?\DateTimeImmutable $now = null): string
    {
        $secret = Totp::generateSecret();
        $this->totp->put($userId, $this->crypto->encrypt($secret), $now);

        return $secret;
    }

    /**
     * Confirms the secret startTotp() just generated and, only on success,
     * commits TOTP as the account's method and issues its backup codes.
     *
     * @return list<string>|null the ten backup codes on success, null when
     *                           the code does not verify (no secret pending,
     *                           wrong code)
     */
    public function confirmTotp(int $userId, string $code, ?\DateTimeImmutable $now = null): ?array
    {
        $stored = $this->totp->forUser($userId);
        if ($stored === null) {
            return null;
        }

        if (!Totp::verify($this->crypto->decrypt($stored->secretEnc), $code, $now)) {
            return null;
        }

        $this->totp->confirm($userId, $now);
        $this->users->updateMfaMethod($userId, MfaMethod::Totp);

        return $this->regenerateBackupCodes($userId, $now);
    }

    /**
     * Commits e-mail as the account's method. The caller has already proven
     * the mailbox is reachable (App\Service\Account\MfaService::
     * requestEmailCode()/verifyEmailCode(), the same check the login page
     * uses) - there is nothing left to verify here.
     *
     * @return list<string> the ten backup codes
     */
    public function commitEmailMethod(int $userId, ?\DateTimeImmutable $now = null): array
    {
        $this->users->updateMfaMethod($userId, MfaMethod::EMail);

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
