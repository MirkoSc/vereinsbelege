<?php

declare(strict_types=1);

namespace App\Service\Suche;

use App\Domain\ArtifactKind;
use App\Domain\Document;
use App\Service\Crypto\Vault;
use App\Service\Document\KiAuslesung;

/**
 * What the AI read from the document (issue #47/M7-5): supplier, numbers,
 * purpose, amount - for photographed receipts the only recognised text
 * there is. Only a valid extraction of the newest run counts; a failed one
 * has nothing to offer.
 */
final readonly class KiQuelle implements Suchtextquelle
{
    public function __construct(private KiAuslesung $kiAuslesung)
    {
    }

    public function arten(): array
    {
        return [ArtifactKind::Extraction];
    }

    public function texte(Document $document, Vault $vault): array
    {
        $extraktion = $this->kiAuslesung->auszug($document, $vault)?->extraktion;

        return $extraktion === null ? [] : Extraktionstexte::aus($extraktion);
    }
}
