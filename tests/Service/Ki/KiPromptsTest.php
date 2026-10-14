<?php

declare(strict_types=1);

namespace App\Tests\Service\Ki;

use App\Config\Paths;
use App\Service\Ki\KiPrompts;
use PHPUnit\Framework\TestCase;

/**
 * The versioned prompts under app/prompts/ (M7-2, issue #44,
 * docs/spec/03-erfassung-und-ki.md section 6).
 */
final class KiPromptsTest extends TestCase
{
    private string $verzeichnis;

    protected function setUp(): void
    {
        $this->verzeichnis = sys_get_temp_dir() . '/ki-prompts-' . bin2hex(random_bytes(4));
        mkdir($this->verzeichnis);
        file_put_contents($this->verzeichnis . '/test-v1.md', "Hallo {{name}}, hier {{schema}}.\n\n");
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->verzeichnis . '/*') ?: []);
        rmdir($this->verzeichnis);
    }

    public function testPlaceholdersAreFilledAtOnce(): void
    {
        $text = new KiPrompts($this->verzeichnis)->text('test-v1', ['name' => 'Modell {{schema}}', 'schema' => '{"a":{"b":1}}']);

        self::assertSame('Hallo Modell {{schema}}, hier {"a":{"b":1}}.', $text, 'braces inside a value are no placeholder');
    }

    public function testAMissingValueFails(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('{{schema}}');

        new KiPrompts($this->verzeichnis)->text('test-v1', ['name' => 'x']);
    }

    public function testAValueWithoutPlaceholderFails(): void
    {
        $this->expectException(\LogicException::class);

        new KiPrompts($this->verzeichnis)->text('test-v1', ['name' => 'x', 'schema' => 'y', 'fehler' => 'z']);
    }

    public function testAMissingFileFails(): void
    {
        $this->expectException(\LogicException::class);

        new KiPrompts($this->verzeichnis)->text('gibt-es-nicht');
    }

    public function testNoPathOutsideTheDirectory(): void
    {
        $this->expectException(\LogicException::class);

        new KiPrompts($this->verzeichnis)->text('../test-v1');
    }

    public function testEveryShippedPromptAndSchemaLoads(): void
    {
        $verzeichnis = new Paths(dirname(__DIR__, 3))->promptsDir();
        $prompts = new KiPrompts($verzeichnis);
        $dateien = glob($verzeichnis . '/*') ?: [];

        self::assertNotSame([], $dateien);
        foreach ($dateien as $datei) {
            $name = basename($datei);
            if (str_ends_with($name, '.schema.json')) {
                self::assertSame(basename($name, '.schema.json'), $prompts->schema(basename($name, '.schema.json'))->name);

                continue;
            }
            self::assertStringEndsWith('.md', $name);
            preg_match_all('/\{\{([a-z_]+)\}\}/', (string) file_get_contents($datei), $treffer);
            $werte = array_fill_keys(array_unique($treffer[1]), 'x');
            self::assertStringNotContainsString('{{', $prompts->text(basename($name, '.md'), $werte));
        }
    }
}
