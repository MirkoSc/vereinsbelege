<?php

declare(strict_types=1);

namespace App\Service\Export;

/**
 * What one ZIP export will contain (issue #76/M12-2), worked out before the
 * first byte goes out: the finished index.csv, and per file its path in the
 * ZIP and the blob it comes from. Built by App\Service\Export\ZipExport::
 * vorbereiten(), shown as the preview on /app/export, streamed by
 * ZipExport::strom().
 *
 * It holds decrypted names and amounts (the paths, the index), so it lives
 * only in the memory of the request that has the vault - never in the
 * session, the database or a file.
 */
final readonly class ExportPlan
{
    /**
     * @param list<array{pfad: string, blobId: int, stufe: int}> $eintraege
     *        in the order they go into the ZIP
     * @param int $groesse plaintext bytes of all files (`file_blob.size`)
     * @param int $fehlend files of matching receipts that could not be
     *        exported - blob missing or never finished; index.csv says so
     */
    public function __construct(
        public string $wurzel,
        public string $indexPfad,
        public string $indexCsv,
        public array $eintraege,
        public int $anzahlBelege,
        public int $groesse,
        public int $fehlend,
    ) {
    }

    /** The download name: the period only, never business data (05 section 2). */
    public function dateiname(): string
    {
        return $this->wurzel . '.zip';
    }

    /** Files in the ZIP, index.csv included. */
    public function anzahlDateien(): int
    {
        return count($this->eintraege) + 1;
    }

    /**
     * Whether the ZIP would outgrow what App\Service\Export\ZipStrom can
     * write without ZIP64 - with room to spare for headers and for
     * deflate's few bytes of growth on data that does not compress.
     */
    public function zuGross(): bool
    {
        return $this->groesse > ZipExport::MAX_GROESSE || $this->anzahlDateien() > ZipExport::MAX_DATEIEN;
    }
}
