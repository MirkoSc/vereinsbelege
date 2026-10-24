<?php

declare(strict_types=1);

namespace App\Service\Tresor;

use App\Repository\VaultRotationRepository;
use App\Service\Bank\BankAccountService;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;

/**
 * `bank_account.iban_bi` (issue #90): the IBAN from the encrypted account
 * data, indexed as App\Service\Bank\BankAccountService writes it.
 */
final readonly class KontoIndexpflege implements Indexpflege
{
    public function __construct(
        private BankAccountService $konten,
        private VaultRotationRepository $rotation,
    ) {
    }

    public function tabelle(): string
    {
        return 'bank_account';
    }

    public function spalten(): array
    {
        return ['bank_account.iban_bi'];
    }

    public function pflege(int $id, array $zeile, Vault $tresor, int $alteVersion, \DateTimeImmutable $now): void
    {
        if ($zeile['iban_bi'] === null) {
            return;
        }

        try {
            $iban = $this->konten->finde($tresor, $id)?->data->iban ?? '';
            $neu = $iban === '' ? null : $tresor->blindIndex()->forValue(BankAccountService::IBAN_PURPOSE, $iban);
        } catch (CryptoException | \JsonException) {
            $neu = null;
        }

        $this->rotation->setzeSpalte('bank_account', 'id', $id, 'iban_bi', $neu);
    }

    public function verwerfen(int $id, \DateTimeImmutable $now): void
    {
        $this->rotation->setzeSpalte('bank_account', 'id', $id, 'iban_bi', null);
    }
}
