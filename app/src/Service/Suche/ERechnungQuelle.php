<?php

declare(strict_types=1);

namespace App\Service\Suche;

use App\Domain\ArtifactKind;
use App\Domain\Document;
use App\Service\Crypto\Vault;
use App\Service\Document\Texterkennung;

/**
 * The fields of a read e-invoice (issue #46/M7-4): supplier, numbers,
 * purpose - an XRechnung has no text layer to search otherwise.
 */
final readonly class ERechnungQuelle implements Suchtextquelle
{
    public function __construct(private Texterkennung $texterkennung)
    {
    }

    public function arten(): array
    {
        return [ArtifactKind::ERechnung];
    }

    public function texte(Document $document, Vault $vault): array
    {
        $extraktion = $this->texterkennung->eRechnung($document, $vault)?->extraktion;

        return $extraktion === null ? [] : Extraktionstexte::aus($extraktion);
    }
}
