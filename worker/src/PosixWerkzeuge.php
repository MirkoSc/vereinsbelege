<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * Werkzeuge with the real programs of the worker image (#55): pdfinfo and
 * pdftoppm (Poppler), ocrmypdf (with Tesseract `deu`+`eng`, Ghostscript,
 * unpaper), tesseract. Started only through App\Worker\Prozess - argument
 * lists, no shell. Inputs and outputs are files in the job's tmpfs
 * directory; the tools' own temporary files go there too (TMPDIR).
 * Exit codes are mapped to error codes; the tools' output is never passed
 * on (it can contain text of the receipt).
 */
final readonly class PosixWerkzeuge implements Werkzeuge
{
    private const string SPRACHEN = 'deu+eng';

    public function kannRendern(): bool
    {
        return Prozess::finde('pdftoppm') !== null && Prozess::finde('pdfinfo') !== null;
    }

    public function kannOcr(): bool
    {
        return Prozess::finde('ocrmypdf') !== null && Prozess::finde('tesseract') !== null;
    }

    public function seitenzahl(string $pdfDatei, \Closure $puls): int
    {
        $ergebnis = $this->starten(['pdfinfo', $pdfDatei], dirname($pdfDatei), 120, $puls, 'PdfInfoFehlgeschlagen');
        if (!$ergebnis->ok()) {
            // 1: cannot open, 3: encrypted - the file itself is the problem.
            throw new JobFehler($ergebnis->exitCode === 3 ? 'PdfPasswortGeschuetzt' : 'PdfRasterungFehlgeschlagen', in_array($ergebnis->exitCode, [1, 3], true));
        }
        if (preg_match('/^Pages:\s+(\d+)\s*$/m', $ergebnis->stdout, $treffer) !== 1) {
            throw new JobFehler('PdfRasterungFehlgeschlagen', true);
        }

        return (int) $treffer[1];
    }

    public function seiteRendern(string $pdfDatei, int $seite, int $dpi, ?int $laengsteSeite, int $qualitaet, string $zielDatei, \Closure $puls): void
    {
        $praefix = (string) preg_replace('/\.jpg$/', '', $zielDatei);
        $befehl = ['pdftoppm', '-f', (string) $seite, '-l', (string) $seite, '-singlefile', '-jpeg', '-jpegopt', 'quality=' . $qualitaet];
        array_push($befehl, ...($laengsteSeite === null ? ['-r', (string) $dpi] : ['-scale-to', (string) $laengsteSeite]));
        array_push($befehl, $pdfDatei, $praefix);

        $ergebnis = $this->starten($befehl, dirname($zielDatei), 600, $puls, 'PdfRasterungFehlgeschlagen');
        if (!$ergebnis->ok() || !is_file($praefix . '.jpg')) {
            throw new JobFehler($ergebnis->exitCode === 3 ? 'PdfPasswortGeschuetzt' : 'PdfRasterungFehlgeschlagen', in_array($ergebnis->exitCode, [1, 3], true));
        }
        if ($praefix . '.jpg' !== $zielDatei) {
            rename($praefix . '.jpg', $zielDatei);
        }
    }

    public function ocr(string $eingabe, bool $istBild, string $pdfaZiel, string $textZiel, \Closure $puls): void
    {
        $befehl = [
            'ocrmypdf',
            '--quiet',
            '--language', self::SPRACHEN,
            '--output-type', 'pdfa-2',
            '--rotate-pages',
            '--deskew',
            '--sidecar', $textZiel,
            // A text layer that was judged unusable is replaced, not kept.
            '--force-ocr',
        ];
        if ($istBild) {
            // Photos rarely carry a usable resolution; ocrmypdf needs one.
            array_push($befehl, '--image-dpi', '300');
        }
        array_push($befehl, $eingabe, $pdfaZiel);

        $ergebnis = $this->starten($befehl, dirname($pdfaZiel), 3600, $puls, 'OcrFehlgeschlagen');
        if (!$ergebnis->ok() || !is_file($pdfaZiel) || !is_file($textZiel)) {
            // ocrmypdf: 2 bad input file, 8 encrypted PDF - final; the rest
            // (missing dependency, PDF/A conversion, child process) may pass.
            throw new JobFehler(match ($ergebnis->exitCode) {
                8 => 'PdfPasswortGeschuetzt',
                2 => 'OcrEingabeUngueltig',
                default => 'OcrFehlgeschlagen',
            }, in_array($ergebnis->exitCode, [2, 8], true));
        }
    }

    public function bildText(string $bildDatei, \Closure $puls): string
    {
        $ergebnis = $this->starten(['tesseract', $bildDatei, 'stdout', '-l', self::SPRACHEN], dirname($bildDatei), 600, $puls, 'OcrFehlgeschlagen');
        if (!$ergebnis->ok()) {
            throw new JobFehler('OcrFehlgeschlagen', false);
        }

        return $ergebnis->stdout;
    }

    /**
     * @param non-empty-list<string> $befehl
     */
    private function starten(array $befehl, string $verzeichnis, int $zeitlimit, \Closure $puls, string $fehlercode): ProzessErgebnis
    {
        try {
            return Prozess::ausfuehren($befehl, $verzeichnis, $zeitlimit, $puls);
        } catch (ProzessFehler) {
            throw new JobFehler($fehlercode, false);
        }
    }
}
