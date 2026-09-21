<?php

declare(strict_types=1);

use App\Config\Config;
use App\Config\Paths;
use App\Http\Kernel;
use App\Http\Router;
use App\Http\StaticFileHandler;
use App\Support\FileLogger;
use App\Support\Version;
use App\View\View;

// -------------------------------------------------------------------------
// Issue #97 - FIRST statement of the application, before an autoloader, a
// config file or a single line of our own code can throw.
//
// The target host ships zend.exception_ignore_args = 0 (hosting finding M0),
// which puts every function's ARGUMENTS into stack traces. On this
// application that is not a cosmetic difference: the login path carries a
// plaintext password, the unlock path the vault key, and the processing
// chain decrypted receipt data - all as call arguments, all into any trace
// that gets rendered or logged. CLAUDE.md section 4 requires it on.
//
// The value is PHP_INI_ALL, so setting it here actually takes effect
// (verified on the host by the M0 check, not assumed). Two more layers sit
// around it: web/.user.ini covers the window before this file runs, and
// Http\Kernel never logs the full exception string regardless.
// -------------------------------------------------------------------------
ini_set('zend.exception_ignore_args', '1');

// The ONLY place for global runtime setup (timezone convention: everything
// is stored and interpreted as Europe/Berlin, CLAUDE.md section 5).
error_reporting(E_ALL);
date_default_timezone_set('Europe/Berlin');

$releaseRoot = dirname(__DIR__, 2);

require $releaseRoot . '/vendor/autoload.php';

$paths = new Paths($releaseRoot);
$version = Version::fromFile($paths->versionFile());
$configFile = getenv('APP_CONFIG_FILE') ?: $paths->configFile();
$logger = new FileLogger($paths->logFile());

$view = new View($paths->viewsDir(), $version->value);

// Install mode: while shared/config.php is missing there is no database and
// no vault, so nothing but the installer can run. The installer itself is
// milestone M1-2; until then this is an honest "not set up yet" page rather
// than a stack of exceptions from Config::fromFile().
if (!is_file($configFile)) {
    $router = new Router();
    $router->get('/{rest:.*}', static fn(): \App\Http\Response => \App\Http\Response::html(
        $view->render('error', [
            'title' => 'Noch nicht eingerichtet',
            'message' => 'Es gibt noch keine Konfiguration. Die Einrichtung folgt mit dem Installer.',
        ]),
        503,
    ));

    return new Kernel(
        router: $router,
        staticFiles: new StaticFileHandler($paths->publicDir(), longCache: false),
        view: $view,
        // NOT debug mode, even here: with debug on, an unhandled exception
        // renders the full exception string into the browser, and on the
        // install path that means the database password the user just typed.
        debug: false,
        logger: $logger,
    );
}

$config = Config::fromFile($configFile);

$router = new Router();
(require __DIR__ . '/routes.php')($router, $view);

// No PDO connection here: ConnectionFactory opens one lazily when a route
// actually needs the database (and reopens it after a long external call,
// see issue #98).
return new Kernel(
    router: $router,
    staticFiles: new StaticFileHandler($paths->publicDir(), longCache: !$version->isDev()),
    view: $view,
    debug: $config->debug,
    logger: $logger,
);
