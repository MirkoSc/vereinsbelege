<?php

declare(strict_types=1);

namespace App\Service\Report;

/**
 * A budget form that cannot be saved (issue #92). The message is shown to
 * the user as is; $feld names the form field it belongs to.
 */
final class BudgetFehler extends \DomainException
{
    public function __construct(string $message, public readonly string $feld = '')
    {
        parent::__construct($message);
    }
}
