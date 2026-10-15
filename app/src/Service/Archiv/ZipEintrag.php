<?php

declare(strict_types=1);

namespace App\Service\Archiv;

/**
 * One entry of the ZIP's central directory (issue #77/M12-3), read by
 * App\Service\Archiv\ZipLeser::verzeichnis().
 *
 * `pfad` is already cleaned: UTF-8 in NFC, `/` as separator, no empty or `.`
 * levels. Business data (supplier names are in it) - it lives only in memory
 * and, encrypted, in the import report; never in a log or a plaintext column.
 *
 * `problem` says in German why the entry cannot be imported at all (a path
 * that climbs out with `..`, an encrypted entry, an unknown compression);
 * null for a readable one.
 */
final readonly class ZipEintrag
{
    public function __construct(
        public int $index,
        public string $pfad,
        public bool $ordner,
        public int $methode,
        public int $crc32,
        public int $gepackt,
        public int $groesse,
        public int $offset,
        public ?string $problem = null,
    ) {
    }

    /** The last level of the path - the file name with extension. */
    public function dateiname(): string
    {
        $teile = explode('/', $this->pfad);

        return (string) end($teile);
    }
}
