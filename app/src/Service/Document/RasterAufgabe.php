<?php

declare(strict_types=1);

namespace App\Service\Document;

/**
 * What App\Service\Document\PdfRasterung::naechste() found (issue #30/M4-8):
 * either a page to render, or nothing to do right now.
 */
final readonly class RasterAufgabe
{
    private function __construct(
        public RasterungStatus $status,
        public ?int $jobId,
        public ?string $lock,
        public ?int $quelleBlobId,
        public ?int $quelleIndex,
        public ?int $seite,
        public ?int $quellenGesamt,
    ) {
    }

    public static function leer(): self
    {
        return new self(RasterungStatus::Leer, null, null, null, null, null, null);
    }

    public static function aufgabe(
        int $jobId,
        string $lock,
        int $quelleBlobId,
        int $quelleIndex,
        int $seite,
        int $quellenGesamt,
    ): self {
        return new self(RasterungStatus::Aufgabe, $jobId, $lock, $quelleBlobId, $quelleIndex, $seite, $quellenGesamt);
    }
}
