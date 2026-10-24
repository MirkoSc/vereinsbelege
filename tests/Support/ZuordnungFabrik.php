<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Repository\AllocationRepository;
use App\Repository\BankAccountRepository;
use App\Repository\BankTransactionRepository;
use App\Repository\InvoiceRepository;
use App\Service\Audit\AuditLog;
use App\Service\Bank\BankAccountService;
use App\Service\Bank\Zuordnung;

/**
 * Builds the matching service (M10-1, issue #65) the way bootstrap.php
 * does - for every test that needs the review page or the ZIP export.
 */
final class ZuordnungFabrik
{
    public static function bauen(\PDO $pdo, AuditLog $audit): Zuordnung
    {
        return new Zuordnung(
            $pdo,
            new AllocationRepository($pdo),
            new InvoiceRepository($pdo),
            new BankTransactionRepository($pdo),
            new BankAccountService($pdo, new BankAccountRepository($pdo)),
            $audit,
        );
    }
}
