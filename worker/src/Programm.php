<?php

declare(strict_types=1);

namespace App\Worker;

use App\Service\Ki\KiAbfrage;
use App\Service\Ki\KiPrompts;
use App\Service\Ki\OpenAiCompatibleClient;
use App\Service\Processing\KiAuslese\Auslesung;
use App\Worker\Http\CurlWorkerHttp;
use App\Worker\Http\KiHttp;
use App\Worker\Schritt\KiAuslesen;
use App\Worker\Schritt\Ocr;
use App\Worker\Schritt\SeitenRendern;

/**
 * The commands of bin/worker.php (docs/spec/07-worker.md sections 4 and 7):
 *
 *   pair <url> <code> [name]   couple with the web host (once)
 *   run                        the polling loop (default)
 *   status                     id, address and fingerprint of this worker
 *
 * Configured by environment variables (the container's compose file, #55):
 *   WORKER_DATEN      directory of the identity file (volume, 0700)  [/daten]
 *   WORKER_ARBEIT     working directory, MUST be a tmpfs             [<tmp>/vereinsbelege-worker]
 *   WORKER_NAME       name shown in the admin                        [host name]
 *   WORKER_FAEHIGKEITEN  limit what is reported, e.g. "ocr,render"   [all the tools allow]
 *   WORKER_ERLAUBE_HTTP_LOCALHOST=1  tests only: http:// to localhost
 */
final class Programm
{
    /**
     * @param list<string> $argv
     * @param array<string, string> $env
     * @param \Closure(string): void $ausgabe console output for the operator
     */
    public static function main(array $argv, array $env, Abbruch $abbruch, \Closure $ausgabe): int
    {
        $befehl = $argv[1] ?? 'run';
        $daten = rtrim($env['WORKER_DATEN'] ?? '/daten', '/');
        $datei = $daten . '/worker.json';
        $httpLokal = ($env['WORKER_ERLAUBE_HTTP_LOCALHOST'] ?? '') === '1';
        $version = self::version();
        $http = new CurlWorkerHttp($abbruch, $httpLokal, 'Vereinsbelege-Worker/' . $version);

        try {
            return match ($befehl) {
                'pair' => self::koppeln($http, $argv, $env, $datei, $version, $httpLokal, $ausgabe),
                'status' => self::status($datei, $ausgabe),
                'run' => self::laufen($http, $env, $datei, $version, $httpLokal, $abbruch),
                default => self::hilfe($ausgabe),
            };
        } catch (\InvalidArgumentException | ApiFehler $e) {
            $ausgabe('Fehler: ' . $e->getMessage() . "\n");

            return 1;
        } catch (Http\HttpFehler $e) {
            $ausgabe('Webhoster nicht erreichbar: ' . $e->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * What the worker reports: `ai` always (the keys come from the web
     * host), `render`/`ocr` when the tools are there - narrowed by
     * WORKER_FAEHIGKEITEN.
     *
     * @return list<string>
     */
    public static function faehigkeiten(Werkzeuge $werkzeuge, ?string $vorgabe): array
    {
        $alle = array_values(array_filter([
            $werkzeuge->kannOcr() ? 'ocr' : null,
            $werkzeuge->kannRendern() ? 'render' : null,
            'ai',
        ]));
        if ($vorgabe === null || trim($vorgabe) === '') {
            return $alle;
        }
        $gewuenscht = array_map(trim(...), explode(',', strtolower($vorgabe)));

        return array_values(array_intersect($alle, $gewuenscht));
    }

    /**
     * @param list<string> $argv
     * @param array<string, string> $env
     * @param \Closure(string): void $ausgabe
     */
    private static function koppeln(CurlWorkerHttp $http, array $argv, array $env, string $datei, string $version, bool $httpLokal, \Closure $ausgabe): int
    {
        $url = $argv[2] ?? '';
        $code = $argv[3] ?? '';
        if ($url === '' || $code === '') {
            return self::hilfe($ausgabe);
        }
        if (Identitaet::laden($datei) !== null && !in_array('--neu', $argv, true)) {
            $ausgabe("Dieser Worker ist bereits gekoppelt. Neu koppeln mit --neu (nach dem Entkoppeln im Admin).\n");

            return 1;
        }
        $name = $argv[4] ?? ($env['WORKER_NAME'] ?? (gethostname() ?: 'Worker'));
        if ($name === '--neu') {
            $name = $env['WORKER_NAME'] ?? (gethostname() ?: 'Worker');
        }
        $identitaet = Kopplung::koppeln($http, $url, $code, $name, $version, $datei, $httpLokal);
        $ausgabe(sprintf(
            "Gekoppelt als Worker %d mit %s.\nFingerabdruck: %s\nBitte im Admin unter „Worker“ vergleichen und bestätigen.\n",
            $identitaet->workerId,
            $identitaet->basisUrl,
            $identitaet->fingerabdruck(),
        ));

        return 0;
    }

    /** @param \Closure(string): void $ausgabe */
    private static function status(string $datei, \Closure $ausgabe): int
    {
        $identitaet = Identitaet::laden($datei);
        if ($identitaet === null) {
            $ausgabe("Nicht gekoppelt.\n");

            return 1;
        }
        $ausgabe(sprintf("Worker %d, %s\nFingerabdruck: %s\n", $identitaet->workerId, $identitaet->basisUrl, $identitaet->fingerabdruck()));

        return 0;
    }

    /** @param array<string, string> $env */
    private static function laufen(CurlWorkerHttp $http, array $env, string $datei, string $version, bool $httpLokal, Abbruch $abbruch): int
    {
        $log = new Log();
        $identitaet = Identitaet::laden($datei);
        if ($identitaet === null) {
            $log->ereignis('nicht_gekoppelt');
            fwrite(STDERR, "Nicht gekoppelt: zuerst `worker pair <url> <code>`.\n");

            return 1;
        }
        $arbeit = Arbeitsverzeichnis::ausSystem($env['WORKER_ARBEIT'] ?? sys_get_temp_dir() . '/vereinsbelege-worker');
        $uhr = static fn(): int => time();
        $schlafen = $abbruch->schlafen(...);
        $api = new ApiClient($http, $identitaet, $abbruch, $uhr, $schlafen, $httpLokal);
        $werkzeuge = new PosixWerkzeuge();
        $prompts = new KiPrompts(dirname(__DIR__, 2) . '/app/prompts');
        $ki = new KiKonfiguration();

        (new Schleife(
            $api,
            $identitaet,
            $ki,
            $arbeit,
            [
                new SeitenRendern($werkzeuge),
                new Ocr($werkzeuge),
                new KiAuslesen($ki, new Auslesung($prompts), new KiAbfrage(new OpenAiCompatibleClient(new KiHttp($http), $prompts), $prompts), $werkzeuge, $identitaet),
            ],
            self::faehigkeiten($werkzeuge, $env['WORKER_FAEHIGKEITEN'] ?? null),
            $version,
            $abbruch,
            $log,
            $uhr,
            $schlafen,
        ))->laufen();

        return 0;
    }

    /** @param \Closure(string): void $ausgabe */
    private static function hilfe(\Closure $ausgabe): int
    {
        $ausgabe(<<<'TEXT'
            Vereinsbelege-Worker
              worker pair <https://adresse> <KOPPLUNGSCODE> [name] [--neu]
              worker run
              worker status

            TEXT);

        return 2;
    }

    private static function version(): string
    {
        $version = trim((string) @file_get_contents(dirname(__DIR__, 2) . '/VERSION'));

        return preg_match('/^[0-9A-Za-z.+-]{1,32}$/', $version) === 1 ? $version : '0.0.0';
    }
}
