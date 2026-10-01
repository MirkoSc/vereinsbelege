<?php

declare(strict_types=1);

namespace App\Service\Bank;

/**
 * An account or cash count change broke one of the rules of
 * docs/spec/02-datenmodell.md "Konten". The message is German and meant for
 * the page as it stands, which only renders in a session with the vault
 * unlocked. It is never logged: it may name the other account of an IBAN
 * conflict.
 *
 * $feld is the form field at fault, so the page can mark it; $konfliktId the
 * other account when the rule was "this IBAN already belongs to one".
 */
final class BankRuleViolation extends \DomainException
{
    public function __construct(string $message, public readonly ?string $feld = null, public readonly ?int $konfliktId = null)
    {
        parent::__construct($message);
    }
}
