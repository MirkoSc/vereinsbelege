<?php

declare(strict_types=1);

namespace App\Service\Account;

/**
 * A role or an assignment broke one of the rules of docs/spec/
 * 01-sicherheit.md section 4. The message is German and meant for the admin
 * page as it stands - it names roles and rights, never club data.
 */
final class RoleRuleViolation extends \DomainException
{
}
