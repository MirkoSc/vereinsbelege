<?php

declare(strict_types=1);

namespace App\Service\Upload;

/**
 * Why an upload was refused.
 *
 * The service itself knows nothing about HTTP, but every one of these cases
 * has exactly one sensible status code and one sensible sentence for the
 * person in front of the browser - so both live here, next to the case, and
 * the controller only maps them out.
 *
 * The messages name no file, no size and no upload id: an error message is a
 * place where business data must not appear (CLAUDE.md section 4).
 */
enum UploadError: string
{
    case UnknownUpload = 'unbekannt';
    case EmptyFile = 'leer';
    case TooLarge = 'zu_gross';
    case ChunkOutOfRange = 'chunk_ausserhalb';
    case ChunkTooLarge = 'chunk_zu_gross';
    case Incomplete = 'unvollstaendig';
    case UnsupportedType = 'typ_nicht_erlaubt';
    case VaultMissing = 'kein_tresor';

    public function status(): int
    {
        return match ($this) {
            self::UnknownUpload => 404,
            // 413 for both size cases: the request is the thing that is too
            // big, whether it is the announced file or a single chunk.
            self::TooLarge, self::ChunkTooLarge => 413,
            self::EmptyFile, self::ChunkOutOfRange => 422,
            self::Incomplete => 409,
            self::UnsupportedType => 415,
            self::VaultMissing => 503,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::UnknownUpload => 'Der Upload ist abgelaufen – bitte die Datei erneut auswählen.',
            self::EmptyFile => 'Die Datei ist leer.',
            self::TooLarge => 'Die Datei ist zu groß.',
            self::ChunkOutOfRange => 'Ungültiger Abschnitt.',
            self::ChunkTooLarge => 'Der Abschnitt ist zu groß.',
            self::Incomplete => 'Der Upload ist unvollständig – bitte erneut versuchen.',
            self::UnsupportedType => 'Nur JPEG, PNG und PDF sind möglich.',
            self::VaultMissing => 'Der Tresor ist noch nicht eingerichtet.',
        };
    }
}
