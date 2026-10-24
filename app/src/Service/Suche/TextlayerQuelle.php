<?php

declare(strict_types=1);

namespace App\Service\Suche;

use App\Domain\ArtifactKind;
use App\Domain\Document;
use App\Service\Crypto\Vault;
use App\Service\Document\Texterkennung;

/**
 * The text layer of the document's PDFs, as the `extract_text` job stored
 * it (issue #45/M7-3) - every page, also a layer too thin for the AI: a
 * stamp or a few words are still worth finding.
 *
 * The optional worker's OCR text (07-worker.md) is meant to arrive as the
 * same kind `text`; if it does, this source already covers it.
 */
final readonly class TextlayerQuelle implements Suchtextquelle
{
    public function __construct(private Texterkennung $texterkennung)
    {
    }

    public function arten(): array
    {
        return [ArtifactKind::Text];
    }

    public function texte(Document $document, Vault $vault): array
    {
        return $this->texterkennung->seitentexte($document, $vault);
    }
}
