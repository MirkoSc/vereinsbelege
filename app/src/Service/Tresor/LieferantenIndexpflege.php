<?php

declare(strict_types=1);

namespace App\Service\Tresor;

use App\Repository\SupplierRepository;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\MasterData\SupplierService;

/**
 * `supplier_key.value_bi` (issue #90): the keys of a supplier are exactly
 * SupplierKeys::fuer() of its data (App\Service\MasterData\SupplierService),
 * so they are derived again under the new key. A supplier without keys - one
 * merged into another - stays without.
 */
final readonly class LieferantenIndexpflege implements Indexpflege
{
    public function __construct(
        private SupplierService $service,
        private SupplierRepository $lieferanten,
    ) {
    }

    public function tabelle(): string
    {
        return 'supplier';
    }

    public function spalten(): array
    {
        return ['supplier_key.value_bi'];
    }

    public function pflege(int $id, array $zeile, Vault $tresor, int $alteVersion, \DateTimeImmutable $now): void
    {
        if ($this->lieferanten->keys($id) === []) {
            return;
        }

        try {
            $lieferant = $this->service->finde($tresor, $id);
            $keys = $lieferant === null ? [] : $this->service->keys($tresor, $lieferant->data);
        } catch (CryptoException | \JsonException) {
            $keys = [];
        }

        $this->lieferanten->replaceKeys($id, $keys);
    }

    public function verwerfen(int $id, \DateTimeImmutable $now): void
    {
        $this->lieferanten->replaceKeys($id, []);
    }
}
