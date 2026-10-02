<?php

declare(strict_types=1);

namespace App\Service\Processing;

use App\Service\Crypto\BlindIndex;

/**
 * The content index of a document (issue #40/M6-6, `document.content_bi`,
 * docs/spec/02-datenmodell.md "Statusmodell", `duplikat_verdacht`): equal
 * exactly when the original files are byte for byte the same, in the same
 * page order - the same photo or PDF submitted twice.
 *
 * Two steps so a caller can spread a document over several short requests
 * (App\Service\Document\Duplikatindex, one original per request):
 *   datei() - SHA-256 over one file's plaintext, streamed, then a blind
 *             index of it (purpose `document.content_file`);
 *   beleg() - a blind index (purpose `document.content`) over the file
 *             indexes in page order.
 * A plain SHA-256 is never the result and never stored: anyone holding a
 * candidate file could confirm it against the database. Behind the blind
 * index key (Vault::blindIndex()) only an unlocked session can compute or
 * compare a value (CLAUDE.md section 5).
 *
 * Framework-free like everything in App\Service\Processing (CLAUDE.md
 * section 6a).
 */
final class Inhaltsindex
{
    public const string ZWECK_DATEI = 'document.content_file';

    public const string ZWECK_BELEG = 'document.content';

    /**
     * @param iterable<string> $stuecke one file's plaintext, in pieces
     *
     * @return string BlindIndex::BYTES raw bytes
     */
    public static function datei(BlindIndex $index, iterable $stuecke): string
    {
        $hash = hash_init('sha256');
        foreach ($stuecke as $stueck) {
            hash_update($hash, $stueck);
        }

        return $index->forValue(self::ZWECK_DATEI, hash_final($hash));
    }

    /**
     * @param non-empty-list<string> $dateien datei() of every original, in
     *        page order
     *
     * @return string BlindIndex::BYTES raw bytes, for `document.content_bi`
     */
    public static function beleg(BlindIndex $index, array $dateien): string
    {
        foreach ($dateien as $datei) {
            if (strlen($datei) !== BlindIndex::BYTES) {
                throw new ProcessingException('Ungültiger Dateiindex.');
            }
        }

        return $index->forValue(self::ZWECK_BELEG, implode(' ', array_map(bin2hex(...), $dateien)));
    }
}
