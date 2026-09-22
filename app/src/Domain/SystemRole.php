<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Permission as P;
use App\Domain\PermissionScope as S;

/**
 * The six roles every installation ships with (docs/spec/01-sicherheit.md
 * section 4). The value is `role.system_key`: the key code looks a system
 * role up by. A system role can neither be renamed nor deleted.
 *
 * standardRechte() is the matrix of the spec and what migrations/
 * 010_role.sql seeds; tests/Domain/SystemRoleTest.php holds all three
 * (spec, enum, seed) together. After installation the rights of every
 * system role except Admin are data and can be adjusted
 * (App\Service\Account\RoleService) - Admin always holds every right,
 * including ones a later release adds, so nobody can lock the club out of
 * its own administration.
 */
enum SystemRole: string
{
    case Admin = 'admin';
    case Vorstand = 'vorstand';
    case Finanzen = 'finanzen';
    case Kassenpruefer = 'kassenpruefer';
    case Steuerberater = 'steuerberater';
    case Vereinsverantwortlicher = 'vereinsverantwortlicher';

    public function bezeichnung(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Vorstand => 'Vorstand',
            self::Finanzen => 'Finanzen',
            self::Kassenpruefer => 'Kassenprüfer',
            self::Steuerberater => 'Steuerberater',
            self::Vereinsverantwortlicher => 'Vereinsverantwortlicher',
        };
    }

    /**
     * External accounts: mandatory end date, 2FA always required, reading
     * only (docs/spec/01-sicherheit.md section 4).
     */
    public function istExtern(): bool
    {
        return $this === self::Kassenpruefer || $this === self::Steuerberater;
    }

    /**
     * @return array<string, PermissionScope> keyed by Permission value
     */
    public function standardRechte(): array
    {
        $alle = static fn(P ...$rechte): array => array_fill_keys(
            array_map(static fn(P $p): string => $p->value, $rechte),
            S::Alle,
        );

        return match ($this) {
            self::Admin => $alle(...P::cases()),
            self::Vorstand => $alle(
                P::InboxView, P::DocumentSubmitInternal, P::BankView, P::ReportView,
                P::ExportZip, P::ExportCsv, P::AuditView,
            ),
            self::Finanzen => $alle(
                P::InboxView, P::DocumentEdit, P::DocumentSubmitInternal, P::SupplierManage,
                P::BankImport, P::BankBook, P::BankView, P::MatchingEdit, P::ReportView,
                P::ExportZip, P::ExportCsv, P::ArchiveImport,
            ),
            self::Kassenpruefer => $alle(
                P::InboxView, P::BankView, P::ReportView, P::ExportZip, P::ExportCsv, P::AuditView,
            ),
            self::Steuerberater => $alle(
                P::InboxView, P::BankView, P::ReportView, P::ExportZip, P::ExportCsv,
            ),
            self::Vereinsverantwortlicher => [
                P::InboxView->value => S::Kostenstelle,
                P::DocumentSubmitInternal->value => S::Alle,
                P::ReportView->value => S::Kostenstelle,
            ],
        };
    }
}
