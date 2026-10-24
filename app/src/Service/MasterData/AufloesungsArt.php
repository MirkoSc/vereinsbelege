<?php

declare(strict_types=1);

namespace App\Service\MasterData;

/**
 * What the supplier resolution (issue #48/M7-6, docs/spec/
 * 03-erfassung-und-ki.md section 7) came to - stored with the result
 * (App\Service\MasterData\Aufloesung), so the value is never renamed.
 */
enum AufloesungsArt: string
{
    /** Steps 1-4: exactly one supplier of the direction's role carries the id or the name. */
    case Treffer = 'treffer';

    /** Step 5: no exact match, but names alike enough - a suggestion a person confirms. */
    case Vorschlag = 'vorschlag';

    /** Step 4: several suppliers of the role carry the name - nobody guesses which one. */
    case Mehrdeutig = 'mehrdeutig';

    /**
     * Steps 1-4: the id or name belongs to a supplier of the other role (a
     * payer on an expense). Not chosen - the form would refuse it - and no
     * new one created: it would be the same partner twice.
     */
    case RollePasstNicht = 'rolle';

    /** Step 6: nothing matched, a new supplier was created (`created_via = ki`, `needs_review`). */
    case Angelegt = 'angelegt';

    /** Nothing to resolve: the suggestion names no supplier, or creating one was not possible. */
    case Ohne = 'ohne';
}
