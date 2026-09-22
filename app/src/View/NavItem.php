<?php

declare(strict_types=1);

namespace App\View;

use App\Domain\Berechtigungen;
use App\Domain\Permission;

/**
 * One entry of an area's navigation.
 *
 * An entry without `$href` is a page a later milestone brings; it renders as
 * inactive text instead of a link, so nobody lands on a 404 and the
 * navigation still shows where things will be.
 */
final readonly class NavItem
{
    public function __construct(
        public string $label,
        public ?string $href = null,
        public ?string $meilenstein = null,
        /**
         * The right the page behind the entry needs (issue #19/M3-6); null
         * for pages every account of the area may open. Hiding the entry is
         * courtesy - the route checks the same right itself.
         */
        public ?Permission $recht = null,
    ) {
    }

    public function sichtbarFuer(Berechtigungen $berechtigungen): bool
    {
        return $this->recht === null || $berechtigungen->darf($this->recht);
    }

    public function verfuegbar(): bool
    {
        return $this->href !== null;
    }

    /**
     * True for the page currently shown - the layout turns that into
     * aria-current="page". Sub-pages count as the same entry: /admin/update
     * stays marked while /admin/update/verlauf is open.
     */
    public function istAktiv(string $pfad): bool
    {
        if ($this->href === null) {
            return false;
        }

        return $pfad === $this->href || str_starts_with($pfad, rtrim($this->href, '/') . '/');
    }
}
