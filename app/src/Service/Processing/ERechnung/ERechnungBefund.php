<?php

declare(strict_types=1);

namespace App\Service\Processing\ERechnung;

/**
 * What looking for a structured e-invoice found (issue #46/M7-4). Only
 * `Gelesen` carries fields; every other case leaves the receipt to the
 * text layer, the page images and a person - never a guess
 * (CLAUDE.md section 6). Technical, never content - like
 * App\Service\Processing\TextlayerBefund.
 */
enum ERechnungBefund: string
{
    /** Read: number, date, currency and gross amount at least. */
    case Gelesen = 'gelesen';

    /** No e-invoice at all: a PDF without an invoice XML attached. */
    case Keine = 'keine';

    /** XML that does not parse, carries a DOCTYPE, or lacks a mandatory field. */
    case Defekt = 'defekt';

    /** An invoice XML in a format this reader does not follow (ZUGFeRD 1.0). */
    case NichtUnterstuetzt = 'nicht_unterstuetzt';

    /** Beyond the reader's limits (XML size, decoded PDF data). */
    case ZuGross = 'zu_gross';

    public function label(): string
    {
        return match ($this) {
            self::Gelesen => 'gelesen',
            self::Keine => 'keine E-Rechnung',
            self::Defekt => 'nicht lesbar',
            self::NichtUnterstuetzt => 'Format nicht unterstützt',
            self::ZuGross => 'zu groß',
        };
    }
}
