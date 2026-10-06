<?php

declare(strict_types=1);

namespace App\Service\Processing\Pdf;

/**
 * One font resource, reduced to what reading its text needs (ISO 32000-1
 * section 9.10): how a shown string splits into codes, the Unicode text of
 * each code and its advance width.
 *
 * The Unicode text comes from the font's ToUnicode map where there is one
 * (every embedded subset font of a current generator writes one), else from
 * its encoding - for a simple font the base encoding with `/Differences`,
 * for a composite (Type0) font without ToUnicode there is no way to know,
 * and every code is U+FFFD.
 */
final class PdfSchrift
{
    /** Advance width when the font names none, in 1/1000 text space units. */
    private const float BREITE_STANDARD = 500.0;

    /** @var array<int, string> code → UTF-8 */
    private array $unicode = [];

    /** @var array<int, string> code → UTF-8 from the encoding, simple fonts only */
    private array $kodierung = [];

    /** @var array<int, float> code → width */
    private array $breiten = [];

    private float $standardBreite = self::BREITE_STANDARD;

    /**
     * Bytes per code: 1 for simple fonts; 2 for composite fonts, unless an
     * embedded encoding CMap says otherwise. Never taken from the ToUnicode
     * map: generators (Adobe's among them) write `<0000> <FFFF>` as its
     * codespace for simple fonts too.
     */
    private int $bytes = 1;

    private readonly bool $zusammengesetzt;

    /**
     * @param array<string, mixed> $dict
     */
    public function __construct(PdfDokument $dokument, array $dict)
    {
        $art = $dict['Subtype'] ?? null;
        $this->zusammengesetzt = $art instanceof PdfName && $art->wert === 'Type0';

        if ($this->zusammengesetzt) {
            $this->bytes = 2;
            $kodierung = $dokument->aufloesen($dict['Encoding'] ?? null);
            if ($kodierung instanceof PdfStream) {
                try {
                    $this->bytes = self::codelaenge($dokument->daten($kodierung)) ?? 2;
                } catch (PdfDefekt) {
                    // Identity it is.
                }
            }
            $nachfahren = $dokument->aufloesen($dict['DescendantFonts'] ?? null);
            $nachfahre = is_array($nachfahren) ? $dokument->aufloesen($nachfahren[0] ?? null) : null;
            if (is_array($nachfahre)) {
                $this->cidBreiten($dokument, $nachfahre);
            }
        } else {
            $this->einfacheBreiten($dokument, $dict);
            $this->einfacheKodierung($dokument, $dict);
        }

        $toUnicode = $dokument->aufloesen($dict['ToUnicode'] ?? null);
        if ($toUnicode instanceof PdfStream) {
            try {
                $this->cmap($dokument->daten($toUnicode));
            } catch (PdfDefekt) {
                // An unreadable map: the encoding (if any) has to do.
            }
        }
    }

    /**
     * The codes of a shown string with their text and width.
     *
     * @return list<array{string, float, bool}> [UTF-8, width in 1/1000 em, is the single-byte code 32 (word spacing applies)]
     */
    public function zerlegen(string $bytes): array
    {
        $ergebnis = [];
        $laenge = strlen($bytes);
        for ($i = 0; $i + $this->bytes <= $laenge; $i += $this->bytes) {
            $code = $this->bytes === 1 ? ord($bytes[$i]) : (ord($bytes[$i]) << 8) | ord($bytes[$i + 1]);
            $ergebnis[] = [
                $this->unicode[$code] ?? $this->kodierung[$code] ?? PdfKodierung::UNBEKANNT,
                $this->breiten[$code] ?? $this->standardBreite,
                $this->bytes === 1 && $code === 32,
            ];
        }

        return $ergebnis;
    }

    /**
     * @param array<string, mixed> $dict
     */
    private function einfacheBreiten(PdfDokument $dokument, array $dict): void
    {
        $erstes = $dokument->aufloesen($dict['FirstChar'] ?? null);
        $breiten = $dokument->aufloesen($dict['Widths'] ?? null);
        if (is_int($erstes) && is_array($breiten)) {
            foreach (array_values($breiten) as $i => $breite) {
                $breite = $dokument->aufloesen($breite);
                if (is_int($breite) || is_float($breite)) {
                    $this->breiten[$erstes + $i] = (float) $breite;
                }
            }
        }

        $beschreibung = $dokument->aufloesen($dict['FontDescriptor'] ?? null);
        $fehlend = is_array($beschreibung) ? $dokument->aufloesen($beschreibung['MissingWidth'] ?? null) : null;
        if ((is_int($fehlend) || is_float($fehlend)) && $fehlend > 0) {
            $this->standardBreite = (float) $fehlend;
        }
    }

    /**
     * `/W` of a CIDFont: `c [w1 w2 …]` or `c_first c_last w` (ISO 32000-1
     * section 9.7.4.3), `/DW` for everything else.
     *
     * @param array<string, mixed> $dict
     */
    private function cidBreiten(PdfDokument $dokument, array $dict): void
    {
        $standard = $dokument->aufloesen($dict['DW'] ?? null);
        $this->standardBreite = is_int($standard) || is_float($standard) ? (float) $standard : 1000.0;

        $w = $dokument->aufloesen($dict['W'] ?? null);
        if (!is_array($w)) {
            return;
        }
        $w = array_values($w);
        for ($i = 0, $n = count($w); $i < $n;) {
            $erstes = $dokument->aufloesen($w[$i]);
            $naechstes = $dokument->aufloesen($w[$i + 1] ?? null);
            if (!is_int($erstes)) {
                return;
            }
            if (is_array($naechstes)) {
                foreach (array_values($naechstes) as $j => $breite) {
                    if (is_int($breite) || is_float($breite)) {
                        $this->breiten[$erstes + $j] = (float) $breite;
                    }
                }
                $i += 2;
                continue;
            }
            $breite = $dokument->aufloesen($w[$i + 2] ?? null);
            if (!is_int($naechstes) || !(is_int($breite) || is_float($breite)) || $naechstes - $erstes > 65535) {
                return;
            }
            for ($code = $erstes; $code <= $naechstes; $code++) {
                $this->breiten[$code] = (float) $breite;
            }
            $i += 3;
        }
    }

    /**
     * @param array<string, mixed> $dict
     */
    private function einfacheKodierung(PdfDokument $dokument, array $dict): void
    {
        $kodierung = $dokument->aufloesen($dict['Encoding'] ?? null);
        $basis = 'WinAnsiEncoding';
        $unterschiede = [];

        if ($kodierung instanceof PdfName) {
            $basis = $kodierung->wert;
        } elseif (is_array($kodierung)) {
            $basisName = $dokument->aufloesen($kodierung['BaseEncoding'] ?? null);
            if ($basisName instanceof PdfName) {
                $basis = $basisName->wert;
            }
            $liste = $dokument->aufloesen($kodierung['Differences'] ?? null);
            $unterschiede = is_array($liste) ? array_values($liste) : [];
        }

        $this->kodierung = PdfKodierung::basis($basis);

        // `/Differences [code /name /name code /name …]`: each name takes the
        // next code after the one before.
        $code = 0;
        foreach ($unterschiede as $eintrag) {
            if (is_int($eintrag)) {
                $code = $eintrag;
            } elseif ($eintrag instanceof PdfName) {
                if ($code >= 0 && $code < 256) {
                    $this->kodierung[$code] = PdfKodierung::glyph($eintrag->wert);
                }
                $code++;
            }
        }
    }

    /**
     * Reads a ToUnicode CMap (Adobe technical note 5411): the codespace
     * (for the code length), `bfchar` and `bfrange` entries. Destinations are
     * UTF-16BE.
     */
    private function cmap(string $daten): void
    {
        preg_match_all('/beginbfchar(.*?)endbfchar/s', $daten, $bloecke);
        foreach ($bloecke[1] as $block) {
            preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]*)>/', $block, $paare, PREG_SET_ORDER);
            foreach ($paare as [, $quelle, $ziel]) {
                $this->unicode[(int) hexdec($quelle)] = self::utf16($ziel);
            }
        }

        preg_match_all('/beginbfrange(.*?)endbfrange/s', $daten, $bloecke);
        foreach ($bloecke[1] as $block) {
            preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(?:<([0-9A-Fa-f]*)>|\[([^\]]*)\])/', $block, $bereiche, PREG_SET_ORDER);
            foreach ($bereiche as $bereich) {
                $von = (int) hexdec($bereich[1]);
                $bis = (int) hexdec($bereich[2]);
                if ($bis < $von || $bis - $von > 65535) {
                    continue;
                }
                if (isset($bereich[4]) && $bereich[4] !== '') {
                    preg_match_all('/<([0-9A-Fa-f]*)>/', $bereich[4], $ziele);
                    foreach ($ziele[1] as $i => $ziel) {
                        if ($von + $i <= $bis) {
                            $this->unicode[$von + $i] = self::utf16($ziel);
                        }
                    }
                    continue;
                }
                // `<lo> <hi> <dst>`: the last byte of dst counts up.
                $ziel = $bereich[3];
                if (strlen($ziel) < 4 || strlen($ziel) % 4 !== 0) {
                    continue;
                }
                $kopf = substr($ziel, 0, -4);
                $letztes = (int) hexdec(substr($ziel, -4));
                for ($code = $von; $code <= $bis; $code++) {
                    $this->unicode[$code] = self::utf16($kopf . sprintf('%04X', ($letztes + $code - $von) & 0xFFFF));
                }
            }
        }
    }

    /** The code length of a CMap's (first) codespace range, in bytes. */
    private static function codelaenge(string $cmap): ?int
    {
        if (preg_match('/begincodespacerange\s*<([0-9A-Fa-f]+)>/', $cmap, $treffer) !== 1) {
            return null;
        }

        return max(1, min(4, intdiv(strlen($treffer[1]) + 1, 2)));
    }

    private static function utf16(string $hex): string
    {
        if ($hex === '' || strlen($hex) % 4 !== 0) {
            return PdfKodierung::UNBEKANNT;
        }
        $text = mb_convert_encoding((string) hex2bin($hex), 'UTF-8', 'UTF-16BE');
        if (!mb_check_encoding($text, 'UTF-8')) {
            return PdfKodierung::UNBEKANNT;
        }

        // Control characters (a map pointing at U+0000 for "no text")
        // contribute nothing readable.
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
    }
}
