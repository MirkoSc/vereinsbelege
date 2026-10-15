<?php

declare(strict_types=1);

namespace App\Service\Archiv;

use App\Service\Upload\UploadException;
use App\Service\Upload\UploadService;

/**
 * The uploaded ZIP of the archive import, read in place from its chunks in
 * `shared/var/tmp/upload/<id>/` (issue #77/M12-3) - App\Service\Archiv\
 * ZipLeser seeks around in it, nothing is joined or copied.
 */
final readonly class UploadQuelle implements ByteQuelle
{
    private int $laenge;

    /**
     * @throws UngueltigesZip when the upload is gone
     */
    public function __construct(private UploadService $uploads, private string $uploadId)
    {
        try {
            $this->laenge = $uploads->groesse($uploadId);
        } catch (UploadException) {
            throw new UngueltigesZip(ArchivImport::UPLOAD_WEG);
        }
    }

    public function laenge(): int
    {
        return $this->laenge;
    }

    public function lesen(int $offset, int $laenge): string
    {
        try {
            return $this->uploads->lesen($this->uploadId, $offset, $laenge);
        } catch (UploadException) {
            throw new UngueltigesZip(ArchivImport::UPLOAD_WEG);
        }
    }
}
