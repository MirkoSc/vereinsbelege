<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Sends a response piece by piece, from an iterable of strings.
 *
 * This is what a decrypted blob goes out through (M2-3): the plaintext of a
 * receipt must never become a file - not in `shared/var/tmp`, not in the
 * system temp directory - and a 20 MB scan must not have to fit into the
 * memory limit at once. {@see FileResponse} stays the right answer for a file
 * that already lies on disk in the clear, such as a static asset.
 *
 * The output buffers are flushed after every piece, so the browser starts
 * displaying while the server is still decrypting.
 */
final readonly class StreamResponse implements ResponseInterface
{
    /**
     * @param iterable<string>      $chunks
     * @param array<string, string> $headers
     */
    public function __construct(
        public iterable $chunks,
        public array $headers = [],
        public int $status = 200,
    ) {
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        foreach ($this->chunks as $chunk) {
            if ($chunk === '') {
                continue;
            }
            echo $chunk;
            // ob_get_level() > 0: a leftover output buffer would collect the
            // whole file and undo the streaming.
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }
    }
}
