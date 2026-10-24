<?php

declare(strict_types=1);

namespace App\Service\Bank;

/**
 * Where an unpaid receipt stands against its due date (M10-5, issue #69,
 * docs/spec/04-bank-und-abgleich.md section 5 "Übersichten", E-37).
 */
enum BelegFaelligkeit
{
    /** Days ahead within which an unpaid receipt counts as "fällig". */
    public const int BALD_TAGE = 7;

    /** The receipt names no due date. */
    case OhneDatum;
    /** The due date is more than BALD_TAGE days ahead. */
    case Offen;
    /** Due today or within BALD_TAGE days. */
    case Faellig;
    /** The due date has passed. */
    case Ueberfaellig;

    public static function fuer(?\DateTimeImmutable $faellig, \DateTimeImmutable $heute): self
    {
        if ($faellig === null) {
            return self::OhneDatum;
        }
        $tag = $faellig->format('Y-m-d');

        return match (true) {
            $tag < $heute->format('Y-m-d') => self::Ueberfaellig,
            $tag <= $heute->modify('+' . self::BALD_TAGE . ' days')->format('Y-m-d') => self::Faellig,
            default => self::Offen,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::OhneDatum => 'ohne Fälligkeit',
            self::Offen => 'offen',
            self::Faellig => 'fällig',
            self::Ueberfaellig => 'überfällig',
        };
    }
}
