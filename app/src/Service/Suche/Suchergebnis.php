<?php

declare(strict_types=1);

namespace App\Service\Suche;

/**
 * The answer of App\Service\Suche\Volltextsuche::suche(): the newest
 * matches the viewer may see, and whether there are more than shown.
 */
final readonly class Suchergebnis
{
    /**
     * @param list<Suchtreffer> $treffer
     */
    public function __construct(public array $treffer, public bool $mehr)
    {
    }
}
