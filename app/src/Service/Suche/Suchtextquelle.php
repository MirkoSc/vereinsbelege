<?php

declare(strict_types=1);

namespace App\Service\Suche;

use App\Domain\ArtifactKind;
use App\Domain\Document;
use App\Service\Crypto\Vault;

/**
 * One place a document's recognised text comes from (issue #94,
 * docs/spec/03-erfassung-und-ki.md section 10): the PDF text layer, a read
 * e-invoice, the AI's extraction - and, once the optional worker exists
 * (issues #51-#55), its OCR text. A new source is one more class handed to
 * App\Service\Suche\Volltextsuche; nothing else changes.
 *
 * Every source reads `document_artifact` rows, so the index notices new
 * text by new rows of the kinds a source names (arten()).
 */
interface Suchtextquelle
{
    /**
     * The artifact kinds this source reads. A new row of one of them puts
     * the document back into the session index.
     *
     * @return list<ArtifactKind>
     */
    public function arten(): array;

    /**
     * The plain texts of $document this source knows - decrypted with the
     * session's vault, never stored as they are.
     *
     * @return list<string>
     */
    public function texte(Document $document, Vault $vault): array;
}
