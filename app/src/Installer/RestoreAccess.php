<?php

declare(strict_types=1);

namespace App\Installer;

use App\Domain\AuditAction;
use App\Domain\Permission;
use App\Domain\User;
use App\Repository\AuditLogRepository;
use App\Repository\MailQueueRepository;
use App\Repository\MfaBackupCodeRepository;
use App\Repository\MfaTotpRepository;
use App\Repository\RateLimitRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Repository\VaultGrantRepository;
use App\Repository\VaultRepository;
use App\Service\Account\Invitation;
use App\Service\Account\MfaMethod;
use App\Service\Account\PasswordHasher;
use App\Service\Account\UserAdministration;
use App\Service\Account\UserRuleViolation;
use App\Service\Account\VaultRecovery;
use App\Service\Audit\AuditLog;
use App\Service\Backup\RestoreService;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\RecoveryKey;
use App\Service\Crypto\RecoveryKeyException;
use App\Service\Crypto\ServerCrypto;
use App\Service\RateLimiter;

/**
 * Getting back into a restored installation whose backup carried no
 * server key (issue #154, docs/spec/06-betrieb.md section 2).
 *
 * `user.email_bi` - the only index the login can search before a session
 * exists - is an HMAC under the server key, and so are `email_enc`,
 * `display_name_enc` and the authenticator secrets. A backup without
 * config.php restores every account as ciphertext under a key that is gone:
 * with a freshly generated key nobody is found, nobody can log in, and the
 * paper recovery key cannot help because `/admin/wiederherstellen` needs a
 * logged-in admin. This class is the step the installer runs between "dump
 * is in" and "config.php is written", in two alternatives:
 *
 * 1. serverKeyFits(): the admin kept a copy of the old config.php (or its
 *    key) and hands it in. The key is accepted only when it actually
 *    decrypts an existing account - a mistyped key would otherwise lock the
 *    installation just as a missing one does.
 * 2. relink(): the admin proves the club's authority with the recovery key
 *    (it contains VK_priv, the root of all business data - whoever holds it
 *    can read everything anyway, so it adds no power the key did not have)
 *    AND identifies their own account with its password. Nothing else could
 *    identify it: names and addresses are unreadable. The account then gets
 *    its address and name re-encrypted under the NEW server key, a grant of
 *    the recovered vault if it had none, and - because the TOTP secret is
 *    ciphertext under the old key - its authenticator removed, so the first
 *    login sets up a second factor again (`mfa_required` stays).
 *
 * What relink() cannot repair, it does not pretend to: the other users'
 * addresses stay unreadable until an admin re-enters them on
 * /admin/benutzer, mail queue, AI API keys and the SMTP password are gone.
 * The vault key itself never touches the session or the disk: the recovery
 * key is parsed and used within the one request that receives it.
 *
 * No Http here (App\Installer\InstallController does the forms); the SQL is
 * in the repositories.
 */
final readonly class RestoreAccess
{
    /** Wrong keys or passwords, counted per client address in `rate_limit`. */
    private const string LIMIT_IP = 'install.zugang';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Whether $serverKeyBase64 is a key AND opens the restored data: the
     * address of at least one account decrypts with it. An installation
     * without any account (cannot happen after the installer, but a dump can
     * be anything) has nothing to check against and accepts a well-formed key.
     */
    public function serverKeyFits(string $serverKeyBase64): bool
    {
        if (!RestoreService::gueltigerServerSchluessel($serverKeyBase64)) {
            return false;
        }
        $crypto = new ServerCrypto((string) base64_decode($serverKeyBase64, true));

        $konten = new UserRepository($this->pdo)->all();
        if ($konten === []) {
            return true;
        }
        foreach ($konten as $konto) {
            try {
                $crypto->decrypt($konto->emailEnc);

                return true;
            } catch (CryptoException) {
                // next row - one readable address is proof enough
            }
        }

        return false;
    }

    /**
     * Re-links the admin account whose password is $password under the new
     * server key, authorised by the club's recovery key.
     *
     * @param string $newServerKeyBase64 the key the installer writes to
     *        config.php - what the address is encrypted with
     * @param string $ip the client address, '' for none (rate limit)
     * @param \Closure(): void $configSchreiben writes config.php with that
     *        key; called inside the transaction, so a key that could not be
     *        written never becomes the only key an account is readable with
     *
     * @throws RestoreAccessException with the message for the page
     */
    public function relink(
        #[\SensitiveParameter] string $recoveryKeyInput,
        string $email,
        string $displayName,
        #[\SensitiveParameter] string $password,
        #[\SensitiveParameter] string $newServerKeyBase64,
        string $ip,
        \Closure $configSchreiben,
        ?\DateTimeImmutable $now = null,
    ): RestoreAccessResult {
        $now ??= new \DateTimeImmutable();
        $limits = new RateLimiter(new RateLimitRepository($this->pdo), RateLimiter::LOGIN_WINDOW_SECONDS);

        // The installer has no account to count against, only the client;
        // the recovery key's checksum makes a typo cheap to tell from a
        // guess, but guessing passwords behind a known key must still end.
        if ($limits->isBlocked(self::LIMIT_IP, $ip, RateLimiter::LOGIN_LIMIT_PER_IP, $now)) {
            throw new RestoreAccessException('Zu viele Versuche. Bitte einige Minuten warten und erneut versuchen.');
        }

        $email = trim($email);
        if (filter_var($email, \FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255) {
            throw new RestoreAccessException('Bitte eine gültige E-Mail-Adresse angeben.');
        }
        try {
            $displayName = Invitation::pruefeName($displayName);
        } catch (UserRuleViolation $e) {
            throw new RestoreAccessException($e->getMessage());
        }
        if ($password === '') {
            throw new RestoreAccessException('Bitte das Passwort des Admin-Kontos eingeben.');
        }

        if (!RestoreService::gueltigerServerSchluessel($newServerKeyBase64)) {
            throw new RestoreAccessException('Der neue Server-Schlüssel ist ungültig.');
        }
        $crypto = new ServerCrypto((string) base64_decode($newServerKeyBase64, true));

        $vaults = new VaultRepository($this->pdo);
        $aktuell = $vaults->current();
        if ($aktuell === null) {
            throw new RestoreAccessException('Das eingespielte Backup enthält keinen Tresor.');
        }

        try {
            $tresor = RecoveryKey::parse($recoveryKeyInput)->openVault($aktuell->publicKey(), $aktuell->version);
        } catch (RecoveryKeyException $e) {
            $limits->registerFailure(self::LIMIT_IP, $ip, $now);

            throw new RestoreAccessException(VaultRecovery::meldungFuer(VaultRecovery::problemFuer($e, $recoveryKeyInput, $vaults)));
        }

        // Which account? The one that may manage accounts (it has to fix the
        // other addresses afterwards) and whose password this is. Argon2id
        // per candidate is the price of having nothing readable to go by; a
        // club has a handful of admins, not hundreds. Every candidate is
        // checked: two admins sharing a password would make the choice a
        // guess, and a guess would write this address onto somebody else's
        // account.
        $users = new UserRepository($this->pdo);
        $zugriff = new UserAccessRepository($this->pdo);
        $hasher = new PasswordHasher();
        $treffer = [];
        foreach ($users->all() as $kandidat) {
            if (!$kandidat->mayLogIn($now) || !$zugriff->berechtigungen($kandidat->id)->darf(Permission::AdminUsers)) {
                continue;
            }
            if ($hasher->verify($password, $kandidat->passwordHash)) {
                $treffer[] = $kandidat;
            }
        }
        if ($treffer === []) {
            $limits->registerFailure(self::LIMIT_IP, $ip, $now);

            throw new RestoreAccessException(
                'Zu diesem Passwort gehört kein aktives Admin-Konto. Bitte das bisherige Passwort eines Kontos '
                . 'eingeben, das Benutzer verwalten darf.',
            );
        }
        if (count($treffer) > 1) {
            throw new RestoreAccessException(
                'Dieses Passwort gehört zu mehreren Admin-Konten; welches gemeint ist, lässt sich nicht erkennen. '
                . 'Bitte mit dem Passwort eines anderen Admin-Kontos verknüpfen.',
            );
        }
        $konto = $treffer[0];
        $limits->reset(self::LIMIT_IP, $ip);

        $grants = new VaultGrantRepository($this->pdo);
        $freigabeErteilt = !isset($grants->grantsFor($aktuell->version)[$konto->id]);
        $mfaZurueckgesetzt = $konto->mfaMethod === MfaMethod::Totp;

        $this->pdo->beginTransaction();
        try {
            $users->updateEmail(
                $konto->id,
                $crypto->encrypt($email),
                $crypto->blindIndex()->forValue('user.email', $email),
            );
            $users->updateDisplayName($konto->id, $crypto->encrypt($displayName));

            if ($mfaZurueckgesetzt) {
                // The secret is ciphertext under the old key: the app would
                // never match again. E-mail codes keep working - the address
                // is readable again from here on.
                new MfaTotpRepository($this->pdo)->delete($konto->id);
                new MfaBackupCodeRepository($this->pdo)->deleteAllForUser($konto->id);
                $users->updateMfaMethod($konto->id, null);
            }

            if ($freigabeErteilt) {
                // A reset shortly before the backup left the account without
                // a grant; the recovered vault seals one, like
                // /admin/wiederherstellen would after a login.
                try {
                    new UserAdministration($this->pdo, $crypto)->freigeben($konto->id, $tresor, $konto->id, $now);
                } catch (UserRuleViolation $e) {
                    throw new RestoreAccessException($e->getMessage());
                }
            }

            // Every queued mail is ciphertext under the lost key: left in
            // place, the first one would stop the cron's delivery for good
            // and break the admin queue page.
            new MailQueueRepository($this->pdo, $crypto)->deleteAll();

            new AuditLog(new AuditLogRepository($this->pdo), $vaults, $crypto)->record(
                AuditAction::ZugangNeuVerknuepft,
                $konto->id,
                $ip,
                $konto->id,
                [
                    'tresor_version' => $aktuell->version,
                    'freigabe_erteilt' => $freigabeErteilt,
                    'mfa_zurueckgesetzt' => $mfaZurueckgesetzt,
                ],
                $now,
            );

            // config.php last, but before the commit: fails the write, the
            // account stays readable under the old (lost) key and the old
            // config.php of "Weg 1" still fits - nothing is half done.
            $configSchreiben();

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return new RestoreAccessResult($konto->id, $freigabeErteilt, $mfaZurueckgesetzt);
    }

    /**
     * How many accounts the restored data holds whose address will NOT be
     * readable after the re-link - for the final page, so the admin knows
     * what to do next (re-enter them on /admin/benutzer).
     */
    public function unreadableAccounts(string $serverKeyBase64, int $ausser): int
    {
        if (!RestoreService::gueltigerServerSchluessel($serverKeyBase64)) {
            throw new \InvalidArgumentException('Not a server key.');
        }
        $crypto = new ServerCrypto((string) base64_decode($serverKeyBase64, true));

        return count(array_filter(
            new UserRepository($this->pdo)->all(),
            static fn(User $u): bool => $u->id !== $ausser && $crypto->decryptOrNull($u->emailEnc) === null,
        ));
    }
}
