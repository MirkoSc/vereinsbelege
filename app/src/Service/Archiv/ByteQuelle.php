<?php

declare(strict_types=1);

namespace App\Service\Archiv;

/**
 * Random read access to the bytes of a ZIP (issue #77/M12-3) - the upload
 * chunks of the archive import (App\Service\Archiv\UploadQuelle) or, in the
 * tests, a string. App\Service\Archiv\ZipLeser needs nothing else: it never
 * wants the file as one piece.
 */
interface ByteQuelle
{
    /** Size of the whole file in bytes. */
    public function laenge(): int;

    /**
     * Up to $laenge bytes from $offset on - fewer only at the end of the file.
     *
     * @throws UngueltigesZip when the bytes are gone (an upload the cron
     *                        cleaned up meanwhile)
     */
    public function lesen(int $offset, int $laenge): string;
}
