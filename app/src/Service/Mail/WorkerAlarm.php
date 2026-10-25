<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Permission;
use App\Domain\User;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Service\Crypto\ServerCrypto;

/**
 * Queues the "worker offline" alarm (issue #53) for every account that may
 * look after the worker (`admin.settings`, the right of the admin page
 * "Worker"). Only server-key ciphertext is opened - the addresses - so the
 * cron can do it (CLAUDE.md section 4).
 */
final readonly class WorkerAlarm
{
    public function __construct(
        private Mailer $mailer,
        private ServerCrypto $crypto,
        private UserRepository $users,
        private UserAccessRepository $zugriff,
    ) {
    }

    /** @return int number of alarms queued */
    /**
     * @param int $zurueck jobs that went back to the browser/session route
     * @param int $wartend jobs still waiting for the worker (fallback period
     *        longer than the alarm's day)
     */
    public function senden(int $stunden, int $zurueck, int $wartend, \DateTimeImmutable $now): int
    {
        $anzahl = 0;
        foreach ($this->users->all() as $user) {
            if (!$this->empfaenger($user, $now)) {
                continue;
            }
            // An address that does not open (restore without the old server
            // key, issue #154) cannot be mailed.
            $email = $this->crypto->decryptOrNull($user->emailEnc);
            if ($email === null) {
                continue;
            }
            $this->mailer->reiheWorkerAusfallEin($email, $stunden, $zurueck, $wartend, $now);
            ++$anzahl;
        }

        return $anzahl;
    }

    private function empfaenger(User $user, \DateTimeImmutable $now): bool
    {
        return $user->mayLogIn($now) && $this->zugriff->berechtigungen($user->id)->darf(Permission::AdminSettings);
    }
}
