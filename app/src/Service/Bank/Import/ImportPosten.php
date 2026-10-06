<?php

declare(strict_types=1);

namespace App\Service\Bank\Import;

use App\Service\Bank\Umsatz;

/**
 * One booking of a statement file, the same for MT940 and CSV (M9-4,
 * issue #62). A value object: nothing here is stored or logged as it is.
 *
 * `nr` is the position in App\Service\Bank\Import\ImportDatei::$posten
 * (chronological, 0-based) - the cursor of the step chain counts in it.
 */
final readonly class ImportPosten
{
    /**
     * @param ?int $saldoNachCent the balance after this booking, if the
     *        file carries one (a CSV column like VR Bank's "Saldo nach
     *        Buchung"); never for MT940, whose balances belong to the
     *        statement
     * @param ?int $zeile the CSV line, for messages; null for MT940
     */
    public function __construct(
        public int $nr,
        public Umsatz $umsatz,
        public string $waehrung,
        public ?int $saldoNachCent,
        public ?int $zeile,
    ) {
    }
}
