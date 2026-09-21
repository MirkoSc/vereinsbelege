<?php

declare(strict_types=1);

namespace App\Tests\Service\Storage;

use App\Service\Crypto\CryptoException;
use App\Service\Crypto\DataKey;
use App\Service\Storage\BlobCipher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The blob stream format (docs/spec/01-sicherheit.md section 2 and its
 * "Pflicht-Tests": crypto round trips, manipulation fails).
 *
 * The cases that matter are the boundaries - empty, exactly one chunk, one
 * byte over - and everything that a broken or manipulated stream can look
 * like: a flipped byte, a missing end, a swapped block, a foreign key.
 */
final class BlobCipherTest extends TestCase
{
    /**
     * @return array<string, array{int}>
     */
    public static function sizes(): array
    {
        return [
            'empty' => [0],
            'one byte' => [1],
            'just under one chunk' => [BlobCipher::CHUNK_BYTES - 1],
            'exactly one chunk' => [BlobCipher::CHUNK_BYTES],
            'one byte over' => [BlobCipher::CHUNK_BYTES + 1],
            'several chunks' => [3 * BlobCipher::CHUNK_BYTES + 777],
        ];
    }

    #[DataProvider('sizes')]
    public function testRoundTrip(int $size): void
    {
        $key = DataKey::generate();
        $plaintext = self::bytes($size);

        $stream = self::encrypt($key, $plaintext, $header);

        self::assertSame($plaintext, self::decrypt($key, $header, $stream));
    }

    /**
     * The pieces a caller writes have nothing to do with the block size - the
     * upload arrives in 2 MiB chunks, a stream is read in 256 KiB pieces.
     */
    public function testPieceSizesDoNotChangeTheResult(): void
    {
        $key = DataKey::generate();
        $plaintext = self::bytes(2 * BlobCipher::CHUNK_BYTES + 4096);

        $writer = BlobCipher::writer($key);
        $stream = '';
        foreach (str_split($plaintext, 1000) as $piece) {
            $stream .= $writer->write($piece);
        }
        $stream .= $writer->finish();

        $reader = BlobCipher::reader($key, $writer->header());
        $result = '';
        foreach (str_split($stream, 7777) as $piece) {
            $result .= $reader->read($piece);
        }
        $result .= $reader->finish();

        self::assertSame($plaintext, $result);
    }

    public function testStreamRevealsNothingAboutTheContent(): void
    {
        $plaintext = str_repeat('Rechnung Getraenkemarkt 119,00 EUR', 100);

        $stream = self::encrypt(DataKey::generate(), $plaintext, $header);

        self::assertStringNotContainsString('Rechnung', $stream);
        self::assertStringNotContainsString('119,00', $stream);
        self::assertSame(BlobCipher::VERSION, ord($header[0]));
        self::assertSame(BlobCipher::HEADER_BYTES, strlen($header));
    }

    public function testSameContentEncryptsDifferentlyEveryTime(): void
    {
        $key = DataKey::generate();
        $plaintext = self::bytes(5000);

        self::assertNotSame(
            self::encrypt($key, $plaintext, $first),
            self::encrypt($key, $plaintext, $second),
            'every stream gets its own header, otherwise equal files would be recognisable',
        );
        self::assertNotSame($first, $second);
    }

    public function testAnotherKeyCannotRead(): void
    {
        $stream = self::encrypt(DataKey::generate(), self::bytes(5000), $header);

        $this->expectException(CryptoException::class);
        self::decrypt(DataKey::generate(), $header, $stream);
    }

    public function testFlippedByteIsNoticed(): void
    {
        $key = DataKey::generate();
        $stream = self::encrypt($key, self::bytes(5000), $header);
        $stream[100] = chr(ord($stream[100]) ^ 0x01);

        $this->expectException(CryptoException::class);
        self::decrypt($key, $header, $stream);
    }

    /** A file cut short is an error, not a shorter receipt. */
    public function testTruncatedStreamIsNoticed(): void
    {
        $key = DataKey::generate();
        $stream = self::encrypt($key, self::bytes(3 * BlobCipher::CHUNK_BYTES), $header);

        $this->expectException(CryptoException::class);
        self::decrypt($key, $header, substr($stream, 0, BlobCipher::BLOCK_BYTES));
    }

    public function testStreamWithoutItsFinalBlockIsNoticed(): void
    {
        $key = DataKey::generate();
        $writer = BlobCipher::writer($key);
        $stream = $writer->write(self::bytes(2 * BlobCipher::CHUNK_BYTES + 10));

        $this->expectException(CryptoException::class);
        self::decrypt($key, $writer->header(), $stream);
    }

    /** secretstream chains its blocks: reordering them breaks the chain. */
    public function testSwappedBlocksAreNoticed(): void
    {
        $key = DataKey::generate();
        $stream = self::encrypt($key, self::bytes(3 * BlobCipher::CHUNK_BYTES), $header);

        $first = substr($stream, 0, BlobCipher::BLOCK_BYTES);
        $second = substr($stream, BlobCipher::BLOCK_BYTES, BlobCipher::BLOCK_BYTES);
        $rest = substr($stream, 2 * BlobCipher::BLOCK_BYTES);

        $this->expectException(CryptoException::class);
        self::decrypt($key, $header, $second . $first . $rest);
    }

    public function testDataAfterTheFinalBlockIsNoticed(): void
    {
        $key = DataKey::generate();
        $stream = self::encrypt($key, self::bytes(100), $header);

        $this->expectException(CryptoException::class);
        self::decrypt($key, $header, $stream . 'noch etwas');
    }

    public function testUnknownVersionSaysSoInsteadOfReturningNonsense(): void
    {
        $key = DataKey::generate();
        $stream = self::encrypt($key, self::bytes(100), $header);
        $header[0] = chr(BlobCipher::VERSION + 1);

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('unknown format version');
        self::decrypt($key, $header, $stream);
    }

    public function testHeaderOfTheWrongLengthIsRejected(): void
    {
        $this->expectException(CryptoException::class);
        BlobCipher::reader(DataKey::generate(), chr(BlobCipher::VERSION));
    }

    public function testWriterRefusesToContinueAfterFinish(): void
    {
        $writer = BlobCipher::writer(DataKey::generate());
        $writer->finish();

        $this->expectException(CryptoException::class);
        $writer->write('mehr');
    }

    private static function bytes(int $size): string
    {
        // Not random_bytes(): a repeating pattern makes it visible if blocks
        // end up in the wrong order.
        return substr(str_repeat('Beleg 0123456789 ', (int) ($size / 17) + 1), 0, $size);
    }

    private static function encrypt(DataKey $key, string $plaintext, ?string &$header): string
    {
        $writer = BlobCipher::writer($key);
        $header = $writer->header();

        return $writer->write($plaintext) . $writer->finish();
    }

    private static function decrypt(DataKey $key, string $header, string $stream): string
    {
        $reader = BlobCipher::reader($key, $header);

        return $reader->read($stream) . $reader->finish();
    }
}
