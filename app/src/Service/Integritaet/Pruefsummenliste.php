<?php

declare(strict_types=1);

namespace App\Service\Integritaet;

/**
 * The checksums.txt of a release as the code integrity check reads it
 * (docs/spec/01-sicherheit.md section 8, issue #79/M13-2).
 *
 * One file, two kinds of lines, all in sha256sum format
 * ("<sha256>  <name>"):
 * - the release assets (`vereinsbelege-vX.Y.Z.zip`, `setup.php`) - what
 *   ReleaseDownloader::verifyChecksum() checks the download against;
 * - one line per file inside the release ZIP, its path prefixed with
 *   `release/` (written by .github/workflows/release.yml since M13-2).
 *
 * setup.php and the updater store the file as shared/release_checksums.txt.
 * Lines this class does not understand are skipped: a release before M13-2
 * simply has no file lines, and sha256sum escapes names with a backslash or
 * newline by a leading "\" - such a file then shows up as "additional".
 */
final readonly class Pruefsummenliste
{
    public const string PRAEFIX = 'release/';

    /**
     * @param array<string, string> $dateien path relative to the release root
     *        => lowercase sha256, sorted by path (byte order)
     * @param string|null $version the release version named by the ZIP line
     * @param string $fingerabdruck sha256 of the whole list, so a step chain
     *        notices when the list changed under it (an update in between)
     */
    private function __construct(
        public array $dateien,
        public ?string $version,
        public string $fingerabdruck,
    ) {
    }

    public static function aus(string $inhalt): self
    {
        $dateien = [];
        $version = null;
        foreach (preg_split('/\r\n|\n|\r/', $inhalt) ?: [] as $zeile) {
            if (preg_match('/^([0-9a-fA-F]{64}) [ *](.+)$/', $zeile, $m) !== 1) {
                continue;
            }
            $hash = strtolower($m[1]);
            $name = $m[2];

            if (str_starts_with($name, self::PRAEFIX)) {
                $pfad = substr($name, strlen(self::PRAEFIX));
                if (self::sichererPfad($pfad)) {
                    $dateien[$pfad] = $hash;
                }
            } elseif (preg_match('/^vereinsbelege-v(\d+\.\d+\.\d+[0-9A-Za-z.+-]*)\.zip$/', $name, $v) === 1) {
                $version = $v[1];
            }
        }
        ksort($dateien, SORT_STRING);

        return new self($dateien, $version, hash('sha256', $inhalt));
    }

    /**
     * A relative path that stays inside the release root: no absolute path,
     * no "." or ".." segment, no empty segment, no backslash, no NUL. The
     * list comes from the release, but it lies in shared/ - a tampered one
     * must not make the check read files outside current/.
     */
    public static function sichererPfad(string $pfad): bool
    {
        if ($pfad === '' || str_contains($pfad, "\0") || str_contains($pfad, '\\') || str_starts_with($pfad, '/')) {
            return false;
        }
        foreach (explode('/', $pfad) as $teil) {
            if ($teil === '' || $teil === '.' || $teil === '..') {
                return false;
            }
        }

        return true;
    }
}
