<?php

declare(strict_types=1);

namespace App\Service\Export;

/**
 * Hands out the paths inside one export ZIP so no two files collide (issue
 * #75/M12-1, docs/spec/05-auswertung-und-export.md section 2): a second
 * "Bauhaus 17.05.2026.pdf" in the same folder becomes
 * "Bauhaus 17.05.2026 (2).pdf", a third " (3)", and so on.
 *
 * Names are compared without regard to case - Windows and macOS unpack
 * "Bauhaus" and "BAUHAUS" into the same folder. For the same reason a
 * folder keeps the spelling it was first handed out with.
 *
 * The numbering follows the order of the calls, so the export (M12-2) asks
 * in a fixed order (receipt date, then id) and gets the same names on
 * every run. One instance per ZIP; it holds only what this request already
 * has in memory.
 */
final class PfadVergabe
{
    /** @var array<string, true> lower-cased full paths already handed out */
    private array $vergeben = [];

    /** @var array<string, string> lower-cased folder path => first spelling */
    private array $ordner = [];

    /**
     * @param list<string> $segmente folders, last the file name without
     *        extension - what App\Service\Export\PfadMuster::aufloesen()
     *        returns, optionally behind fixed folders like "Belege_2026" or
     *        "_Originale"; every segment is cleaned again here
     * @param string $endung the file's extension, e.g. "pdf"
     */
    public function vergeben(array $segmente, string $endung): string
    {
        $datei = array_pop($segmente) ?? '';
        $ordner = $this->ordnerpfad($segmente);

        $endung = Dateiname::endung($endung);
        $punktEndung = $endung === '' ? '' : '.' . $endung;
        $stamm = Dateiname::segment($datei);
        if ($stamm === '') {
            $stamm = PfadMuster::ERSATZ_DATEINAME;
        }

        for ($nummer = 1; ; $nummer++) {
            $suffix = $nummer === 1 ? '' : sprintf(' (%d)', $nummer);
            // The suffix always fits: the stem gives way, never the counter.
            $name = Dateiname::segment($stamm, Dateiname::MAX_LAENGE - mb_strlen($suffix)) . $suffix . $punktEndung;
            $pfad = $ordner === '' ? $name : $ordner . '/' . $name;
            $schluessel = mb_strtolower($pfad);

            if (!isset($this->vergeben[$schluessel]) && !isset($this->ordner[$schluessel])) {
                $this->vergeben[$schluessel] = true;

                return $pfad;
            }
        }
    }

    /**
     * @param list<string> $segmente
     */
    private function ordnerpfad(array $segmente): string
    {
        $pfad = '';
        foreach ($segmente as $segment) {
            $segment = Dateiname::segment($segment);
            if ($segment === '') {
                continue;
            }

            $kandidat = $pfad === '' ? $segment : $pfad . '/' . $segment;
            $schluessel = mb_strtolower($kandidat);
            $this->ordner[$schluessel] ??= $kandidat;
            $pfad = $this->ordner[$schluessel];
        }

        return $pfad;
    }
}
