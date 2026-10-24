<?php

declare(strict_types=1);

namespace App\Service\Invoice;

/**
 * Where a series stands against its next expected receipt (issue #58/M8-2,
 * docs/spec/03-erfassung-und-ki.md section 9): only a confirmed series
 * with a next date can be due or overdue.
 */
enum SerienFaelligkeit
{
    /** Suggestion, dismissed or without a fixed rhythm: no next date. */
    case Keine;
    /** The next date is still ahead. */
    case Offen;
    /** The next date has come, the tolerance is not over yet. */
    case Faellig;
    /** Next date plus tolerance is past and no receipt came. */
    case Ueberfaellig;

    public function label(): string
    {
        return match ($this) {
            self::Keine => '–',
            self::Offen => 'erwartet',
            self::Faellig => 'fällig',
            self::Ueberfaellig => 'überfällig',
        };
    }
}
