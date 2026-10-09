<?php

declare(strict_types=1);

namespace App\Service\Processing\ERechnung;

use App\Service\Processing\Pdf\PdfDefekt;
use App\Service\Processing\Pdf\PdfDokument;
use App\Service\Processing\Pdf\PdfStream;
use App\Service\Processing\Pdf\PdfTextlayer;
use App\Service\Processing\Pdf\PdfZuGross;

/**
 * Finds the invoice XML a ZUGFeRD/Factur-X PDF carries as an embedded file
 * (docs/spec/03-erfassung-und-ki.md section 3, issue #46/M7-4) and reads it
 * with ERechnungLeser. The PDF structure comes from the text layer's own
 * reader (App\Service\Processing\Pdf\PdfDokument, issue #45/M7-3), within
 * the same decoding budget - no third-party PDF library.
 *
 * Only embedded files whose name ends in ".xml" are looked at, the names
 * the standards fix first: an invoice PDF may carry other attachments (a
 * delivery note, a time sheet) that are no business of this reader. The
 * first invoice XML decides - read, damaged or of an unsupported version.
 *
 * Like PdfTextlayer it never throws for what is in the file: a damaged or
 * encrypted PDF simply has no e-invoice (`Keine`) and goes the way of any
 * other PDF.
 */
final class ERechnungPdf
{
    /** Embedded files looked at, at most. */
    public const int MAX_ANHAENGE = 32;

    /**
     * ZUGFeRD 2.x/Factur-X (`factur-x.xml`, `zugferd-invoice.xml`), the
     * ZUGFeRD 2.0 spelling, XRechnung embedded the ZUGFeRD way.
     */
    private const array BEKANNTE_NAMEN = ['factur-x.xml', 'zugferd-invoice.xml', 'xrechnung.xml'];

    public static function lesen(string $pdf): ERechnungErgebnis
    {
        try {
            $dokument = new PdfDokument($pdf, PdfTextlayer::MAX_DEKODIERT);
            if ($dokument->verschluesselt()) {
                return ERechnungErgebnis::ohne(ERechnungBefund::Keine);
            }

            foreach (self::kandidaten($dokument->eingebetteteDateien(self::MAX_ANHAENGE)) as $stream) {
                $ergebnis = ERechnungLeser::lesen($dokument->daten($stream));
                if ($ergebnis->befund !== ERechnungBefund::Keine) {
                    return $ergebnis;
                }
            }

            return ERechnungErgebnis::ohne(ERechnungBefund::Keine);
        } catch (PdfZuGross) {
            return ERechnungErgebnis::ohne(ERechnungBefund::ZuGross);
        } catch (PdfDefekt | \TypeError | \ValueError | \ArithmeticError) {
            return ERechnungErgebnis::ohne(ERechnungBefund::Keine);
        }
    }

    /**
     * The XML attachments, the standard names first.
     *
     * @param list<array{string, PdfStream}> $dateien
     *
     * @return list<PdfStream>
     */
    private static function kandidaten(array $dateien): array
    {
        $bekannt = [];
        $andere = [];
        foreach ($dateien as [$name, $stream]) {
            $name = strtolower($name);
            if (in_array($name, self::BEKANNTE_NAMEN, true)) {
                $bekannt[] = $stream;
            } elseif (str_ends_with($name, '.xml')) {
                $andere[] = $stream;
            }
        }

        return [...$bekannt, ...$andere];
    }
}
