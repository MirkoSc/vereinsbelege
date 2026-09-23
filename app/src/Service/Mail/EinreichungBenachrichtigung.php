<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Permission;
use App\Domain\User;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Service\Crypto\ServerCrypto;

/**
 * "Mail an die Rolle Finanzen bei neuer Einreichung" (issue #27/M4-5):
 * queues one notice for every active account that may decide on receipts
 * (`document.edit` - Finanzen and Admin in the shipped roles, and any role
 * an admin gave that right).
 *
 * The notice carries the reference number and a link, nothing of the
 * receipt (CLAUDE.md section 4). Queued only
 * (Mailer::reiheNeueEinreichungEin()): the cron sends them, the public
 * request that caused them stays short. Works without a vault - the
 * addresses are server-key operating data.
 */
final readonly class EinreichungBenachrichtigung
{
    public function __construct(
        private Mailer $mailer,
        private ServerCrypto $crypto,
        private UserRepository $users,
        private UserAccessRepository $zugriff,
    ) {
    }

    /**
     * @param string|null $basis checked base URL (PublicUrl::resolve()), or
     *        null - then the mail names the menu entry instead of a link
     * @return int number of notices queued
     */
    public function senden(string $referenz, int $documentId, ?string $basis, ?\DateTimeImmutable $now = null): int
    {
        $anzahl = 0;
        foreach ($this->empfaenger($now) as $user) {
            $this->mailer->reiheNeueEinreichungEin(
                $this->crypto->decrypt($user->emailEnc),
                $referenz,
                $basis === null ? null : $basis . '/app/posteingang/' . $documentId,
                $now,
            );
            $anzahl++;
        }

        return $anzahl;
    }

    /**
     * @return list<User>
     */
    public function empfaenger(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();

        return array_values(array_filter(
            $this->users->all(),
            fn(User $u): bool => $u->mayLogIn($now)
                && $this->zugriff->berechtigungen($u->id)->darf(Permission::DocumentEdit),
        ));
    }
}
