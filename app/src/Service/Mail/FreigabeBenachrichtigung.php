<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Service\Account\UserAdministration;
use App\Service\Crypto\ServerCrypto;

/**
 * "Admins bekommen dazu eine Mail (ohne fachlichen Inhalt)"
 * (docs/spec/01-sicherheit.md section 2 "Freigabe", issue #20/M3-7): queues
 * one notice for every account that can grant the vault
 * (UserAdministration::freigeber()), whenever an account starts waiting for
 * a grant - an accepted invitation, a password reset, an unlock.
 *
 * Queued only (Mailer::reiheFreigabeHinweisEin()): the cron sends them, the
 * request that caused them stays short.
 */
final readonly class FreigabeBenachrichtigung
{
    public function __construct(
        private Mailer $mailer,
        private ServerCrypto $crypto,
        private UserAdministration $verwaltung,
    ) {
    }

    /**
     * @param string|null $basis checked base URL (PublicUrl::resolve()), or
     *        null - then the mail names the menu entry instead of a link
     * @return int number of notices queued
     */
    public function senden(?string $basis, ?\DateTimeImmutable $now = null): int
    {
        $anzahl = 0;
        foreach ($this->verwaltung->freigeber($now) as $admin) {
            $this->mailer->reiheFreigabeHinweisEin(
                $this->crypto->decrypt($admin->emailEnc),
                $basis === null ? null : $basis . '/admin/tresor',
                $now,
            );
            $anzahl++;
        }

        return $anzahl;
    }
}
