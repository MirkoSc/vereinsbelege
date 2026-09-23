<?php

declare(strict_types=1);

namespace App\Service\Inbox;

/**
 * An inbox action broke a rule (issue #27/M4-5): a missing reason, a date
 * in the past, a status change the status model does not allow. The
 * message is German and meant for the page as it stands - it names the
 * rule, never club data (CLAUDE.md section 4).
 */
final class InboxRuleViolation extends \DomainException
{
}
