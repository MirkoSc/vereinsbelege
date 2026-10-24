<?php

declare(strict_types=1);

namespace App\Installer;

/**
 * A refused access step after a restore (App\Installer\RestoreAccess): the
 * message is meant for the installer page, in German, and names nothing
 * about the account but what the admin typed themselves.
 */
final class RestoreAccessException extends \RuntimeException
{
}
