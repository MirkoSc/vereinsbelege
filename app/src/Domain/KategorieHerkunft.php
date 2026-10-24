<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Where the category suggested for a receipt comes from (M7-7, issue #49,
 * docs/spec/03-erfassung-und-ki.md section 8), in the order they win: a
 * rule of the club, the supplier's own default, what the supplier's
 * checked receipts had, the AI.
 */
enum KategorieHerkunft: string
{
    case Regel = 'regel';
    case Lieferant = 'lieferant';
    case Gelernt = 'gelernt';
    case Ki = 'ki';
}
