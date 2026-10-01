<?php

declare(strict_types=1);

namespace App\App;

use App\Domain\User;
use App\Repository\SettingRepository;
use App\Repository\UserRepository;
use App\Service\Account\MfaService;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\ServerCrypto;
use App\Service\Mail\MailAttemptResult;
use App\Service\Mail\Mailer;

/**
 * The database-backed collaborators App\App\MfaController needs, built
 * lazily behind one closure - the same reason App\App\AuthController's
 * `$login` is lazy: the confirmation FORM (a GET) needs no database at all,
 * only submitting a code does.
 */
final readonly class MfaToolbox
{
    public function __construct(
        public MfaService $mfa,
        public UserRepository $users,
        public Mailer $mailer,
        public SettingRepository $settings,
        public ServerCrypto $crypto,
        public AuditLog $audit,
    ) {
    }

    /**
     * Creates a fresh e-mail code for the account and mails it right away -
     * the one place that does so for the login, whether the account asked for
     * it (App\App\MfaController::sendEmailCode()) or the login itself starts
     * it (App\App\AuthController::submit(), issue #153). The caller checks
     * the rate limit first.
     */
    public function sendEmailCode(User $user): MailAttemptResult
    {
        $code = $this->mfa->requestEmailCode($user->id);

        return $this->mailer->sendeMfaCode(
            $this->crypto->decrypt($user->emailEnc),
            $code,
            intdiv(MfaService::EMAIL_CODE_TTL_SECONDS, 60),
        );
    }
}
