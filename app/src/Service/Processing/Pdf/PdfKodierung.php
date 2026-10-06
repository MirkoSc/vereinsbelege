<?php

declare(strict_types=1);

namespace App\Service\Processing\Pdf;

/**
 * Code → Unicode for simple fonts without a ToUnicode map (ISO 32000-1
 * section 9.6.6 and annex D): the base encodings and the glyph names of
 * `/Differences`. Covers what German (and Western European) invoices
 * use - Latin-1, the Windows-1252 extras (€, typographic quotes, dashes,
 * bullet, ellipsis), the common ligatures and the `uniXXXX`/`uXXXX` naming
 * convention of the Adobe Glyph List. A name outside of that is unknown
 * (U+FFFD), which the usability heuristic counts against the text
 * (App\Service\Processing\Textlayer).
 */
final class PdfKodierung
{
    public const string UNBEKANNT = "\u{FFFD}";

    /** Windows-1252 0x80-0x9F (PDF's WinAnsiEncoding); the gaps are unassigned. */
    private const array WIN_ANSI_80 = [
        0x80 => 0x20AC, 0x82 => 0x201A, 0x83 => 0x0192, 0x84 => 0x201E, 0x85 => 0x2026, 0x86 => 0x2020,
        0x87 => 0x2021, 0x88 => 0x02C6, 0x89 => 0x2030, 0x8A => 0x0160, 0x8B => 0x2039, 0x8C => 0x0152,
        0x8E => 0x017D, 0x91 => 0x2018, 0x92 => 0x2019, 0x93 => 0x201C, 0x94 => 0x201D, 0x95 => 0x2022,
        0x96 => 0x2013, 0x97 => 0x2014, 0x98 => 0x02DC, 0x99 => 0x2122, 0x9A => 0x0161, 0x9B => 0x203A,
        0x9C => 0x0153, 0x9E => 0x017E, 0x9F => 0x0178,
    ];

    /** MacRomanEncoding 0x80-0xFF. */
    private const array MAC_ROMAN_80 = [
        0x00C4, 0x00C5, 0x00C7, 0x00C9, 0x00D1, 0x00D6, 0x00DC, 0x00E1, 0x00E0, 0x00E2, 0x00E4, 0x00E3, 0x00E5, 0x00E7, 0x00E9, 0x00E8,
        0x00EA, 0x00EB, 0x00ED, 0x00EC, 0x00EE, 0x00EF, 0x00F1, 0x00F3, 0x00F2, 0x00F4, 0x00F6, 0x00F5, 0x00FA, 0x00F9, 0x00FB, 0x00FC,
        0x2020, 0x00B0, 0x00A2, 0x00A3, 0x00A7, 0x2022, 0x00B6, 0x00DF, 0x00AE, 0x00A9, 0x2122, 0x00B4, 0x00A8, 0x2260, 0x00C6, 0x00D8,
        0x221E, 0x00B1, 0x2264, 0x2265, 0x00A5, 0x00B5, 0x2202, 0x2211, 0x220F, 0x03C0, 0x222B, 0x00AA, 0x00BA, 0x03A9, 0x00E6, 0x00F8,
        0x00BF, 0x00A1, 0x00AC, 0x221A, 0x0192, 0x2248, 0x2206, 0x00AB, 0x00BB, 0x2026, 0x00A0, 0x00C0, 0x00C3, 0x00D5, 0x0152, 0x0153,
        0x2013, 0x2014, 0x201C, 0x201D, 0x2018, 0x2019, 0x00F7, 0x25CA, 0x00FF, 0x0178, 0x2044, 0x20AC, 0x2039, 0x203A, 0xFB01, 0xFB02,
        0x2021, 0x00B7, 0x201A, 0x201E, 0x2030, 0x00C2, 0x00CA, 0x00C1, 0x00CB, 0x00C8, 0x00CD, 0x00CE, 0x00CF, 0x00CC, 0x00D3, 0x00D4,
        0xF8FF, 0x00D2, 0x00DA, 0x00DB, 0x00D9, 0x0131, 0x02C6, 0x02DC, 0x00AF, 0x02D8, 0x02D9, 0x02DA, 0x00B8, 0x02DD, 0x02DB, 0x02C7,
    ];

    /** Glyph names of U+0020-U+007E in order (Adobe Glyph List). */
    private const string NAMEN_ASCII = 'space exclam quotedbl numbersign dollar percent ampersand quotesingle parenleft parenright asterisk plus comma hyphen period slash zero one two three four five six seven eight nine colon semicolon less equal greater question at A B C D E F G H I J K L M N O P Q R S T U V W X Y Z bracketleft backslash bracketright asciicircum underscore grave a b c d e f g h i j k l m n o p q r s t u v w x y z braceleft bar braceright asciitilde';

    /** Glyph names of U+00A0-U+00FF in order. */
    private const string NAMEN_LATIN1 = 'nbspace exclamdown cent sterling currency yen brokenbar section dieresis copyright ordfeminine guillemotleft logicalnot softhyphen registered macron degree plusminus twosuperior threesuperior acute mu paragraph periodcentered cedilla onesuperior ordmasculine guillemotright onequarter onehalf threequarters questiondown Agrave Aacute Acircumflex Atilde Adieresis Aring AE Ccedilla Egrave Eacute Ecircumflex Edieresis Igrave Iacute Icircumflex Idieresis Eth Ntilde Ograve Oacute Ocircumflex Otilde Odieresis multiply Oslash Ugrave Uacute Ucircumflex Udieresis Yacute Thorn germandbls agrave aacute acircumflex atilde adieresis aring ae ccedilla egrave eacute ecircumflex edieresis igrave iacute icircumflex idieresis eth ntilde ograve oacute ocircumflex otilde odieresis divide oslash ugrave uacute ucircumflex udieresis yacute thorn ydieresis';

    /** Further names German invoices use, beyond the two ranges above. */
    private const array NAMEN_WEITERE = [
        'Euro' => "\u{20AC}", 'quoteleft' => "\u{2018}", 'quoteright' => "\u{2019}", 'quotesinglbase' => "\u{201A}",
        'quotedblleft' => "\u{201C}", 'quotedblright' => "\u{201D}", 'quotedblbase' => "\u{201E}",
        'endash' => "\u{2013}", 'emdash' => "\u{2014}", 'bullet' => "\u{2022}", 'ellipsis' => "\u{2026}",
        'dagger' => "\u{2020}", 'daggerdbl' => "\u{2021}", 'perthousand' => "\u{2030}", 'trademark' => "\u{2122}",
        'florin' => "\u{0192}", 'circumflex' => "\u{02C6}", 'tilde' => "\u{02DC}", 'guilsinglleft' => "\u{2039}",
        'guilsinglright' => "\u{203A}", 'Scaron' => "\u{0160}", 'scaron' => "\u{0161}", 'Zcaron' => "\u{017D}",
        'zcaron' => "\u{017E}", 'OE' => "\u{0152}", 'oe' => "\u{0153}", 'Ydieresis' => "\u{0178}", 'dotlessi' => "\u{0131}",
        'Lslash' => "\u{0141}", 'lslash' => "\u{0142}", 'minus' => "\u{2212}", 'fraction' => "\u{2044}",
        'fi' => 'fi', 'fl' => 'fl', 'ff' => 'ff', 'ffi' => 'ffi', 'ffl' => 'ffl',
        'space' => ' ', 'uni00A0' => "\u{00A0}", 'hyphen' => '-', 'sfthyphen' => "\u{00AD}",
        'quotesingle' => "'", 'grave' => '`', 'mu1' => "\u{00B5}", 'middot' => "\u{00B7}",
    ];

    /** @var array<string, string>|null */
    private static ?array $namen = null;

    /**
     * The 256 codes of a base encoding, as UTF-8 (U+FFFD where unassigned).
     *
     * @return array<int, string>
     */
    public static function basis(string $name): array
    {
        $tabelle = [];
        for ($code = 0; $code < 256; $code++) {
            $tabelle[$code] = match ($name) {
                'MacRomanEncoding' => $code < 0x80 ? self::ascii($code) : mb_chr(self::MAC_ROMAN_80[$code - 0x80], 'UTF-8'),
                'StandardEncoding' => match ($code) {
                    0x27 => "\u{2019}",
                    0x60 => "\u{2018}",
                    default => $code < 0x80 ? self::ascii($code) : self::UNBEKANNT,
                },
                default => self::winAnsi($code),
            };
        }

        return $tabelle;
    }

    /** The Unicode text of a glyph name (`adieresis`, `Euro`, `uni00E4`, `f_i`, `a.sc`), U+FFFD if unknown. */
    public static function glyph(string $name): string
    {
        $namen = self::namen();
        if (isset($namen[$name])) {
            return $namen[$name];
        }

        // `a.sc`, `one.oldstyle`: the part before the first period counts.
        $basis = explode('.', $name, 2)[0];
        if ($basis !== $name && $basis !== '') {
            return self::glyph($basis);
        }
        // Ligatures named by their components: `f_f_i`.
        if (str_contains($name, '_')) {
            return implode('', array_map(self::glyph(...), explode('_', $name)));
        }
        if (preg_match('/^uni((?:[0-9A-F]{4})+)$/', $name, $treffer) === 1) {
            $text = '';
            foreach (str_split($treffer[1], 4) as $hex) {
                $text .= self::codepoint((int) hexdec($hex));
            }

            return $text;
        }
        if (preg_match('/^u([0-9A-F]{4,6})$/', $name, $treffer) === 1) {
            return self::codepoint((int) hexdec($treffer[1]));
        }

        return self::UNBEKANNT;
    }

    /** UTF-8 of a code point; U+FFFD for surrogates, controls and anything out of range. */
    public static function codepoint(int $codepoint): string
    {
        if ($codepoint < 0x20 && !in_array($codepoint, [0x09, 0x0A, 0x0D], true)) {
            return self::UNBEKANNT;
        }
        if ($codepoint > 0x10FFFF || ($codepoint >= 0xD800 && $codepoint <= 0xDFFF)) {
            return self::UNBEKANNT;
        }

        return (string) mb_chr($codepoint, 'UTF-8');
    }

    private static function winAnsi(int $code): string
    {
        return match (true) {
            $code < 0x80 => self::ascii($code),
            $code < 0xA0 => isset(self::WIN_ANSI_80[$code]) ? mb_chr(self::WIN_ANSI_80[$code], 'UTF-8') : self::UNBEKANNT,
            default => mb_chr($code, 'UTF-8'),
        };
    }

    private static function ascii(int $code): string
    {
        return $code >= 0x20 && $code < 0x7F ? chr($code) : self::UNBEKANNT;
    }

    /**
     * @return array<string, string>
     */
    private static function namen(): array
    {
        if (self::$namen !== null) {
            return self::$namen;
        }

        $namen = [];
        foreach (explode(' ', self::NAMEN_ASCII) as $i => $name) {
            $namen[$name] = chr(0x20 + $i);
        }
        foreach (explode(' ', self::NAMEN_LATIN1) as $i => $name) {
            $namen[$name] = (string) mb_chr(0xA0 + $i, 'UTF-8');
        }

        return self::$namen = [...$namen, ...self::NAMEN_WEITERE];
    }
}
