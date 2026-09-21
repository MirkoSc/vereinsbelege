<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Appends error lines to shared/var/log/app.log (CLAUDE.md section 2).
 *
 * error_log() alone is not enough on this hosting: it lands in the
 * provider's PHP log, which is not reliably readable from the customer panel
 * and rotates on its own schedule. For a system whose operation is meant to
 * be observable by one volunteer treasurer, that is a blind spot - a 500 in
 * the public submission path would leave no trace anyone could find.
 *
 * Deliberately tiny and dependency-free. Every write is best effort: a logger
 * that throws would turn a handled error into an unhandled one, and it runs
 * inside the global error handler of all places.
 *
 * Callers are responsible for what they pass in - no business plaintext ever
 * reaches this file (CLAUDE.md section 4). Http\Kernel logs class, message,
 * route and location, never the exception string.
 */
final readonly class FileLogger
{
    public const int DEFAULT_MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private string $file,
        private int $maxBytes = self::DEFAULT_MAX_BYTES,
    ) {
    }

    public function append(string $line): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $this->rotate();

        @file_put_contents(
            $this->file,
            sprintf("[%s] %s\n", new \DateTimeImmutable()->format('c'), rtrim($line, "\r\n")),
            FILE_APPEND | LOCK_EX,
        );
    }

    /**
     * One generation is enough here: the file is read when something broke,
     * not mined for history, and unbounded growth on a hosting package with
     * a fixed quota is the worse failure - a full disk would take the whole
     * site down, which is precisely what the log is supposed to help debug.
     */
    private function rotate(): void
    {
        $size = @filesize($this->file);
        if ($size === false || $size < $this->maxBytes) {
            return;
        }

        @rename($this->file, $this->file . '.1');
    }
}
