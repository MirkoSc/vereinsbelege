<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Service\Processing\ERechnung\ERechnungBefund;
use App\Service\Processing\ERechnung\ERechnungSyntax;

/**
 * What `extract_text` stored about a document's e-invoice (issue #46/M7-4),
 * decrypted for the review page and the AI step (M7-5):
 * Texterkennung::eRechnung(). `extraktion` is the invoice in the shape of
 * the AI's answer (`extract-v1`, docs/spec/03-erfassung-und-ki.md
 * section 6) - only when the befund is `gelesen`.
 */
final readonly class ERechnungAuszug
{
    /**
     * @param array<string, mixed>|null $extraktion
     */
    public function __construct(
        public ERechnungBefund $befund,
        public ?ERechnungSyntax $syntax,
        public string $profil,
        public ?array $extraktion,
    ) {
    }

    public function gelesen(): bool
    {
        return $this->befund === ERechnungBefund::Gelesen && $this->extraktion !== null;
    }
}
