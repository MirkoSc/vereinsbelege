<?php

declare(strict_types=1);

namespace App\Tests\Worker;

use App\Tests\Worker\Support\FakeWerkzeuge;
use App\Worker\Abbruch;
use App\Worker\Programm;
use PHPUnit\Framework\TestCase;

/**
 * The commands of bin/worker.php (docs/spec/07-worker.md section 8).
 */
final class ProgrammTest extends TestCase
{
    public function testCapabilitiesFollowTheToolsAndTheOperatorsLimit(): void
    {
        $werkzeuge = new FakeWerkzeuge();
        self::assertSame(['ocr', 'render', 'ai'], Programm::faehigkeiten($werkzeuge, null));
        self::assertSame(['render', 'ai'], Programm::faehigkeiten($werkzeuge, ' render , AI '));

        $werkzeuge->ocrDa = false;
        $werkzeuge->rendern = false;
        self::assertSame(['ai'], Programm::faehigkeiten($werkzeuge, ''));
        self::assertSame([], Programm::faehigkeiten($werkzeuge, 'ocr'), 'nothing is reported that the tools cannot do');
    }

    public function testPairingWithAPlainHttpAddressIsRefused(): void
    {
        $daten = sys_get_temp_dir() . '/vb_programm_' . bin2hex(random_bytes(6));
        $ausgabe = '';

        $code = Programm::main(['worker.php', 'pair', 'http://belege.verein.de', 'ABCD-EFGH'], ['WORKER_DATEN' => $daten], new Abbruch(), static function (string $text) use (&$ausgabe): void {
            $ausgabe .= $text;
        });

        self::assertSame(1, $code);
        self::assertStringContainsString('https://', $ausgabe);
        self::assertDirectoryDoesNotExist($daten);
    }

    public function testStatusWithoutPairing(): void
    {
        $ausgabe = '';

        $code = Programm::main(['worker.php', 'status'], ['WORKER_DATEN' => sys_get_temp_dir() . '/vb_leer_' . bin2hex(random_bytes(6))], new Abbruch(), static function (string $text) use (&$ausgabe): void {
            $ausgabe .= $text;
        });

        self::assertSame(1, $code);
        self::assertSame("Nicht gekoppelt.\n", $ausgabe);
    }

    public function testAnUnknownCommandShowsTheHelp(): void
    {
        $ausgabe = '';

        self::assertSame(2, Programm::main(['worker.php', 'hilfe'], [], new Abbruch(), static function (string $text) use (&$ausgabe): void {
            $ausgabe .= $text;
        }));
        self::assertStringContainsString('worker pair', $ausgabe);
    }
}
