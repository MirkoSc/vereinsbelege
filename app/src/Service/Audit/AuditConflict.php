<?php

declare(strict_types=1);

namespace App\Service\Audit;

/**
 * Another request appended to the audit log between reading the head and
 * inserting - the id is taken. AuditLog::record() retries on it.
 */
final class AuditConflict extends \RuntimeException
{
}
