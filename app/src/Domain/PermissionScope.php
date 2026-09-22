<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * How far a right a role grants reaches (docs/spec/01-sicherheit.md
 * section 4): everywhere, or only in the cost centers assigned to the user
 * (`user_cost_center`, "Vereinsverantwortlicher"). Stored as the value of
 * each key in `role.permissions` (migrations/010_role.sql).
 */
enum PermissionScope: string
{
    case Alle = 'alle';
    case Kostenstelle = 'kostenstelle';

    /** Of two grants of the same right, the wider one counts. */
    public function weiter(self $andere): self
    {
        return $this === self::Alle || $andere === self::Alle ? self::Alle : self::Kostenstelle;
    }
}
