<?php

declare(strict_types=1);

namespace App\Service\Account;

/**
 * A user-management rule said no (issue #20/M3-7): an address already in
 * use, locking oneself out, granting without an unlocked vault. The message
 * is a finished German sentence for the page - never containing an address
 * or any other value the admin typed, so it is also safe for a log line.
 * The counterpart of RoleRuleViolation for accounts.
 */
final class UserRuleViolation extends \RuntimeException
{
}
