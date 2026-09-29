<?php

declare(strict_types=1);

namespace App\Service\MasterData;

/**
 * A category change broke one of the rules of docs/spec/02-datenmodell.md
 * "Kategorien". The message is German and meant for the admin page as it
 * stands - it names categories, never club data.
 */
final class CategoryRuleViolation extends \DomainException
{
}
