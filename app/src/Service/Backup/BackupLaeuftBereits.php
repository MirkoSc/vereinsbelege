<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * Another backup step chain is running, or one of its steps is (issue #112).
 */
final class BackupLaeuftBereits extends \RuntimeException
{
}
