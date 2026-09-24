<?php

declare(strict_types=1);

namespace App\Service\Document;

/**
 * What App\Service\Document\PdfRasterung::seiteSpeichern() did with one
 * uploaded page (issue #30/M4-8).
 *
 * `ok()` carries where to continue - `naechsteQuelle`/`naechsteSeite` - so
 * the browser never has to call naechste() again mid-job to learn about a
 * later source: that job's lock is still held (App\Repository\JobRepository
 * ::fortschritt() keeps it, unlike a session job's schrittErledigt()), so a
 * fresh claim attempt would find nothing to reclaim. The browser instead
 * just keeps driving the same job with what this answer told it, and only
 * asks naechste() again once this one answers `fertig`.
 */
final readonly class SeiteErgebnis
{
    private function __construct(
        public RasterungStatus $status,
        public ?int $naechsteQuelle = null,
        public ?int $naechsteSeite = null,
    ) {
    }

    public static function ok(int $naechsteQuelle, int $naechsteSeite): self
    {
        return new self(RasterungStatus::Ok, $naechsteQuelle, $naechsteSeite);
    }

    public static function fertig(): self
    {
        return new self(RasterungStatus::Fertig);
    }

    public static function unerwartet(): self
    {
        return new self(RasterungStatus::Unerwartet);
    }

    public static function verloren(): self
    {
        return new self(RasterungStatus::Verloren);
    }

    public static function ungueltigerTyp(): self
    {
        return new self(RasterungStatus::UngueltigerTyp);
    }

    public static function zuGross(): self
    {
        return new self(RasterungStatus::ZuGross);
    }
}
