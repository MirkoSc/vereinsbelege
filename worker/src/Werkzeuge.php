<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * The command-line tools of the worker container (docs/spec/07-worker.md
 * sections 2 and 7): Poppler for page images, OCRmyPDF/Tesseract for text
 * and PDF/A. Every file they read or write lies in the job's tmpfs
 * directory (Arbeitsverzeichnis). Behind an interface so the job logic is
 * tested without the tools; the real ones are PosixWerkzeuge.
 *
 * $puls is called while a tool runs (stop request, lock extension) and may
 * throw - the tool is then terminated.
 */
interface Werkzeuge
{
    /** Poppler (pdfinfo, pdftoppm) is there. */
    public function kannRendern(): bool;

    /** OCRmyPDF and Tesseract are there. */
    public function kannOcr(): bool;

    /**
     * @param \Closure(): void $puls
     *
     * @throws JobFehler
     */
    public function seitenzahl(string $pdfDatei, \Closure $puls): int;

    /**
     * Renders page $seite (1-based) of $pdfDatei as JPEG to $zielDatei, at
     * $dpi or - with $laengsteSeite - scaled so the longer side has that
     * many pixels.
     *
     * @param \Closure(): void $puls
     *
     * @throws JobFehler
     */
    public function seiteRendern(string $pdfDatei, int $seite, int $dpi, ?int $laengsteSeite, int $qualitaet, string $zielDatei, \Closure $puls): void;

    /**
     * OCR of $eingabe (a PDF, or an image with $istBild): a PDF/A-2b with
     * text layer to $pdfaZiel and the recognised text to $textZiel, pages
     * separated by a form feed.
     *
     * @param \Closure(): void $puls
     *
     * @throws JobFehler
     */
    public function ocr(string $eingabe, bool $istBild, string $pdfaZiel, string $textZiel, \Closure $puls): void;

    /**
     * The text Tesseract reads in one page image.
     *
     * @param \Closure(): void $puls
     *
     * @throws JobFehler
     */
    public function bildText(string $bildDatei, \Closure $puls): string;
}
