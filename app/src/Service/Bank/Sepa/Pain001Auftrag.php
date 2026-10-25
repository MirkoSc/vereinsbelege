<?php

declare(strict_types=1);

namespace App\Service\Bank\Sepa;

/**
 * Everything one pain.001 file says (issue #85, E-42): the paying account
 * and its holder, the requested execution date and the transfers. Texts
 * are already in the SEPA character set (App\Service\Bank\Sepa\SepaText),
 * IBAN/BIC normalised - App\Service\Bank\Sepa\Pain001 only checks.
 */
final readonly class Pain001Auftrag
{
    /**
     * @param list<Pain001Ueberweisung> $ueberweisungen
     */
    public function __construct(
        public string $msgId,
        public string $pmtInfId,
        public \DateTimeImmutable $erstellt,
        public string $auftraggeber,
        public string $iban,
        public string $bic,
        public \DateTimeImmutable $ausfuehrung,
        public array $ueberweisungen,
    ) {
    }
}
