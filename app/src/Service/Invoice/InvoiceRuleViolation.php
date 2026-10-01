<?php

declare(strict_types=1);

namespace App\Service\Invoice;

/**
 * A rule of the review page (issue #37/M6-3) that the entered data or the
 * document's state breaks. The message is German and shown as it is - it
 * never contains an amount, a name or anything else of the receipt
 * (CLAUDE.md section 4).
 *
 * @param string|null $feld the form field at fault, so the page can mark it
 */
final class InvoiceRuleViolation extends \DomainException
{
    public function __construct(string $message, public readonly ?string $feld = null, public readonly ?int $konfliktId = null)
    {
        parent::__construct($message);
    }
}
