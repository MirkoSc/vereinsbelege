<?php

declare(strict_types=1);

namespace App\Service\Rechtstexte;

/**
 * The texts a club maintains under /admin/rechtstexte (issue #80/M13-3,
 * E-46): the two public legal pages and the privacy notice next to the
 * consent checkbox on /einreichen.
 *
 * Each one ships a template (App\Config\Paths::dataDir() .
 * '/rechtstexte/<value>.md', read by Rechtstexte) with
 * placeholders in square brackets. The templates are a starting point,
 * not legal advice - the admin page and the public page both say so until
 * the club has saved its own text.
 */
enum Rechtstext: string
{
    case Impressum = 'impressum';
    case Datenschutz = 'datenschutz';
    case EinreichungHinweis = 'einreichung_hinweis';

    /**
     * A TEXT column holds 65 535 bytes; the cap stays well below so a
     * text can never be cut off by the database.
     */
    public const int MAX_BYTES = 60_000;

    public function settingName(): string
    {
        return 'rechtstext_' . $this->value;
    }

    public function titel(): string
    {
        return match ($this) {
            self::Impressum => 'Impressum',
            self::Datenschutz => 'Datenschutzerklärung',
            self::EinreichungHinweis => 'Datenschutz-Hinweis auf „Beleg einreichen“',
        };
    }

    /** The public page that shows the text, null for the notice on /einreichen. */
    public function pfad(): ?string
    {
        return match ($this) {
            self::Impressum => '/impressum',
            self::Datenschutz => '/datenschutz',
            self::EinreichungHinweis => null,
        };
    }
}
