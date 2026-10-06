<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Result of the balance check of a statement import (`bank_import.
 * balance_check`, M9-4, issue #62, docs/spec/04-bank-und-abgleich.md
 * sections 2-4). A mismatch is a warning, never a reason to refuse the
 * import. The values are a storage format.
 */
enum BalanceCheck: string
{
    case Ok = 'ok';
    case Abweichung = 'abweichung';

    /** The file carries no balances (a CSV export without a balance column). */
    case NichtVerfuegbar = 'n.v.';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'Salden stimmen',
            self::Abweichung => 'Salden weichen ab',
            self::NichtVerfuegbar => 'keine Salden in der Datei',
        };
    }
}
