<?php

declare(strict_types=1);

namespace App\Service\Report;

use App\Domain\BankAccount;

/**
 * The balance of one account at the dashboard's reference day (issue #72),
 * in cents of the account's currency. Decrypted - only in a session with
 * the vault unlocked.
 */
final readonly class Kontostand
{
    public function __construct(
        public BankAccount $konto,
        public int $stand,
    ) {
    }
}
