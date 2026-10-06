<?php

declare(strict_types=1);

namespace App\Service\Processing\Pdf;

/**
 * A stream object as found in the file: its dictionary and where its raw
 * (still encoded) bytes are. Only the offsets are kept - an image stream of
 * a scanned page is never copied, let alone decoded
 * (App\Service\Processing\Pdf\PdfDokument::daten() decodes on demand).
 */
final readonly class PdfStream
{
    /**
     * @param array<string, mixed> $dict
     */
    public function __construct(
        public array $dict,
        public int $start,
        public int $laenge,
    ) {
    }
}
