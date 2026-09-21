<?php

declare(strict_types=1);

namespace App\Service\Upload;

/**
 * Collects a file from 2 MiB chunks in `shared/var/tmp`
 * (docs/spec/03-erfassung-und-ki.md section 4).
 *
 * Why at all: no single request on this host may run long (CLAUDE.md section
 * 1), and the hoster's upload limit applies per request. Cut into chunks, a
 * 30 MB PDF is a series of short requests instead of one that times out.
 *
 * No database table. The state of an upload is its directory - that keeps the
 * cron cleanup free of any database access (a CronTask must never decrypt and
 * should not need a connection), and an interrupted upload leaves nothing
 * behind but files with an old timestamp.
 *
 * `shared/var/tmp/upload/<32 hex>/`
 *     meta.json   announced size, chunk count, chunk size, creation time
 *     <n>.part    chunk n, plaintext
 *
 * Two things are deliberately NOT in there: the original file name and the
 * MIME type the browser claimed. A file name like
 * "Rechnung_Getraenkemarkt_Mueller.pdf" is business data and must not lie
 * around in the clear (CLAUDE.md section 4), so it travels with the closing
 * request and goes straight into the encrypted blob metadata. The chunk
 * content itself may be plaintext here - that is the one exception
 * `shared/var/tmp` exists for.
 *
 * Chunks may arrive in any order and more than once: each one is written to a
 * temporary name and renamed into place, so a request that dies halfway
 * leaves no half chunk, and resending a chunk simply overwrites it.
 *
 * Framework-free on purpose: no Http, no PDO, no session.
 */
final readonly class UploadService
{
    /** Chunk size from the spec. The last chunk is shorter. */
    public const int CHUNK_BYTES = 2 * 1024 * 1024;

    /**
     * Upper bound for one file. A receipt photo is a few MB, a scanned PDF
     * with many pages can be a few dozen - above that something is wrong, and
     * the disk of a shared host is not big. A constant, not a setting: it
     * bounds what an unfinished upload can cost before the cron cleans up.
     */
    public const int MAX_FILE_BYTES = 32 * 1024 * 1024;

    private const string META = 'meta.json';

    /** Copy buffer; the same size the blob cipher works with. */
    private const int READ_BYTES = 65536;

    private const string ID_PATTERN = '/^[0-9a-f]{32}$/';

    /**
     * @param int $chunkBytes the size new uploads are cut into. Only the
     *        tests pass anything but the constant - every upload carries the
     *        size it was opened with in its metadata, so changing it later
     *        never breaks one that is already running.
     */
    public function __construct(
        private string $uploadDir,
        private int $chunkBytes = self::CHUNK_BYTES,
    ) {
    }

    /**
     * Opens an upload and says how the browser has to cut the file.
     *
     * @throws UploadException when the announced size is empty or too large -
     *                         before a single byte was transferred
     */
    public function create(int $groesse, ?\DateTimeImmutable $now = null): UploadTicket
    {
        if ($groesse < 1) {
            throw new UploadException(UploadError::EmptyFile);
        }

        if ($groesse > self::MAX_FILE_BYTES) {
            throw new UploadException(UploadError::TooLarge);
        }

        $id = bin2hex(random_bytes(16));
        $chunks = (int) ceil($groesse / $this->chunkBytes);
        $dir = $this->uploadDir . '/' . $id;

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the upload directory.');
        }

        $ticket = new UploadTicket($id, $groesse, $chunks, $this->chunkBytes);
        $meta = [
            'erstellt' => ($now ?? new \DateTimeImmutable())->getTimestamp(),
            'groesse' => $groesse,
            'chunks' => $chunks,
            'chunk_bytes' => $this->chunkBytes,
        ];

        if (file_put_contents($dir . '/' . self::META, json_encode($meta, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write the upload metadata.');
        }

        return $ticket;
    }

    /**
     * Stores one chunk and returns how many bytes it had.
     *
     * A chunk that is longer than it may be is refused while it is being
     * read, so an oversized body cannot fill the disk first.
     *
     * @param resource $stream the request body
     * @throws UploadException on an unknown upload, an index outside the
     *                         file, or an oversized chunk
     */
    public function writeChunk(string $id, int $index, mixed $stream): int
    {
        $dir = $this->directory($id);
        $meta = $this->meta($id);

        if ($index < 0 || $index >= $meta['chunks']) {
            throw new UploadException(UploadError::ChunkOutOfRange);
        }

        $erlaubt = $this->chunkSize($index, $meta);
        $temp = sprintf('%s/%d.%s.part', $dir, $index, bin2hex(random_bytes(4)));
        $ziel = sprintf('%s/%d.part', $dir, $index);

        $out = fopen($temp, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Cannot open the chunk file.');
        }

        $geschrieben = 0;
        try {
            while (!feof($stream)) {
                $stueck = fread($stream, self::READ_BYTES);
                if ($stueck === false || $stueck === '') {
                    break;
                }

                $geschrieben += strlen($stueck);
                if ($geschrieben > $erlaubt) {
                    throw new UploadException(UploadError::ChunkTooLarge);
                }

                if (fwrite($out, $stueck) === false) {
                    throw new \RuntimeException('Cannot write the chunk file.');
                }
            }
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($temp);

            throw $e;
        }

        fclose($out);

        // A short chunk is not refused: it lands, status() reports it as
        // missing because its length is wrong, and the browser sends it
        // again over the same index.
        if (!rename($temp, $ziel)) {
            @unlink($temp);

            throw new \RuntimeException('Cannot store the chunk file.');
        }

        return $geschrieben;
    }

    /**
     * What is on disk: which chunks still have to come, and how much of the
     * file is already here.
     *
     * @throws UploadException when the upload is unknown
     */
    public function status(string $id): UploadStatus
    {
        $dir = $this->directory($id);
        $meta = $this->meta($id);
        clearstatcache();

        $fehlend = [];
        $empfangen = 0;
        for ($i = 0; $i < $meta['chunks']; $i++) {
            $pfad = sprintf('%s/%d.part', $dir, $i);
            $groesse = is_file($pfad) ? filesize($pfad) : false;
            if ($groesse === false || $groesse !== $this->chunkSize($i, $meta)) {
                $fehlend[] = $i;

                continue;
            }

            $empfangen += $groesse;
        }

        return new UploadStatus($meta['chunks'], $fehlend, $meta['groesse'], $empfangen);
    }

    /**
     * The first bytes of the file, for the magic byte check - never more than
     * asked for, so a 30 MB scan is not read to learn what it is.
     *
     * It reads across chunk boundaries on purpose: with the real chunk size a
     * signature always fits into the first chunk, but the boundary must not
     * be what decides whether a file is recognised. Returns what is there,
     * which is an empty string when nothing has arrived yet - the caller
     * checks completeness first anyway.
     *
     * @throws UploadException when the upload is unknown
     */
    public function head(string $id, int $bytes = MagicBytes::HEAD_BYTES): string
    {
        $dir = $this->directory($id);
        $meta = $this->meta($id);

        $head = '';
        for ($i = 0; $i < $meta['chunks'] && strlen($head) < $bytes; $i++) {
            $pfad = sprintf('%s/%d.part', $dir, $i);
            $teil = is_file($pfad) ? file_get_contents($pfad, false, null, 0, $bytes - strlen($head)) : false;
            if ($teil === false || $teil === '') {
                break;
            }

            $head .= $teil;
        }

        return $head;
    }

    /**
     * The file, in order, in pieces - to be handed straight to
     * BlobService::store(). Nothing is assembled into a second file: the
     * plaintext never exists as a whole anywhere, not on disk and not in
     * memory.
     *
     * @return \Generator<int, string>
     * @throws UploadException when a chunk is gone (a cleanup that ran in
     *                         between, a manual delete)
     */
    public function chunks(string $id): \Generator
    {
        $dir = $this->directory($id);
        $meta = $this->meta($id);

        for ($i = 0; $i < $meta['chunks']; $i++) {
            $pfad = sprintf('%s/%d.part', $dir, $i);
            $in = is_file($pfad) ? fopen($pfad, 'rb') : false;
            if ($in === false) {
                throw new UploadException(UploadError::Incomplete);
            }

            try {
                while (!feof($in)) {
                    $stueck = fread($in, self::READ_BYTES);
                    if ($stueck === false || $stueck === '') {
                        break;
                    }

                    yield $stueck;
                }
            } finally {
                fclose($in);
            }
        }
    }

    /**
     * Throws the upload away. Idempotent: an upload that is already gone is
     * not an error, which is what makes the closing request and the cron able
     * to run into each other without a lock.
     */
    public function discard(string $id): void
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return;
        }

        self::removeDir($this->uploadDir . '/' . $id);
    }

    /**
     * Removes uploads whose newest file is older than the given moment - the
     * cron's 24 h rule (spec 03 section 4).
     *
     * The newest timestamp inside the directory decides, not the directory's
     * own: an upload that is being written to right now keeps refreshing it
     * with every chunk, so a running upload is never swept away underneath
     * the browser.
     *
     * @return int number of uploads removed
     */
    public function cleanup(\DateTimeImmutable $aelterAls): int
    {
        if (!is_dir($this->uploadDir)) {
            return 0;
        }

        $grenze = $aelterAls->getTimestamp();
        $eintraege = scandir($this->uploadDir);
        if ($eintraege === false) {
            return 0;
        }

        clearstatcache();
        $entfernt = 0;
        foreach ($eintraege as $eintrag) {
            if (preg_match(self::ID_PATTERN, $eintrag) !== 1) {
                continue;
            }

            $dir = $this->uploadDir . '/' . $eintrag;
            if (!is_dir($dir) || self::newestMtime($dir) >= $grenze) {
                continue;
            }

            self::removeDir($dir);
            $entfernt++;
        }

        return $entfernt;
    }

    /**
     * @throws UploadException when the id is not one of ours or the upload
     *                         does not exist (any more)
     */
    private function directory(string $id): string
    {
        // The id goes into a path, so it is checked before it is used - a
        // random 32 hex string and nothing else (same rule as
        // FsBlobBackend::path()).
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new UploadException(UploadError::UnknownUpload);
        }

        $dir = $this->uploadDir . '/' . $id;
        if (!is_dir($dir)) {
            throw new UploadException(UploadError::UnknownUpload);
        }

        return $dir;
    }

    /**
     * @return array{erstellt: int, groesse: int, chunks: int, chunk_bytes: int}
     */
    private function meta(string $id): array
    {
        $roh = @file_get_contents($this->uploadDir . '/' . $id . '/' . self::META);
        $daten = is_string($roh) && $roh !== '' ? json_decode($roh, true) : null;
        if (!is_array($daten) || !isset($daten['groesse'], $daten['chunks'], $daten['chunk_bytes'])) {
            throw new UploadException(UploadError::UnknownUpload);
        }

        return [
            'erstellt' => (int) ($daten['erstellt'] ?? 0),
            'groesse' => (int) $daten['groesse'],
            'chunks' => (int) $daten['chunks'],
            'chunk_bytes' => (int) $daten['chunk_bytes'],
        ];
    }

    /**
     * How long chunk $index has to be: full, except for the last one.
     *
     * @param array{erstellt: int, groesse: int, chunks: int, chunk_bytes: int} $meta
     */
    private function chunkSize(int $index, array $meta): int
    {
        if ($index < $meta['chunks'] - 1) {
            return $meta['chunk_bytes'];
        }

        return $meta['groesse'] - ($meta['chunks'] - 1) * $meta['chunk_bytes'];
    }

    private static function newestMtime(string $dir): int
    {
        $neueste = (int) @filemtime($dir);
        $eintraege = scandir($dir);
        foreach ($eintraege === false ? [] : $eintraege as $eintrag) {
            if ($eintrag === '.' || $eintrag === '..') {
                continue;
            }

            $neueste = max($neueste, (int) @filemtime($dir . '/' . $eintrag));
        }

        return $neueste;
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $eintraege = scandir($dir);
        foreach ($eintraege === false ? [] : $eintraege as $eintrag) {
            if ($eintrag === '.' || $eintrag === '..') {
                continue;
            }

            @unlink($dir . '/' . $eintrag);
        }

        @rmdir($dir);
    }
}
