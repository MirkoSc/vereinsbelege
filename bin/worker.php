#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * The optional processing worker (docs/spec/07-worker.md, issue #54): runs
 * in its own container on a Raspberry Pi or VPS - never on the web host -,
 * polls the web host's `/api/worker/*` and processes `render_pages`, `ocr`
 * and `ai_extract` without a time limit. Code: worker/src/ (App\Worker,
 * not part of the release ZIP) plus the shared app/src.
 *
 *   php bin/worker.php pair https://belege.verein.de ABCD-EFGH
 *   php bin/worker.php run
 *   php bin/worker.php status
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/worker/autoload.php';

date_default_timezone_set('Europe/Berlin');
// As on the web host (CLAUDE.md section 4): no arguments in stack traces.
ini_set('zend.exception_ignore_args', '1');
ini_set('display_errors', 'stderr');
ini_set('memory_limit', getenv('WORKER_SPEICHER') ?: '1024M');

$abbruch = new App\Worker\Abbruch();
// Without pcntl a SIGTERM (docker stop, an update) would kill the loop
// without giving the running job back - refused instead of skipped
// silently (issue #55). pair/status need no signal handler.
if (!function_exists('pcntl_async_signals') && ($argv[1] ?? 'run') === 'run') {
    fwrite(STDERR, "Fehler: Die PHP-Erweiterung pcntl fehlt – ohne sie gibt der Worker beim Beenden den laufenden Job nicht zurück.\n");
    exit(1);
}
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $anhalten = static function () use ($abbruch): void {
        $abbruch->anfordern();
    };
    pcntl_signal(SIGTERM, $anhalten);
    pcntl_signal(SIGINT, $anhalten);
}

/** @var array<string, string> $env */
$env = array_filter(getenv(), is_string(...));

exit(App\Worker\Programm::main(
    array_values(array_map(strval(...), $argv)),
    $env,
    $abbruch,
    static function (string $text): void {
        fwrite(STDOUT, $text);
    },
));
