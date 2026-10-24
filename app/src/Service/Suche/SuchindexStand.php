<?php

declare(strict_types=1);

namespace App\Service\Suche;

/**
 * How far this session's full-text index is (issue #94): built through
 * once (`fertig`), and how many documents still wait for it (`offen`) -
 * new text since the last step, or the rest of the first build.
 */
final readonly class SuchindexStand
{
    public function __construct(public bool $fertig, public int $offen)
    {
    }

    /** Searchable without waiting: built through and nothing new. */
    public function aktuell(): bool
    {
        return $this->fertig && $this->offen === 0;
    }
}
