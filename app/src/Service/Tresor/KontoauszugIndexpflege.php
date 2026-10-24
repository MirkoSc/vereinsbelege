<?php

declare(strict_types=1);

namespace App\Service\Tresor;

use App\Repository\VaultRotationRepository;
use App\Service\Crypto\Vault;

/**
 * `bank_import.source_bi` (issue #90): the account line of an imported
 * statement file, used only to suggest the account for the next file of the
 * same source. Recomputing it means parsing the stored file, which a short
 * request cannot promise; it is cleared instead. Effect: the first statement
 * after a rotation is matched by the account's IBAN
 * (`bank_account.iban_bi`, recomputed) or picked by hand once.
 */
final readonly class KontoauszugIndexpflege implements Indexpflege
{
    public function __construct(private VaultRotationRepository $rotation)
    {
    }

    public function tabelle(): string
    {
        return 'bank_import';
    }

    public function spalten(): array
    {
        return ['bank_import.source_bi'];
    }

    public function pflege(int $id, array $zeile, Vault $tresor, int $alteVersion, \DateTimeImmutable $now): void
    {
        if ($zeile['source_bi'] !== null) {
            $this->rotation->setzeSpalte($this->tabelle(), 'id', $id, 'source_bi', null);
        }
    }
}
