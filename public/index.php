<?php

declare(strict_types=1);

use App\Http\Kernel;
use App\Http\Request;

// Issue #97, repeated here and not only in bootstrap.php: this file runs
// FIRST, and everything below - including the require of bootstrap.php - can
// throw before that file's own ini_set() is reached. A trace rendered or
// logged from the catch block must not carry call arguments either.
ini_set('zend.exception_ignore_args', '1');
// Belt to those braces: even where arguments ARE kept - a host that
// ignores the above, or an extension that reads the trace differently -
// this caps every string parameter to '...'. php.ini-production sets it
// to 0 for the same reason; the host does not.
ini_set('zend.exception_string_param_max_len', '0');

// Maintenance mode: set while the updater switches releases. Checked before
// bootstrap so it works even if the app is mid-switch; /admin stays reachable
// for the update step chain.
//
// The docroot shim performs the SAME check one level up, and that is the one
// that actually matters: between the two renames of a switch this file does
// not exist, so it cannot answer. This copy is kept because the shim only
// reaches an installation through the updater's self-healing - until that has
// run once, this is the only check there is.
$maintenanceFlag = dirname(__DIR__, 2) . '/shared/maintenance.flag';
$requestPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (is_file($maintenanceFlag) && !str_starts_with($requestPath, '/admin')) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Retry-After: 30');
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>Wartung</title></head>'
        . '<body><h1>Kurze Wartungspause</h1><p>Die Belegverwaltung wird gerade aktualisiert. '
        . 'Bitte in einer Minute erneut laden.</p></body></html>';
    exit;
}

// Bootstrap runs OUTSIDE the Kernel's own error handler, and things do fail
// there: a missing VERSION file, an unreadable config, a broken autoloader.
// The result would otherwise be a blank 500 with nothing written anywhere,
// which is exactly the blind spot shared/var/log/ is meant to close.
//
// Deliberately hand-rolled rather than using Support\FileLogger: the
// autoloader is what bootstrap.php loads first, so it may itself be the thing
// that failed. Same redaction rule as Http\Kernel - class, message, method,
// path, file, line, never the full exception string.
try {
    /** @var Kernel $kernel */
    $kernel = require dirname(__DIR__) . '/app/src/bootstrap.php';
    $kernel->handle(Request::fromGlobals())->send();
} catch (\Throwable $e) {
    $zeile = sprintf(
        '[%s] BOOTSTRAP %s: %s [%s %s] at %s:%d',
        date('c'),
        $e::class,
        $e->getMessage(),
        $_SERVER['REQUEST_METHOD'] ?? '-',
        $requestPath,
        $e->getFile(),
        $e->getLine(),
    );

    error_log($zeile);
    $logDatei = dirname(__DIR__, 2) . '/shared/var/log/app.log';
    if (is_dir(dirname($logDatei)) || @mkdir(dirname($logDatei), 0775, true) || is_dir(dirname($logDatei))) {
        @file_put_contents($logDatei, $zeile . "\n", FILE_APPEND | LOCK_EX);
    }

    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>Fehler</title></head>'
        . '<body><h1>Interner Fehler</h1><p>Die Belegverwaltung ist gerade nicht erreichbar. '
        . 'Bitte später erneut versuchen.</p></body></html>';
}
