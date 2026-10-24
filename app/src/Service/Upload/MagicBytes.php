<?php

declare(strict_types=1);

namespace App\Service\Upload;

use App\Service\Processing\ERechnung\ERechnungLeser;

/**
 * What kind of file did we actually receive?
 *
 * The browser's `file.type` is a claim, not evidence - it comes from the
 * client and is trivial to set. The first bytes are the evidence, so they
 * decide alone (docs/spec/03-erfassung-und-ki.md section 4).
 *
 * Deliberately hand-written instead of ext/fileinfo: the host may not have
 * the extension, the magic database differs between installations, and three
 * signatures are less code than the detour (CLAUDE.md section 1 - no command
 * line tools, no native dependencies).
 *
 * Allowed are exactly the types the capture accepts: JPEG, PNG, PDF, HEIC
 * (section 1 of the same spec; HEIC is kept as the original - decision E-10 -
 * while the browser converts it to the JPEG that is worked with) and, since
 * issue #46/M7-4, the XML of an e-invoice (XRechnung). XML has no signature
 * of its own - any `<` would do - so for XML the root element is the
 * evidence: only an EN 16931 invoice or credit note (CII or UBL) passes
 * (App\Service\Processing\ERechnung\ERechnungLeser::wurzel()); HTML, SVG
 * and any other XML stay refused.
 */
final class MagicBytes
{
    public const string JPEG = 'image/jpeg';
    public const string PNG = 'image/png';
    public const string PDF = 'application/pdf';
    public const string HEIC = 'image/heic';
    public const string XML = 'application/xml';

    /**
     * Enough for the longest signature below - and for the root element of
     * an XML e-invoice behind its prolog, comments and namespace
     * declarations. Still only the start of a file that may be 32 MB.
     */
    public const int HEAD_BYTES = 64 * 1024;

    /**
     * The MIME type of the file starting with these bytes, or null when it is
     * none of the five. A short string is simply not a match - a file that
     * does not even have a signature's worth of bytes is not one of ours.
     */
    public static function detect(string $head): ?string
    {
        // JPEG: SOI plus the first marker byte. The fourth byte varies with
        // the flavour (JFIF, Exif, raw), so three bytes are the signature.
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return self::JPEG;
        }

        if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
            return self::PNG;
        }

        // No tolerance for leading junk: a PDF whose header sits at an offset
        // is broken for our reader too, and accepting a prefix would let a
        // file be two things at once.
        if (str_starts_with($head, '%PDF-')) {
            return self::PDF;
        }

        if (self::istHeic($head)) {
            return self::HEIC;
        }

        // An optional byte order mark and whitespace, then markup - and the
        // markup has to be an e-invoice.
        if (preg_match('/^(?:\xEF\xBB\xBF)?\s*</', $head) === 1 && ERechnungLeser::wurzel($head) !== null) {
            return self::XML;
        }

        return null;
    }

    /**
     * HEIC/HEIF: an ISO base media file whose `ftyp` box names an HEVC image
     * brand. `mif1`/`msf1` alone also cover AVIF and other codecs, so those
     * only count when a compatible brand says HEVC. The extension and the
     * MIME type are not looked at.
     */
    private static function istHeic(string $head): bool
    {
        if (strlen($head) < 16 || substr($head, 4, 4) !== 'ftyp') {
            return false;
        }

        $groesse = unpack('N', $head, 0)[1];
        $ende = min($groesse >= 16 ? $groesse : 16, strlen($head));
        $hauptmarke = substr($head, 8, 4);
        // Major brand, minor version (4 bytes), then the compatible brands.
        $marken = [$hauptmarke];
        for ($i = 16; $i + 4 <= $ende; $i += 4) {
            $marken[] = substr($head, $i, 4);
        }

        $hevc = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'hevm', 'hevs'];
        if (in_array($hauptmarke, $hevc, true)) {
            return true;
        }

        return in_array($hauptmarke, ['mif1', 'msf1'], true)
            && array_intersect($marken, $hevc) !== [];
    }
}
