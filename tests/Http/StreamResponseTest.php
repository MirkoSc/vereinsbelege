<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\StreamResponse;
use PHPUnit\Framework\TestCase;

/**
 * The response a decrypted blob goes out through (issue #10): it must send
 * piece by piece and never collect the whole file first.
 *
 * Capturing happens through an output handler and not through ob_get_clean(),
 * because send() flushes - which is exactly the behaviour under test.
 */
final class StreamResponseTest extends TestCase
{
    public function testSendsEveryPieceOnItsOwn(): void
    {
        $sent = $this->capture(new StreamResponse(['Teil 1 ', 'Teil 2 ', 'Teil 3']));

        self::assertSame(['Teil 1 ', 'Teil 2 ', 'Teil 3'], $sent, 'every piece goes out on its own, nothing is collected');
        self::assertSame('Teil 1 Teil 2 Teil 3', implode('', $sent));
    }

    /**
     * The source is pulled lazily: a generator decrypting block by block must
     * not be drained before the first byte leaves.
     */
    public function testPullsTheSourceLazily(): void
    {
        $produced = [];
        $chunks = (function () use (&$produced): \Generator {
            foreach (['a', 'b', 'c'] as $piece) {
                $produced[] = $piece;
                yield $piece;
            }
        })();

        $response = new StreamResponse($chunks);
        self::assertSame([], $produced, 'nothing is produced before sending');

        self::assertSame(['a', 'b', 'c'], $this->capture($response));
        self::assertSame(['a', 'b', 'c'], $produced);
    }

    public function testEmptyPiecesAreSkipped(): void
    {
        self::assertSame(['Inhalt'], $this->capture(new StreamResponse(['', 'Inhalt', ''])));
    }

    /**
     * Headers cannot be observed in the CLI test process - what is checked
     * here is that the response carries them unchanged to send().
     */
    public function testCarriesHeadersAndStatus(): void
    {
        $response = new StreamResponse(
            ['%PDF-1.4'],
            ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="beleg.pdf"'],
            206,
        );

        self::assertSame('application/pdf', $response->headers['Content-Type']);
        self::assertSame(206, $response->status);
    }

    /**
     * @return list<string> what left the output buffer, one entry per flush
     */
    private function capture(StreamResponse $response): array
    {
        $sent = [];
        ob_start(static function (string $buffer) use (&$sent): string {
            if ($buffer !== '') {
                $sent[] = $buffer;
            }

            // Nothing is passed on: the test process is not a browser.
            return '';
        }, 1);

        try {
            $response->send();
        } finally {
            ob_end_clean();
        }

        return $sent;
    }
}
