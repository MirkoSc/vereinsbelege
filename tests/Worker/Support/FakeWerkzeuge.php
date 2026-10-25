<?php

declare(strict_types=1);

namespace App\Tests\Worker\Support;

use App\Tests\Support\FakeJpeg;
use App\Worker\WerkzeugFehler;
use App\Worker\Werkzeuge;

/**
 * Poppler and OCRmyPDF without the programs: page images are JPEGs with
 * real markers (FakeJpeg), the PDF/A is a stub, the text a fixed invoice
 * text. Every file goes where the real tools would write it, so the job
 * logic around them - tmpfs files, limits, uploads - runs as in the
 * container. $danach runs after each call (e.g. a stop request), before
 * the puls the real tools would give.
 */
final class FakeWerkzeuge implements Werkzeuge
{
    public const string TEXT = "Rechnung Nr. 2026-0815\nSportartikel Müller GmbH, Hauptstraße 1, 12345 Musterstadt\n"
        . "Leistung: Trainingsbälle und Hütchen für die Jugendabteilung des Vereins, geliefert am zwölften Oktober\n"
        . "Nettobetrag hundert Euro, Umsatzsteuer neunzehn Prozent, Gesamtbetrag einhundertneunzehn Euro, zahlbar innerhalb vierzehn Tagen";

    public bool $rendern = true;

    public bool $ocrDa = true;

    public int $seiten = 2;

    public int $breite = 1240;

    public int $hoehe = 1754;

    public string $text = self::TEXT;

    public ?WerkzeugFehler $fehler = null;

    /** @var list<string> */
    public array $aufrufe = [];

    /** @var list<string> every file the tools were handed */
    public array $dateien = [];

    /** @var ?\Closure(): void */
    public ?\Closure $danach = null;

    public function kannRendern(): bool
    {
        return $this->rendern;
    }

    public function kannOcr(): bool
    {
        return $this->ocrDa;
    }

    public function seitenzahl(string $pdfDatei, \Closure $puls): int
    {
        $this->aufruf('seitenzahl', $pdfDatei, $puls);

        return $this->seiten;
    }

    public function seiteRendern(string $pdfDatei, int $seite, int $dpi, ?int $laengsteSeite, int $qualitaet, string $zielDatei, \Closure $puls): void
    {
        $this->aufruf('seite:' . $seite . ':' . $dpi . ':' . ($laengsteSeite ?? '-') . ':' . $qualitaet, $pdfDatei, $puls);
        [$breite, $hoehe] = $laengsteSeite === null
            ? [$this->breite, $this->hoehe]
            : [(int) round($this->breite * $laengsteSeite / max($this->breite, $this->hoehe)), (int) round($this->hoehe * $laengsteSeite / max($this->breite, $this->hoehe))];
        file_put_contents($zielDatei, FakeJpeg::bauen($breite, $hoehe));
    }

    public function ocr(string $eingabe, bool $istBild, string $pdfaZiel, string $textZiel, \Closure $puls): void
    {
        $this->aufruf('ocr:' . ($istBild ? 'bild' : 'pdf'), $eingabe, $puls);
        file_put_contents($pdfaZiel, "%PDF-1.7\n% PDF/A-2b " . basename($eingabe) . "\n%%EOF\n");
        file_put_contents($textZiel, $this->text . "\f");
    }

    public function bildText(string $bildDatei, \Closure $puls): string
    {
        $this->aufruf('bildtext', $bildDatei, $puls);

        return $this->text;
    }

    private function aufruf(string $was, string $datei, \Closure $puls): void
    {
        $this->aufrufe[] = $was;
        $this->dateien[] = $datei;
        if (!is_file($datei)) {
            throw new \LogicException('The tool got no input file: ' . $datei);
        }
        if ($this->fehler !== null) {
            throw $this->fehler;
        }
        if ($this->danach !== null) {
            ($this->danach)();
        }
        $puls();
    }
}
