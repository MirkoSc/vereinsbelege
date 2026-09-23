<?php

declare(strict_types=1);

namespace App\Service\MasterData;

/**
 * A cost center change broke one of the rules of docs/spec/
 * 02-datenmodell.md "Fachdaten". The message is German and meant for the
 * admin page as it stands - it names cost centers, never club data.
 */
final class CostCenterRuleViolation extends \DomainException
{
}
