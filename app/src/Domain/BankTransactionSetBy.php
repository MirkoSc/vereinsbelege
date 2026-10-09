<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Who set the receipt status or the category of a booking
 * (`bank_transaction.doc_source`/`category_source`, M9-6, issue #64,
 * docs/spec/04-bank-und-abgleich.md section 5 "Stand M9-6"): the default of
 * the direction, a rule, or a person. A rule only touches what is still the
 * default, and taking a rule back only what the rule set. The values are a
 * storage format; `standard` never occurs for a category - a booking without
 * one has none.
 */
enum BankTransactionSetBy: string
{
    case Standard = 'standard';
    case Regel = 'regel';
    case Manuell = 'manuell';
}
