<?php

declare(strict_types=1);

namespace App\Service\Migration;

/**
 * Reads SQL statements one by one from a file, starting at a byte offset,
 * with a fixed-size read buffer - the streaming counterpart of
 * SqlSplitter::split() for dump.sql files of several hundred MB (issue
 * #120, docs/spec/06-betrieb.md section 2).
 *
 * It splits exactly like SqlSplitter (quotes with backslash escapes, which
 * do not apply inside backticks; line comments -- and #; block comments),
 * so a file read through here gives the same statements as split() on the
 * whole text. The lexer state (open quote, comment) survives buffer
 * boundaries within one instance. Across requests only grenze() is kept:
 * it always points just behind a statement's semicolon, where the lexer
 * is outside any quote or comment, so a fresh instance can start there
 * with a clean state.
 *
 * Memory stays bounded by the read buffer plus the one statement being
 * collected; a statement above MAX_STATEMENT_BYTES is refused instead of
 * growing until memory_limit.
 */
final class SqlStatementStream
{
    public const int READ_BYTES = 1024 * 1024;

    /**
     * The largest statement accepted. The dumper flushes an INSERT at
     * 512 KiB plus one row (MysqlDumper::BYTES_PER_INSERT), a hex chunk row
     * of file_blob_chunk is about 512 KiB - and MariaDB's default
     * max_allowed_packet is 16 MiB, so anything larger would fail anyway.
     */
    public const int MAX_STATEMENT_BYTES = 16 * 1024 * 1024;

    /** Characters that end a run of plain text outside quotes. */
    private const string SPECIAL = ";#-/'\"`";

    /** @var resource */
    private $handle;

    private string $buffer = '';

    /** Read position within $buffer. */
    private int $pos = 0;

    /** File offset of $buffer[0]. */
    private int $base;

    private int $boundary;

    private int $bytesRead = 0;

    private readonly int $size;

    /**
     * @param int<1, max> $readBytes
     *
     * @throws \RuntimeException when the file cannot be read or the offset lies outside it
     */
    public function __construct(string $file, int $offset = 0, private readonly int $readBytes = self::READ_BYTES)
    {
        $handle = @fopen($file, 'rb');
        $stat = $handle === false ? false : fstat($handle);
        if ($handle === false || $stat === false) {
            if ($handle !== false) {
                fclose($handle);
            }
            throw new \RuntimeException('dump.sql nicht lesbar.');
        }
        $this->size = (int) $stat['size'];
        if ($offset < 0 || $offset > $this->size || fseek($handle, $offset) !== 0) {
            fclose($handle);
            throw new \RuntimeException('Ungültige Position in dump.sql - bitte die Wiederherstellung neu beginnen.');
        }
        $this->handle = $handle;
        $this->base = $offset;
        $this->boundary = $offset;
    }

    public function __destruct()
    {
        fclose($this->handle);
    }

    /**
     * The next non-empty statement, trimmed and without its semicolon, or
     * null at the end of the file.
     *
     * @throws \RuntimeException for a statement above MAX_STATEMENT_BYTES
     */
    public function next(): ?string
    {
        $current = '';

        while (true) {
            if ($this->pos >= strlen($this->buffer) && !$this->refill()) {
                break;
            }

            $run = strcspn($this->buffer, self::SPECIAL, $this->pos);
            if ($run > 0) {
                $current .= substr($this->buffer, $this->pos, $run);
                $this->pos += $run;
                self::guardSize($current);
                continue;
            }

            $char = $this->buffer[$this->pos];

            if ($char === ';') {
                $this->pos++;
                $this->boundary = $this->base + $this->pos;
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    return $trimmed;
                }
                $current = '';
                continue;
            }

            if ($char === '#') {
                $this->pos++;
                $this->skipLineComment();
                continue;
            }

            if ($char === '-' || $char === '/') {
                $following = $this->following();
                if ($char === '-' && $following === '-') {
                    $this->pos += 2;
                    $this->skipLineComment();
                    continue;
                }
                if ($char === '/' && $following === '*') {
                    $this->pos += 2;
                    $this->skipBlockComment();
                    continue;
                }
                $current .= $char;
                $this->pos++;
                continue;
            }

            // a quote: ' " or `
            $this->pos++;
            $current .= $char;
            $this->readQuoted($char, $current);
            self::guardSize($current);
        }

        // End of file: a last statement may lack its semicolon.
        $this->boundary = $this->base + $this->pos;
        $trimmed = trim($current);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Byte offset just behind the last statement next() returned (or the end
     * of the file once it returned null) - where the next request resumes.
     */
    public function boundary(): int
    {
        return $this->boundary;
    }

    /** Size of the file when it was opened. */
    public function size(): int
    {
        return $this->size;
    }

    /** Bytes this instance has read from the file - for the tests of issue #120. */
    public function bytesRead(): int
    {
        return $this->bytesRead;
    }

    /**
     * Appends the rest of a quoted string or identifier, including its
     * closing quote, to $current. Mirrors SqlSplitter: a backslash escapes
     * the next byte except inside backticks; a backslash as the very last
     * byte of the file is kept as it is.
     */
    private function readQuoted(string $quote, string &$current): void
    {
        $stops = $quote === '`' ? '`' : $quote . '\\';

        while (true) {
            if ($this->pos >= strlen($this->buffer) && !$this->refill()) {
                return;
            }

            $run = strcspn($this->buffer, $stops, $this->pos);
            if ($run > 0) {
                $current .= substr($this->buffer, $this->pos, $run);
                $this->pos += $run;
                self::guardSize($current);
                continue;
            }

            $char = $this->buffer[$this->pos];
            if ($char === '\\') {
                $following = $this->following();
                if ($following === null) {
                    $current .= '\\';
                    $this->pos++;

                    return;
                }
                $current .= '\\' . $following;
                $this->pos += 2;
                continue;
            }

            $current .= $char;
            $this->pos++;

            return;
        }
    }

    /** Up to (not including) the next newline, like SqlSplitter. */
    private function skipLineComment(): void
    {
        while (true) {
            $newline = strpos($this->buffer, "\n", $this->pos);
            if ($newline !== false) {
                $this->pos = $newline;

                return;
            }
            $this->pos = strlen($this->buffer);
            if (!$this->refill()) {
                return;
            }
        }
    }

    /** Past the next "*" "/", or to the end of the file when there is none. */
    private function skipBlockComment(): void
    {
        while (true) {
            $end = strpos($this->buffer, '*/', $this->pos);
            if ($end !== false) {
                $this->pos = $end + 2;

                return;
            }
            // Keep a trailing "*": its "/" may be the first byte of the next read.
            $this->pos = max($this->pos, strlen($this->buffer) - 1);
            if (!$this->refill()) {
                $this->pos = strlen($this->buffer);

                return;
            }
        }
    }

    /** The byte after the current one, reading on if needed; null at the end of the file. */
    private function following(): ?string
    {
        while ($this->pos + 1 >= strlen($this->buffer)) {
            if (!$this->refill()) {
                return null;
            }
        }

        return $this->buffer[$this->pos + 1];
    }

    /**
     * Drops the consumed part of the buffer and appends the next read.
     * False at the end of the file.
     */
    private function refill(): bool
    {
        if ($this->pos > 0) {
            $this->base += $this->pos;
            $this->buffer = substr($this->buffer, $this->pos);
            $this->pos = 0;
        }

        $data = fread($this->handle, $this->readBytes);
        if ($data === false) {
            throw new \RuntimeException('dump.sql nicht lesbar.');
        }
        if ($data === '') {
            return false;
        }
        $this->bytesRead += strlen($data);
        $this->buffer .= $data;

        return true;
    }

    private static function guardSize(string $current): void
    {
        if (strlen($current) > self::MAX_STATEMENT_BYTES) {
            throw new \RuntimeException(
                'dump.sql enthält eine Anweisung über 16 MiB - das Backup ist beschädigt oder stammt nicht aus dieser Anwendung.',
            );
        }
    }
}
