<?php

declare(strict_types=1);

namespace App\Service\Tresor;

use App\Domain\InvoiceRecord;
use App\Repository\VaultRotationRepository;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\Invoice\Pruefung;

/**
 * `invoice.number_bi` (issue #90): the invoice number from the encrypted
 * data, indexed exactly as App\Service\Invoice\Pruefung writes it.
 */
final readonly class RechnungsIndexpflege implements Indexpflege
{
    public function __construct(private VaultRotationRepository $rotation)
    {
    }

    public function tabelle(): string
    {
        return 'invoice';
    }

    public function spalten(): array
    {
        return ['invoice.number_bi'];
    }

    public function pflege(int $id, array $zeile, Vault $tresor, int $alteVersion, \DateTimeImmutable $now): void
    {
        if ($zeile['number_bi'] === null) {
            return;
        }

        try {
            $nummer = Pruefung::entschluesseln($tresor, InvoiceRecord::fromRow($zeile))->invoiceNumber;
            $neu = $nummer === ''
                ? null
                : $tresor->blindIndex()->forValue(Pruefung::NUMMER_ZWECK, Pruefung::nummerFuerIndex($nummer));
        } catch (CryptoException | \JsonException) {
            $neu = null;
        }

        $this->rotation->setzeSpalte('invoice', 'id', $id, 'number_bi', $neu);
    }

    public function verwerfen(int $id, \DateTimeImmutable $now): void
    {
        $this->rotation->setzeSpalte('invoice', 'id', $id, 'number_bi', null);
    }
}
