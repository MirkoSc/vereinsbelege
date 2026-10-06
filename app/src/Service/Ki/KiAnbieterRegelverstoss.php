<?php

declare(strict_types=1);

namespace App\Service\Ki;

/**
 * A provider profile change broke one of the rules of
 * App\Service\Ki\KiAnbieterService. The message is German and meant for the
 * admin page as it stands - it never contains the API key.
 */
final class KiAnbieterRegelverstoss extends \DomainException
{
}
