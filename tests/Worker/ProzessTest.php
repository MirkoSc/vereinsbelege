<?php

declare(strict_types=1);

namespace App\Tests\Worker;

use App\Worker\Abgebrochen;
use App\Worker\Prozess;
use App\Worker\ProzessFehler;
use PHPUnit\Framework\TestCase;

/**
 * The worker's one way to start a program (docs/spec/07-worker.md section
 * 8). The local Docker environment of the web host forbids proc_open()
 * (docker/php/shared-hosting.ini) - there these tests have nothing to run;
 * CI and the worker container run them.
 */
final class ProzessTest extends TestCase
{
    private string $verzeichnis = '';

    protected function setUp(): void
    {
        if (in_array('proc_open', array_map(trim(...), explode(',', (string) ini_get('disable_functions'))), true)) {
            self::markTestSkipped('proc_open() ist in dieser Umgebung gesperrt (Webhoster-Nachbildung).');
        }
        $this->verzeichnis = sys_get_temp_dir() . '/vb_prozess_' . bin2hex(random_bytes(6));
        mkdir($this->verzeichnis, 0700);
    }

    protected function tearDown(): void
    {
        if ($this->verzeichnis !== '') {
            @rmdir($this->verzeichnis);
        }
    }

    public function testOutputAndExitCodeAreReturned(): void
    {
        $ergebnis = Prozess::ausfuehren([PHP_BINARY, '-r', 'echo getenv("HOME"), "|", getenv("SECRET") === false ? "-" : "x"; fwrite(STDERR, "e"); exit(3);'], $this->verzeichnis, 30);

        self::assertSame(3, $ergebnis->exitCode);
        self::assertSame($this->verzeichnis . '|-', $ergebnis->stdout, 'HOME is the job directory, nothing else of the environment passes');
        self::assertSame('e', $ergebnis->stderr);
    }

    public function testArgumentsAreNeverInterpretedByAShell(): void
    {
        $ergebnis = Prozess::ausfuehren([PHP_BINARY, '-r', 'echo $argv[1];', '; echo boom'], $this->verzeichnis, 30);

        self::assertSame('; echo boom', $ergebnis->stdout);
    }

    public function testATimeoutTerminatesTheProgram(): void
    {
        $this->expectException(ProzessFehler::class);

        Prozess::ausfuehren([PHP_BINARY, '-r', 'sleep(30);'], $this->verzeichnis, 1);
    }

    public function testThePulsCanStopTheProgram(): void
    {
        $start = microtime(true);
        try {
            Prozess::ausfuehren([PHP_BINARY, '-r', 'sleep(30);'], $this->verzeichnis, 60, static function (): never {
                throw new Abgebrochen('Beenden angefordert.');
            });
            self::fail('expected the stop');
        } catch (Abgebrochen) {
        }

        self::assertLessThan(10, microtime(true) - $start);
    }

    public function testFindLooksOnThePathOnly(): void
    {
        self::assertNull(Prozess::finde('../bin/sh'));
        self::assertNull(Prozess::finde('gibt-es-sicher-nicht-' . bin2hex(random_bytes(3))));
    }
}
