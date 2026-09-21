<?php

declare(strict_types=1);

namespace App\Service\Upload;

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
 * Allowed are exactly the three types the capture accepts: JPEG, PNG, PDF
 * (section 1 of the same spec; HEIC is converted in the browser).
 */
final class MagicBytes
{
    public const string JPEG = 'image/jpeg';
    public const string PNG = 'image/png';
    public const string PDF = 'application/pdf';

    /** Enough for the longest signature below. */
    public const int HEAD_BYTES = 16;

    /**
     * The MIME type of the file starting with these bytes, or null when it is
     * none of the three. A short string is simply not a match - a file that
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

        return null;
    }
}
