<?php

declare(strict_types=1);

namespace App\App;

use App\Repository\UserRepository;
use App\Service\Account\PasswordReset;
use App\Service\Crypto\ServerCrypto;
use App\Service\Mail\FreigabeBenachrichtigung;
use App\Service\Mail\Mailer;
use App\Service\Mail\MailSettingsRepository;
use App\Support\FileLogger;

/**
 * The database-backed collaborators App\App\PasswordController needs, built
 * lazily behind one closure - the same reason as App\App\MfaToolbox: the
 * forms themselves (GET) need no database, only submitting them does.
 */
final readonly class PasswordToolbox
{
    public function __construct(
        public PasswordReset $reset,
        public UserRepository $users,
        public Mailer $mailer,
        public MailSettingsRepository $mailSettings,
        public ServerCrypto $crypto,
        public ?FileLogger $logger = null,
        /** Tells the admins who can grant that the account waits again (M3-7). */
        public ?FreigabeBenachrichtigung $freigabeHinweis = null,
    ) {
    }
}
