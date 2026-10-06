<?php

declare(strict_types=1);

namespace App\Service\Processing;

/**
 * The text layer of one PDF, page by page, and the decision whether it is
 * good enough to stand in for the page images when the AI reads the
 * receipt (docs/spec/03-erfassung-und-ki.md section 3, issue #45/M7-3:
 * "Heuristik: > 200 Zeichen, Anteil Buchstaben").
 *
 * Usable means: more than MIN_ZEICHEN characters that are not whitespace,
 * and at least MIN_BUCHSTABENANTEIL of them letters (`\p{L}`). The first
 * rules out a scan with nothing but a page number or a stamp in its text
 * layer; the second a text layer that is there but unreadable - a font
 * without a usable encoding produces U+FFFD or symbol soup, never mostly
 * letters. Digits are not letters: an invoice is full of them, but a text
 * of digits alone (a broken map that hits only the numerals) is no basis
 * for an AI either. Real invoices land around 55-75 %.
 */
final readonly class Textlayer
{
    public const int MIN_ZEICHEN = 200;

    public const float MIN_BUCHSTABENANTEIL = 0.5;

    /** Separates pages in text() - a form feed, as pdftotext does. */
    public const string SEITENWECHSEL = "\f";

    /**
     * @param list<string> $seiten UTF-8 text per page, in page order
     */
    public function __construct(
        public TextlayerBefund $befund,
        public array $seiten,
    ) {
    }

    public function text(): string
    {
        return implode(self::SEITENWECHSEL, $this->seiten);
    }

    /** Characters that are not whitespace. */
    public function zeichen(): int
    {
        return (int) preg_match_all('/\S/u', $this->text());
    }

    /** Share of letters among the characters that are not whitespace, 0.0 for none. */
    public function buchstabenanteil(): float
    {
        $zeichen = $this->zeichen();

        return $zeichen === 0 ? 0.0 : (int) preg_match_all('/\p{L}/u', $this->text()) / $zeichen;
    }

    public function brauchbar(): bool
    {
        return $this->befund === TextlayerBefund::Gelesen
            && $this->zeichen() > self::MIN_ZEICHEN
            && $this->buchstabenanteil() >= self::MIN_BUCHSTABENANTEIL;
    }
}
