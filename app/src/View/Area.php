<?php

declare(strict_types=1);

namespace App\View;

use App\Domain\Berechtigungen;
use App\Domain\Permission;

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
     * every milestone.
     *
     * With the account's rights given, only the entries it may open are
     * returned (issue #19/M3-6) - filtered server side, and still only
     * courtesy: every route checks its right itself (App\Http\LoginGuard).
     * Without, the full list (the public pages, the tests).
     *
     * @return list<NavItem>
     */
    public function navigation(?Berechtigungen $berechtigungen = null): array
    {
        $eintraege = match ($this) {
            self::Oeffentlich => [],
            self::App => [
                new NavItem('Start', '/app'),
                new NavItem('Sicherheit', '/app/sicherheit'),
                new NavItem('Posteingang', meilenstein: 'M4', recht: Permission::InboxView),
                new NavItem('Belege', meilenstein: 'M6', recht: Permission::InboxView),
                new NavItem('Lieferanten', meilenstein: 'M7', recht: Permission::SupplierManage),
                new NavItem('Konten', meilenstein: 'M8', recht: Permission::BankView),
                new NavItem('Abgleich', meilenstein: 'M10', recht: Permission::MatchingEdit),
                new NavItem('Auswertungen', meilenstein: 'M11', recht: Permission::ReportView),
            ],
            self::Admin => [
                new NavItem('Update', '/admin/update', recht: Permission::AdminSystem),
                new NavItem('Designsystem', '/admin/designsystem'),
                new NavItem('Benutzer', meilenstein: 'M3', recht: Permission::AdminUsers),
                new NavItem('Rollen', '/admin/rollen', recht: Permission::AdminUsers),
                new NavItem('Tresor', meilenstein: 'M3', recht: Permission::AdminVaultGrant),
                new NavItem('KI-Anbieter', meilenstein: 'M7', recht: Permission::AdminSettings),
                new NavItem('Kategorien', meilenstein: 'M6', recht: Permission::AdminSettings),
                new NavItem('Speicher', '/admin/speicher', recht: Permission::AdminSettings),
                new NavItem('Mail', '/admin/mail', recht: Permission::AdminSettings),
                new NavItem('Backup', meilenstein: 'M1', recht: Permission::AdminSystem),
                new NavItem('Audit-Log', meilenstein: 'M3', recht: Permission::AuditView),
                new NavItem('Einstellungen', meilenstein: 'M3', recht: Permission::AdminSettings),
            ],
        };

        if ($berechtigungen === null) {
            return $eintraege;
        }

        return array_values(array_filter(
            $eintraege,
            static fn(NavItem $eintrag): bool => $eintrag->sichtbarFuer($berechtigungen),
        ));
    }

    /**
     * Where /admin sends an account: the first admin page it may open.
     */
    public static function adminStartFuer(Berechtigungen $berechtigungen): string
    {
        foreach (self::Admin->navigation($berechtigungen) as $eintrag) {
            if ($eintrag->href !== null) {
                return $eintrag->href;
            }
        }

        return '/admin/designsystem';
    }
}
