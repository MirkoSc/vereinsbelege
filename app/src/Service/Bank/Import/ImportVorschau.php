<?php

declare(strict_types=1);

namespace App\Service\Bank\Import;

use App\Domain\BankAccount;
use App\Domain\BankImportRecord;
use App\Domain\BankImportStats;

/**
 * Everything the import page shows about one statement file (M9-4,
 * issue #62, docs/spec/04-bank-und-abgleich.md section 4): period, counts
 * (new, duplicate, row errors, pending, before the opening date), the
 * balance check and the bookings themselves. Worked out from the encrypted
 * file on every view - session only, never stored.
 */
final readonly class ImportVorschau
{
    /**
     * @param list<VorschauPosten> $posten
     * @param ?string $anschlussHinweis why the file could not be compared
     *        with the known account balance, or null
     */
    public function __construct(
        public BankImportRecord $import,
        public ImportDatei $datei,
        public ?BankAccount $konto,
        public array $posten,
        public BankImportStats $zaehler,
        public SaldenErgebnis $salden,
        public ?string $anschlussHinweis,
        public string $dateiname,
    ) {
    }

    /** Whether "Übernehmen" can go ahead: an account, and something to write. */
    public function uebernehmbar(): bool
    {
        return $this->konto !== null && $this->zaehler->gesamt > 0;
    }

    /** Warnings the confirm button names, so nobody confirms past them by accident. */
    public function hatWarnungen(): bool
    {
        return $this->zaehler->fehler > 0 || $this->salden->abweichungen() !== [];
    }
}
