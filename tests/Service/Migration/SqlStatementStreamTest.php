<?php

declare(strict_types=1);

namespace App\Tests\Service\Migration;

use App\Service\Migration\SqlSplitter;
use App\Service\Migration\SqlStatementStream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #120: the streaming reader must split exactly like SqlSplitter, also
 * when a buffer boundary falls into a quote, an escape or a comment marker,
 * and a fresh instance at any boundary() must carry on seamlessly.
 */
final class SqlStatementStreamTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/vb_sqlstream_' . uniqid('', true) . '.sql';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function knifflig(): array
    {
        return [
            'semicolon in quotes' => ["INSERT INTO t VALUES ('a;b', \"c;d\", `e;f`);SELECT 2;"],
            'escaped quotes' => ["INSERT INTO t VALUES ('it\\'s; fine', \"say \\\"x;\\\"\");SELECT 1"],
            'doubled quotes' => ["INSERT INTO t VALUES ('it''s; fine');SELECT 1;"],
            'no escape in backticks' => ["SELECT `a\\`; SELECT 'b';"],
            'backslash before closing quote' => ["SELECT 'C:\\\\';SELECT 2;"],
            'line comments' => ["-- a; b\nSELECT 1; # c; 'd\nSELECT 2;--e"],
            'block comments' => ["/* a; 'b */SELECT 1;/*/ still; comment */SELECT 2;/* open"],
            'star then slash' => ["SELECT 1 /* x **/; SELECT 2 */;"],
            'minus and slash alone' => ['SELECT 3-1; SELECT 6/2;'],
            'comment markers in strings' => ["SELECT '-- no', '# no', '/* no */';"],
            'unterminated quote' => ["SELECT 1; SELECT 'open; still open"],
            'backslash at the very end' => ["SELECT 1; SELECT 'x\\"],
            'empty statements' => [";;  ;\n SELECT 1 ;; ;"],
            'only comments' => ["-- nothing\n/* here */ # at all"],
            'empty' => [''],
            'hex literal' => ['INSERT INTO c VALUES (1, 0x' . str_repeat('0a1b', 300) . ');'],
        ];
    }

    #[DataProvider('knifflig')]
    public function testSplitsLikeSqlSplitterForEveryBufferSize(string $sql): void
    {
        file_put_contents($this->file, $sql);
        $erwartet = SqlSplitter::split($sql);

        foreach ([1, 2, 3, 5, 8, 64, SqlStatementStream::READ_BYTES] as $puffer) {
            self::assertSame($erwartet, $this->alle(0, $puffer), 'buffer of ' . $puffer . ' bytes');
            self::assertSame($erwartet, $this->stueckweise($puffer, strlen($sql)), 'resumed, buffer of ' . $puffer);
        }
    }

    /**
     * Random scripts from the characters that matter - a deterministic seed,
     * so a failure reproduces.
     */
    public function testRandomScriptsSplitLikeSqlSplitter(): void
    {
        $zeichen = ["'", '"', '`', '\\', ';', '-', '#', '/', '*', "\n", ' ', 'a', '0x1F', '--', '/*', '*/', "''"];
        mt_srand(120);
        for ($fall = 0; $fall < 1500; $fall++) {
            $sql = '';
            for ($i = mt_rand(0, 40); $i > 0; $i--) {
                $sql .= $zeichen[mt_rand(0, count($zeichen) - 1)];
            }
            file_put_contents($this->file, $sql);
            $puffer = mt_rand(1, 6);

            $erwartet = SqlSplitter::split($sql);
            self::assertSame($erwartet, $this->alle(0, $puffer), 'case ' . $fall . ': ' . json_encode($sql));
            self::assertSame($erwartet, $this->stueckweise($puffer, strlen($sql)), 'case ' . $fall . ' resumed');
        }
    }

    public function testTheBoundaryIsJustBehindTheSemicolon(): void
    {
        file_put_contents($this->file, "SELECT 1;\n-- c\nSELECT 'x;y';  ");

        $stream = new SqlStatementStream($this->file);
        self::assertSame(0, $stream->boundary());
        self::assertSame('SELECT 1', $stream->next());
        self::assertSame(9, $stream->boundary());
        self::assertSame("SELECT 'x;y'", $stream->next());
        self::assertSame(28, $stream->boundary());
        self::assertNull($stream->next());
        self::assertSame(30, $stream->boundary(), 'the end of the file');
        self::assertSame(30, $stream->size());
        self::assertNull($stream->next(), 'and stays there');
    }

    public function testAResumedStreamDoesNotReadFromTheStart(): void
    {
        $zeile = "INSERT INTO t VALUES ('" . str_repeat('x', 1000) . "');\n";
        file_put_contents($this->file, str_repeat($zeile, 1000));

        $stream = new SqlStatementStream($this->file, 999 * strlen($zeile), 4096);
        self::assertNotNull($stream->next());
        self::assertNull($stream->next());
        self::assertSame(strlen($zeile), $stream->bytesRead(), 'only the last statement is read');
    }

    public function testMemoryStaysBoundedForALargeFile(): void
    {
        $zeile = 'INSERT INTO c VALUES (1, 0x' . bin2hex(random_bytes(256 * 1024)) . ", 'a;b');\n";
        $handle = fopen($this->file, 'wb');
        self::assertIsResource($handle);
        for ($i = 0; $i < 40; $i++) {
            fwrite($handle, $zeile);
        }
        fclose($handle);

        $vorher = memory_get_usage();
        $stream = new SqlStatementStream($this->file);
        $anzahl = 0;
        $spitze = 0;
        while (($statement = $stream->next()) !== null) {
            self::assertSame(trim($zeile, ";\n"), $statement);
            $anzahl++;
            $spitze = max($spitze, memory_get_usage() - $vorher);
        }

        self::assertSame(40, $anzahl);
        // 20 MiB of dump; the reader holds a read buffer and one statement.
        self::assertLessThan(SqlStatementStream::READ_BYTES * 2 + strlen($zeile) * 3, $spitze);
    }

    public function testAnOffsetOutsideTheFileIsRefused(): void
    {
        file_put_contents($this->file, 'SELECT 1;');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Ungültige Position');

        new SqlStatementStream($this->file, 10);
    }

    public function testAMissingFileIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nicht lesbar');

        new SqlStatementStream($this->file . '.fehlt');
    }

    public function testAnOversizedStatementIsRefusedInsteadOfFillingMemory(): void
    {
        $handle = fopen($this->file, 'wb');
        self::assertIsResource($handle);
        fwrite($handle, "SELECT '");
        $block = str_repeat('x', 1024 * 1024);
        for ($i = 0; $i <= SqlStatementStream::MAX_STATEMENT_BYTES / strlen($block); $i++) {
            fwrite($handle, $block);
        }
        fwrite($handle, "';");
        fclose($handle);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('über 16 MiB');

        new SqlStatementStream($this->file)->next();
    }

    /**
     * @param int<1, max> $puffer
     *
     * @return list<string>
     */
    private function alle(int $offset, int $puffer): array
    {
        $stream = new SqlStatementStream($this->file, $offset, $puffer);
        $statements = [];
        while (($statement = $stream->next()) !== null) {
            $statements[] = $statement;
        }

        return $statements;
    }

    /**
     * One statement per instance, each starting at the boundary the one
     * before left - a request chain in miniature.
     *
     * @param int<1, max> $puffer
     *
     * @return list<string>
     */
    private function stueckweise(int $puffer, int $groesse): array
    {
        $statements = [];
        $offset = 0;
        while (true) {
            $stream = new SqlStatementStream($this->file, $offset, $puffer);
            $statement = $stream->next();
            self::assertGreaterThanOrEqual($offset, $stream->boundary());
            $offset = $stream->boundary();
            if ($statement === null) {
                break;
            }
            $statements[] = $statement;
        }
        self::assertSame($groesse, $offset, 'ends at the end of the file');

        return $statements;
    }
}
