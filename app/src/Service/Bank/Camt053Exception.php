<?php

declare(strict_types=1);

namespace App\Service\Bank;

/**
 * The file is no CAMT.053 statement the parser can read
 * (docs/spec/04-bank-und-abgleich.md section 2a). The message is German
 * (the import page shows it) and names only the position - statement,
 * entry, element - never what an element says, an amount or an account
 * (CLAUDE.md section 4).
 */
final class Camt053Exception extends \RuntimeException
{
}
