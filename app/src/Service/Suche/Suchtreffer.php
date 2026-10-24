<?php

declare(strict_types=1);

namespace App\Service\Suche;

use App\Domain\InboxItem;

/**
 * One document the full-text search found (issue #94): the inbox row it
 * links to (plaintext columns only, already narrowed to the viewer's scope)
 * and a short piece of the text around the match, as segments
 * [text, is a match] for the view to escape and mark.
 */
final readonly class Suchtreffer
{
    /**
     * @param list<array{0: string, 1: bool}> $ausschnitt
     */
    public function __construct(public InboxItem $item, public array $ausschnitt)
    {
    }
}
