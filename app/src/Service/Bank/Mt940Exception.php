<?php

declare(strict_types=1);

namespace App\Service\Bank;

/**
 * The file is no MT940 statement the parser can read
 * (docs/spec/04-bank-und-abgleich.md section 2). The message is German
 * (the import page shows it) and names only the line number and the field
 * tag - never a line's content, an amount or an account (CLAUDE.md
 * section 4).
 */
final class Mt940Exception extends \RuntimeException
{
}
