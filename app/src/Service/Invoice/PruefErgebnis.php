<?php

declare(strict_types=1);

namespace App\Service\Invoice;

/**
 * What saving the review page did (issue #37/M6-3).
 *
 * @param list<string> $warnungen hints that did not stop the save (the sum
 *        check), German, without any value of the receipt
 */
final readonly class PruefErgebnis
{
    /**
     * @param list<string> $warnungen
     */
    public function __construct(
        public int $invoiceId,
        public bool $geprueft,
        public array $warnungen,
        public bool $lieferantAngelegt,
    ) {
    }
}
