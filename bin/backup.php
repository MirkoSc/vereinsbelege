<?php

declare(strict_types=1);

use App\Config\Config;
use App\Config\Paths;
use App\Database\ConnectionFactory;
use App\Service\Backup\BackupService;
use App\Support\Version;

// Dev/CLI entry point only. There is no admin page for backups before the
// login of M3 - an open download of the whole dump would be worse than none -
// so this is how a backup is made by hand until then. The update chain
// creates one on its own before every switch.
//
//   php bin/backup.php [--mit-config]
//
// --mit-config puts shared/config.php into the ZIP. It holds the server key
// (CLAUDE.md section 4): only do that for a ZIP that stays somewhere safe.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

ini_set('zend.exception_ignore_args', '1');
ini_set('zend.exception_string_param_max_len', '0');
error_reporting(E_ALL);
date_default_timezone_set('Europe/Berlin');

$releaseRoot = dirname(__DIR__);

require $releaseRoot . '/vendor/autoload.php';

$paths = new Paths($releaseRoot);
$mitConfig = in_array('--mit-config', $argv, true);

try {
    $config = Config::fromFile(getenv('APP_CONFIG_FILE') ?: $paths->configFile());
    $version = Version::fromFile($paths->versionFile());

    $name = new BackupService(
        ConnectionFactory::create($config),
        $paths->backupDir(),
        $paths->configFile(),
        $version->value,
    )->create($mitConfig);

    echo sprintf("Backup created: %s/%s%s\n", $paths->backupDir(), $name, $mitConfig ? ' (with config.php)' : '');
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup failed: ' . $e->getMessage() . "\n");
    exit(1);
}
