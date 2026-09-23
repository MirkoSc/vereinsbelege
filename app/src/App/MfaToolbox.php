<?php

declare(strict_types=1);

namespace App\App;

use App\Repository\SettingRepository;
use App\Repository\UserRepository;
use App\Service\Account\MfaService;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\ServerCrypto;
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
}
