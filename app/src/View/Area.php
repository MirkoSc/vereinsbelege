<?php

declare(strict_types=1);

namespace App\View;

/**
 * The three areas of the application (CLAUDE.md section 3). Each one gets
 * its own chrome around the same shell: the public pages carry no navigation
 * at all, the user area and the admin area carry theirs.
 *
 * The area is what a controller picks when it renders, and it decides more
 * than looks: the public pages must not start a session, so their layout
 * never receives a CSRF token and never sends one with htmx.
 */
enum Area: string
{
    /** Reachable without an account: submission page, start page, errors. */
    case Oeffentlich = 'oeffentlich';

    /** Everything behind the login (/app/...). */
    case App = 'app';

    /** Administration (/admin/...). */
    case Admin = 'admin';

    public function bezeichnung(): string
    {
        return match ($this) {
            self::Oeffentlich => 'Öffentlich',
            self::App => 'Belegverwaltung',
            self::Admin => 'Verwaltung',
        };
    }

    /** Where the brand in the header links to. */
    public function startseite(): string
    {
        return match ($this) {
            self::Oeffentlich => '/',
            self::App => '/app',
            self::Admin => '/admin',
        };
    }

    /**
     * The navigation of the area, in display order.
     *
     * Entries without a route are the areas CLAUDE.md section 3 names but
     * that are not built yet; they render as inactive so the navigation
     * shows the whole picture instead of growing a new special case with
     * every milestone. Rights come with the Permission enum (M3-6) and will
     * filter this list server side - the milestone note is only a label.
     *
     * @return list<NavItem>
     */
    public function navigation(): array
    {
        return match ($this) {
            self::Oeffentlich => [],
            self::App => [
                new NavItem('Start', '/app'),
                new NavItem('Posteingang', meilenstein: 'M4'),
                new NavItem('Belege', meilenstein: 'M6'),
                new NavItem('Lieferanten', meilenstein: 'M7'),
                new NavItem('Konten', meilenstein: 'M8'),
                new NavItem('Abgleich', meilenstein: 'M10'),
                new NavItem('Auswertungen', meilenstein: 'M11'),
            ],
            self::Admin => [
                new NavItem('Update', '/admin/update'),
                new NavItem('Designsystem', '/admin/designsystem'),
                new NavItem('Benutzer', meilenstein: 'M3'),
                new NavItem('Rollen', meilenstein: 'M3'),
                new NavItem('Tresor', meilenstein: 'M3'),
                new NavItem('KI-Anbieter', meilenstein: 'M7'),
                new NavItem('Kategorien', meilenstein: 'M6'),
                new NavItem('Speicher', meilenstein: 'M2'),
                new NavItem('Mail', meilenstein: 'M12'),
                new NavItem('Backup', meilenstein: 'M1'),
                new NavItem('Audit-Log', meilenstein: 'M3'),
                new NavItem('Einstellungen', meilenstein: 'M3'),
            ],
        };
    }
}
