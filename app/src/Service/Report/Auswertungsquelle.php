<?php

declare(strict_types=1);

namespace App\Service\Report;

use App\Domain\BankAccount;
use App\Domain\Zugriffsbereich;
use App\Service\Crypto\Vault;

/**
 * Where a report page gets its decrypted input (issue #72): straight from
 * the database (Auswertungsdaten) or through the session cache in front of
 * it (AuswertungsCache). Pages depend on this, so they need not know which
 * one they hold - the dashboard (#72), "Wohin fließt das Geld" (#73) and the
 * Kassenprüfung (#74) all use the cache.
 */
interface Auswertungsquelle
{
    /** A ReportService for this installation's settings (sphaeren_aktiv). */
    public function rechner(): ReportService;

    /** The report lines of one basis and period, already narrowed to $bereich. */
    public function grundlage(Vault $vault, Grundlage $grundlage, Zeitraum $zeitraum, Zugriffsbereich $bereich): Auswertungsgrundlage;

    /**
     * The accounts and the bookings up to the end of $zeitraum, for
     * ReportService::kontostaende()/kontosalden().
     *
     * @return array{konten: list<BankAccount>, bewegungen: list<Kontobewegung>, unlesbar: int}
     */
    public function kontobewegungen(Vault $vault, Zeitraum $zeitraum, Zugriffsbereich $bereich): array;
}
