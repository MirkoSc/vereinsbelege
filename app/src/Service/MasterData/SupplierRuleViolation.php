<?php

declare(strict_types=1);

namespace App\Service\MasterData;

/**
 * A supplier change broke one of the rules of docs/spec/02-datenmodell.md
 * "Lieferanten". The message is German and meant for the page as it stands,
 * which only renders in a session with the vault unlocked. It is never
 * logged: it may name the other supplier of a conflict.
 *
 * $konfliktId is that other supplier, when the rule was "this IBAN (VAT id,
 * ...) already belongs to someone" - the page links to it.
 */
final class SupplierRuleViolation extends \DomainException
{
    public function __construct(string $message, public readonly ?int $konfliktId = null)
    {
        parent::__construct($message);
    }
}
