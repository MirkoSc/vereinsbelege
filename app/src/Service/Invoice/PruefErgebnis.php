<?php

declare(strict_types=1);

namespace App\Service\Invoice;

/**
 * What saving the review page did (issue #37/M6-3).
 *
 * @param list<string> $warnungen hints that did not stop the save (the sum
 *        check), German, without any value of the receipt
 * @param list<string> $gelernt what the receipt taught its supplier (issue
 *        #201/M7-6b) - German labels ("IBAN"), no values
 * @param bool $nichtGelernt learning was refused: an id belongs to another
 *        supplier since the page was shown
 */
final readonly class PruefErgebnis
{
    /**
     * @param list<string> $warnungen
     * @param list<string> $gelernt
     */
    public function __construct(
        public int $invoiceId,
        public bool $geprueft,
        public array $warnungen,
        public bool $lieferantAngelegt,
        public array $gelernt = [],
        public bool $nichtGelernt = false,
    ) {
    }
}
