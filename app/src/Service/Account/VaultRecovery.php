<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Repository\VaultGrantRepository;
use App\Repository\VaultRepository;
use App\Service\Crypto\RecoveryKey;
use App\Service\Crypto\RecoveryKeyException;
use App\Service\Crypto\RecoveryKeyProblem;
use App\Service\RateLimiter;

/**
 * Unlocking the vault with the paper recovery key (issue #22/M3-9,
 * docs/spec/01-sicherheit.md section 2 "Letzter Admin hat Passwort
 * vergessen"): the last way in when a password reset left an admin with no
 * grant and nobody left to grant one.
 *
 * Deliberately self-service and nothing more: it seals the recovered vault to
 * the *calling* account's own key pair - `App\Service\Account\
 * UserAdministration::freigeben()` has no rule against granting to oneself -
 * and hands back the unlocked vault so the caller can put it straight into
 * this session (`SessionVault`). An account that already has a grant (the
 * usual "just forgot to re-lock the browser" case) only gets its session
 * unlocked; a second grant of the same generation would be pointless.
 *
 * Nothing about HTTP, audit or mail here - `App\Admin\VaultRecoveryController`
 * does that, the same split as `PasswordChange`/`PasswordReset`.
 */
final readonly class VaultRecovery
{
    /** Wrong or mistyped recovery keys, counted per account in `rate_limit`. */
    private const string LIMIT_ACCOUNT = 'wiederherstellen.account';

    public function __construct(
        private VaultRepository $vaults,
        private VaultGrantRepository $grants,
        private UserAdministration $verwaltung,
        private RateLimiter $limits,
    ) {
    }

    public function wiederherstellen(
        int $userId,
        #[\SensitiveParameter] string $eingabe,
        ?\DateTimeImmutable $now = null,
    ): VaultRecoveryResult {
        $now ??= new \DateTimeImmutable();
        $konto = (string) $userId;

        // A live session is not a licence to guess: someone at an unlocked
        // computer could otherwise try recovery keys here without limit.
        if ($this->limits->isBlocked(self::LIMIT_ACCOUNT, $konto, RateLimiter::LOGIN_LIMIT_PER_ACCOUNT, $now)) {
            return VaultRecoveryResult::fehler(
                'Zu viele Versuche. Bitte warten Sie einige Minuten und versuchen Sie es erneut.',
                'rate_limit',
            );
        }

        $aktuell = $this->vaults->current();
        if ($aktuell === null) {
            // Cannot happen once the installer has run, but a null current()
            // must not become a false-shaped call into openVault() below.
            $this->limits->registerFailure(self::LIMIT_ACCOUNT, $konto, $now);

            return VaultRecoveryResult::fehler('Es ist noch kein Tresor eingerichtet.', 'kein_tresor');
        }

        try {
            $tresor = RecoveryKey::parse($eingabe)->openVault($aktuell->publicKey(), $aktuell->version);
        } catch (RecoveryKeyException $e) {
            $this->limits->registerFailure(self::LIMIT_ACCOUNT, $konto, $now);

            return VaultRecoveryResult::fehler(self::meldungFuer($e->problem), $e->problem->value);
        }

        $this->limits->reset(self::LIMIT_ACCOUNT, $konto);

        $bereitsFreigegeben = !in_array($userId, $this->grants->pendingUserIds($aktuell->version, $now), true);
        if (!$bereitsFreigegeben) {
            try {
                $this->verwaltung->freigeben($userId, $tresor, $userId, $now);
            } catch (UserRuleViolation $e) {
                return VaultRecoveryResult::fehler($e->getMessage());
            }
        }

        return VaultRecoveryResult::erfolg($tresor, freigabeErteilt: !$bereitsFreigegeben);
    }

    private static function meldungFuer(RecoveryKeyProblem $problem): string
    {
        return match ($problem) {
            RecoveryKeyProblem::Laenge => 'Der Schlüssel hat nicht die richtige Länge. '
                . 'Bitte alle sieben Gruppen vollständig eingeben.',
            RecoveryKeyProblem::Zeichen => 'Der Schlüssel enthält ein Zeichen, das darin nicht vorkommt.',
            RecoveryKeyProblem::Version => 'Dieser Schlüssel wurde in einem unbekannten Format erzeugt.',
            RecoveryKeyProblem::Pruefsumme => 'Der Schlüssel ist an einer Stelle falsch eingegeben. '
                . 'Bitte Gruppe für Gruppe mit dem Ausdruck vergleichen.',
            RecoveryKeyProblem::FremderTresor => 'Dieser Schlüssel gehört nicht zu dieser Installation.',
        };
    }
}
