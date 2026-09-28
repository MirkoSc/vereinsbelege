<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\Document;
use PHPUnit\Framework\TestCase;

/**
 * `document.processed_blob_ids` (issue #34/M5-4, migration 016): one entry
 * per original page, and every version a viewer may fetch.
 */
final class DocumentTest extends TestCase
{
    public function testWithoutProcessedPagesOnlyOriginalsAndPdfAreServed(): void
    {
        $document = Document::fromRow(self::zeile([3, 4], null, 9));

        self::assertSame([], $document->processedBlobIds);
        self::assertNull($document->processedBlobId(0));
        self::assertSame([3, 4, 9], $document->blobIds());
    }

    public function testProcessedPagesLineUpWithTheOriginals(): void
    {
        $document = Document::fromRow(self::zeile([3, 4], '[7, null]', null));

        self::assertSame([7, null], $document->processedBlobIds);
        self::assertSame(7, $document->processedBlobId(0));
        self::assertNull($document->processedBlobId(1));
        self::assertSame([3, 4, 7], $document->blobIds());
    }

    public function testAListOfTheWrongShapeIsNormalisedToOneEntryPerPage(): void
    {
        $kurz = Document::fromRow(self::zeile([3, 4], '[7]', null));
        self::assertSame([7, null], $kurz->processedBlobIds);

        $unsinn = Document::fromRow(self::zeile([3], '["7"]', null));
        self::assertSame([null], $unsinn->processedBlobIds);
        self::assertSame([3], $unsinn->blobIds());
    }

    /**
     * @param list<int> $originale
     * @return array<string, mixed>
     */
    private static function zeile(array $originale, ?string $verarbeitet, ?int $pdf): array
    {
        return [
            'id' => 1,
            'source' => 'einreichung',
            'submission_id' => null,
            'original_blob_ids' => json_encode($originale),
            'processed_blob_ids' => $verarbeitet,
            'pdf_blob_id' => $pdf,
            'status' => 'eingegangen',
            'ocr_status' => 'keine',
        ];
    }
}
