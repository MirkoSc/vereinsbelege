<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One `budget` row as stored (issue #92, migrations/044_budget.sql):
 * structure plus the sealed data key and the ciphertext of the amount.
 * What App\Repository\BudgetRepository returns; App\Service\Report\Budgets
 * decrypts it into a App\Service\Report\Budgetsoll.
 */
final readonly class BudgetRecord
{
    public function __construct(
        public int $id,
        public int $year,
        public ?int $categoryId,
        public ?int $costCenterId,
        public string $dekSealed,
        public string $dataEnc,
    ) {
    }
}
