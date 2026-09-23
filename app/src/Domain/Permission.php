<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Every right the application checks (docs/spec/01-sicherheit.md section 4,
 * issue #19/M3-6). Checked server side per action - a route declares the
 * one it needs (App\Http\Zugriff, app/src/routes.php) and App\Http\LoginGuard
 * enforces it; hiding a link is never the check (CLAUDE.md section 4).
 *
 * The values are what `role.permissions` stores (migrations/010_role.sql),
 * so they are part of the data format: renaming one needs a migration.
 */
enum Permission: string
{
    case InboxView = 'inbox.view';
    case DocumentEdit = 'document.edit';
    case DocumentSubmitInternal = 'document.submit_internal';
    case SupplierManage = 'supplier.manage';
    case BankImport = 'bank.import';
    case BankBook = 'bank.book';
    case BankView = 'bank.view';
    case MatchingEdit = 'matching.edit';
    case ReportView = 'report.view';
    case ExportZip = 'export.zip';
    case ExportCsv = 'export.csv';
    case ArchiveImport = 'archive.import';
    case AuditView = 'audit.view';
    case AdminUsers = 'admin.users';
    case AdminVaultGrant = 'admin.vault_grant';
    case AdminSettings = 'admin.settings';
    case AdminSystem = 'admin.system';

    public function bezeichnung(): string
    {
        return match ($this) {
            self::InboxView => 'Posteingang sehen',
            self::DocumentEdit => 'Belege prüfen, bearbeiten, festschreiben',
            self::DocumentSubmitInternal => 'Belege intern hochladen',
            self::SupplierManage => 'Lieferanten verwalten',
            self::BankImport => 'Kontoauszüge importieren',
            self::BankBook => 'Manuell buchen, Kasse führen',
            self::BankView => 'Konten und Buchungen sehen',
            self::MatchingEdit => 'Abgleich Beleg ↔ Buchung',
            self::ReportView => 'Auswertungen sehen',
            self::ExportZip => 'ZIP-Export',
            self::ExportCsv => 'CSV-Export',
            self::ArchiveImport => 'Archiv importieren',
            self::AuditView => 'Audit-Log sehen',
            self::AdminUsers => 'Benutzer und Rollen verwalten',
            self::AdminVaultGrant => 'Tresor-Freigaben erteilen',
            self::AdminSettings => 'Einstellungen (KI, Mail, Speicher, Kategorien, Kostenstellen)',
            self::AdminSystem => 'System (Backup, Update, Wartung)',
        };
    }

    /**
     * The `admin.*` rights. Holding at least one of them is what opens
     * `/admin/*` at all (docs/spec/01-sicherheit.md section 4).
     */
    public function istAdmin(): bool
    {
        return str_starts_with($this->value, 'admin.');
    }

    /**
     * Rights that only read. External roles (Kassenprüfer, Steuerberater)
     * may hold nothing else - "ausschließlich lesend" (docs/spec/
     * 01-sicherheit.md section 4). Exports count as reading: they write
     * nothing into the application.
     */
    public function istLesend(): bool
    {
        return match ($this) {
            self::InboxView, self::BankView, self::ReportView,
            self::ExportZip, self::ExportCsv, self::AuditView => true,
            default => false,
        };
    }

    /**
     * Rights a role may restrict to the cost centers assigned to the user
     * ("eigene Kostenstelle", docs/spec/01-sicherheit.md section 4). The
     * others are either not tied to a cost center or make no sense halved.
     */
    public function kostenstellenFaehig(): bool
    {
        return match ($this) {
            self::InboxView, self::ReportView => true,
            default => false,
        };
    }
}
