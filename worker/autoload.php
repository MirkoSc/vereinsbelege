<?php

declare(strict_types=1);

/**
 * The worker program's own autoloader (docs/spec/07-worker.md section 8):
 * App\Worker\ lives in worker/src/, which is not part of the release ZIP.
 * Deliberately not in composer.json - the web host's Composer classmap must
 * not even name these classes. Loaded by bin/worker.php and the tests, after
 * vendor/autoload.php.
 */
spl_autoload_register(static function (string $klasse): void {
    $praefix = 'App\\Worker\\';
    if (!str_starts_with($klasse, $praefix)) {
        return;
    }
    $datei = __DIR__ . '/src/' . str_replace('\\', '/', substr($klasse, strlen($praefix))) . '.php';
    if (is_file($datei)) {
        require $datei;
    }
});
