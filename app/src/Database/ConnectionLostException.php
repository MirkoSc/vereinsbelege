<?php

declare(strict_types=1);

namespace App\Database;

/**
 * The database connection died at a point where reopening it silently would
 * be wrong - currently: with a transaction open (see ConnectionFactory).
 */
final class ConnectionLostException extends \RuntimeException
{
}
