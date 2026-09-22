<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Domain\Berechtigungen;
use App\Domain\User;

/**
 * The logged-in user as a request needs them: the row, plus the display name
 * already decrypted for the header.
 *
 * It exists so that App\Http\LoginGuard can re-check the account on every
 * request without holding a crypto key or a repository itself - it gets this
 * from a closure and looks no further. The re-check matters: locking an
 * account or letting an external account expire has to take effect now, not
 * at the next login, and a user deleted underneath a live session must stop
 * being one.
 *
 * Together with the row come its rights (App\Domain\Berechtigungen), for
 * the same reason: the guard checks them per request, per route.
 *
 * The decrypted name lives for the length of one request. It is deliberately
 * not put into the session, which is a file on disk
 * (docs/spec/01-sicherheit.md section 2).
 */
final readonly class SessionUser
{
    public function __construct(
        public User $user,
        public string $anzeigename,
        /**
         * The account's rights and scopes (issue #19/M3-6), loaded on
         * every request together with the row - taking a role away takes
         * effect on the next click.
         */
        public Berechtigungen $berechtigungen,
    ) {
    }

    /**
     * @return array<string, string|int>
     */
    public function __debugInfo(): array
    {
        return ['id' => $this->user->id, 'anzeigename' => '*** display name ***'];
    }
}
