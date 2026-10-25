<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * The ONE place in the code base that starts another program
 * (docs/spec/07-worker.md section 8, E-50). The web host never starts one
 * (CLAUDE.md section 1); the worker runs in its own container
 * (Raspberry Pi/VPS) and needs Poppler and OCRmyPDF for its jobs.
 *
 * Bounded on purpose, checked by tests/Support/WorkerProzessGrenzeTest.php:
 *   - lives in worker/src/, which is not part of the release ZIP;
 *   - proc_open() with an argument LIST, never a command string - no shell
 *     is involved, nothing in an argument can become a second command;
 *   - stdin is /dev/null, the environment is reduced to PATH, HOME, TMPDIR
 *     and LANG (HOME and TMPDIR point into the job's tmpfs directory);
 *   - refuses to run outside the CLI.
 *
 * stdout and stderr are kept in memory only (capped) and never logged:
 * an OCR tool may print text of the receipt.
 */
final class Prozess
{
    /** Bytes kept of stdout and of stderr each. */
    public const int MAX_AUSGABE = 8 * 1024 * 1024;

    /** How long a child gets after SIGTERM before SIGKILL. */
    private const int GNADENFRIST_SEKUNDEN = 5;

    private function __construct()
    {
    }

    /**
     * Runs $befehl (program and arguments) in $verzeichnis and waits for it.
     * $puls is called about once a second while it runs - it may throw
     * (shutdown requested, lock lost); the child is then terminated and
     * the exception passed on.
     *
     * @param non-empty-list<string> $befehl
     * @param ?\Closure(): void $puls
     *
     * @throws ProzessFehler when the program cannot be started or runs out of time
     */
    public static function ausfuehren(array $befehl, string $verzeichnis, int $zeitlimitSekunden, ?\Closure $puls = null): ProzessErgebnis
    {
        if (PHP_SAPI !== 'cli') {
            throw new ProzessFehler('Programme startet nur der Worker auf der Kommandozeile.');
        }
        if ($befehl === [] || !is_dir($verzeichnis)) {
            throw new ProzessFehler('Ungültiger Programmaufruf.');
        }

        $pipes = [];
        $prozess = proc_open(
            array_values($befehl),
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $verzeichnis,
            [
                'PATH' => (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
                'HOME' => $verzeichnis,
                'TMPDIR' => $verzeichnis,
                'LANG' => 'C.UTF-8',
            ],
        );
        if (!is_resource($prozess)) {
            throw new ProzessFehler('Programm konnte nicht gestartet werden: ' . basename($befehl[0]));
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $ausgabe = ['', ''];
        $ende = time() + max(1, $zeitlimitSekunden);
        $exitCode = null;
        try {
            while (true) {
                $lesen = [$pipes[1], $pipes[2]];
                $schreiben = null;
                $fehler = null;
                if (@stream_select($lesen, $schreiben, $fehler, 1) !== false) {
                    foreach ($lesen as $pipe) {
                        $index = $pipe === $pipes[1] ? 0 : 1;
                        $stueck = fread($pipe, 65536);
                        if (is_string($stueck) && strlen($ausgabe[$index]) < self::MAX_AUSGABE) {
                            $ausgabe[$index] .= substr($stueck, 0, self::MAX_AUSGABE - strlen($ausgabe[$index]));
                        }
                    }
                }
                if (feof($pipes[1]) && feof($pipes[2])) {
                    // Both closed, the exit is due: no busy loop until then.
                    usleep(50_000);
                }
                $status = proc_get_status($prozess);
                if (!$status['running']) {
                    $exitCode = $status['exitcode'];
                    break;
                }
                if (time() > $ende) {
                    throw new ProzessFehler('Zeitlimit überschritten: ' . basename($befehl[0]));
                }
                if ($puls !== null) {
                    $puls();
                }
            }
            // Whatever is still buffered after the exit.
            foreach ([1, 2] as $nr) {
                $rest = stream_get_contents($pipes[$nr]);
                if (is_string($rest) && strlen($ausgabe[$nr - 1]) < self::MAX_AUSGABE) {
                    $ausgabe[$nr - 1] .= substr($rest, 0, self::MAX_AUSGABE - strlen($ausgabe[$nr - 1]));
                }
            }
        } finally {
            if ($exitCode === null) {
                self::beenden($prozess);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($prozess);
        }

        return new ProzessErgebnis($exitCode, $ausgabe[0], $ausgabe[1]);
    }

    /**
     * The absolute path of $programm on PATH, or null.
     */
    public static function finde(string $programm): ?string
    {
        if (preg_match('/^[A-Za-z0-9._-]+$/', $programm) !== 1) {
            return null;
        }
        foreach (explode(':', (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin')) as $verzeichnis) {
            $pfad = rtrim($verzeichnis, '/') . '/' . $programm;
            if ($verzeichnis !== '' && is_file($pfad) && is_executable($pfad)) {
                return $pfad;
            }
        }

        return null;
    }

    /** @param resource $prozess */
    private static function beenden($prozess): void
    {
        proc_terminate($prozess, 15);
        $frist = time() + self::GNADENFRIST_SEKUNDEN;
        while (time() <= $frist && proc_get_status($prozess)['running']) {
            usleep(100_000);
        }
        if (proc_get_status($prozess)['running']) {
            proc_terminate($prozess, 9);
        }
    }
}
