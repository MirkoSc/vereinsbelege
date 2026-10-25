<?php

declare(strict_types=1);

namespace App\Service\Upload;

/**
 * A finished upload, opened once for checking and storing (security review
 * N-13, issue #242): the magic byte check sees exactly the bytes that are
 * then encrypted, nothing is read twice.
 *
 * Why that matters: a chunk may be sent again at any time, and
 * UploadService::writeChunk() renames the new one into place. Reading the
 * head for the check and then the whole file again for the blob left a gap
 * in which a parallel request could swap the first chunk - a file that
 * passed as a PDF would be stored as whatever came after. Here every chunk
 * file is opened before the first byte is read; a handle keeps the file it
 * was opened on even when another one is renamed over its name, so what
 * was opened is what is checked and what is stored.
 *
 * kopf() reads the first bytes and keeps them; chunks() hands those out
 * first and continues on the same handles.
 */
final class OffenesUpload
{
    /** Copy buffer; the same size the blob cipher works with. */
    private const int READ_BYTES = 65536;

    private string $puffer = '';

    private int $aktuell = 0;

    private bool $gelesen = false;

    /**
     * @param list<resource> $teile one open handle per chunk, in order
     */
    public function __construct(
        private array $teile,
        public readonly int $groesse,
    ) {
    }

    /**
     * The first $bytes of the file, for MagicBytes::detect() - fewer only
     * when the file is shorter.
     */
    public function kopf(int $bytes = MagicBytes::HEAD_BYTES): string
    {
        while (strlen($this->puffer) < $bytes) {
            $stueck = $this->naechstesStueck($bytes - strlen($this->puffer));
            if ($stueck === null) {
                break;
            }

            $this->puffer .= $stueck;
        }

        return substr($this->puffer, 0, $bytes);
    }

    /**
     * The whole file, in order and in pieces - starting with what kopf()
     * already read. Can be iterated once.
     *
     * @return \Generator<int, string>
     * @throws UploadException when the file is not as long as announced
     */
    public function chunks(): \Generator
    {
        if ($this->gelesen) {
            throw new \LogicException('An opened upload can be read only once.');
        }
        $this->gelesen = true;

        $laenge = strlen($this->puffer);
        if ($this->puffer !== '') {
            yield $this->puffer;
            $this->puffer = '';
        }

        while (($stueck = $this->naechstesStueck(self::READ_BYTES)) !== null) {
            $laenge += strlen($stueck);
            yield $stueck;
        }

        if ($laenge !== $this->groesse) {
            throw new UploadException(UploadError::Incomplete);
        }
    }

    public function schliessen(): void
    {
        foreach ($this->teile as $teil) {
            if (is_resource($teil)) {
                fclose($teil);
            }
        }

        $this->teile = [];
    }

    public function __destruct()
    {
        $this->schliessen();
    }

    /** Up to $bytes from where reading stopped, across chunk boundaries; null at the end. */
    private function naechstesStueck(int $bytes): ?string
    {
        while ($this->aktuell < count($this->teile)) {
            $stueck = fread($this->teile[$this->aktuell], max(1, $bytes));
            if ($stueck !== false && $stueck !== '') {
                return $stueck;
            }

            $this->aktuell++;
        }

        return null;
    }
}
