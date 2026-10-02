<?php

declare(strict_types=1);

namespace App\Service\Document;

/**
 * Resolving a suspected duplicate broke a rule (issue #40/M6-6): there is no
 * suspicion (any more), the document is locked or already decided, someone
 * else was faster. The message is German and meant for the page - it names
 * the rule, never club data (CLAUDE.md section 4).
 */
final class DuplikatRuleViolation extends \DomainException
{
}
