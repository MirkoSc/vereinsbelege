<?php

declare(strict_types=1);

use App\Config\Config;
use App\Config\Paths;
use App\Database\ConnectionFactory;
use App\Service\Backup\BackupCliOptions;
use App\Service\Backup\BackupService;
use App\Support\Version;

// Dev/CLI entry point only. There is no admin page for backups before the
// login of M3 - an open download of the whole dump would be worse than none -
// so this is how a backup is made by hand until then. The update chain
// creates one on its own before every switch.
//
//   php bin/backup.php [--mit-config] [--ohne-blobs]
//
// --mit-config puts shared/config.php into the ZIP. It holds the server key
// (CLAUDE.md section 4): only do that for a ZIP that stays somewhere safe.
// Without it the backup restores into an installation nobody can log in to
// until an admin re-links their account with the recovery key (issue #154,
// docs/spec/06-betrieb.md section 2) - the script says so every time.
//
// The encrypted blobs of storage backend `fs` travel by default; --ohne-blobs
// leaves them out for a quick schema-only snapshot. The chunks of backend
// `db` are in the dump either way.
//
// Options are parsed strictly (App\Service\Backup\BackupCliOptions, issue
// #155): an unknown option is an error, `--help` shows this instead of
// creating a backup.
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

try {
    $optionen = BackupCliOptions::parse($argv);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n\n" . BackupCliOptions::USAGE . "\n");
    exit(2);
}
if ($optionen->hilfe) {
    echo BackupCliOptions::USAGE . "\n";
    exit(0);
}

$paths = new Paths($releaseRoot);

try {
    $config = Config::fromFile(getenv('APP_CONFIG_FILE') ?: $paths->configFile());
    $version = Version::fromFile($paths->versionFile());

    $name = new BackupService(
        ConnectionFactory::create($config),
        $paths->backupDir(),
        $paths->configFile(),
        $version->value,
        $paths->blobDir(),
    )->create($optionen->mitConfig, $optionen->mitBlobs);

    echo sprintf(
        "Backup created: %s/%s%s%s\n",
        $paths->backupDir(),
        $name,
        $optionen->mitConfig ? ' (with config.php)' : '',
        $optionen->mitBlobs ? '' : ' (without blobs)',
    );
    if (!$optionen->mitConfig) {
        fwrite(STDERR, BackupService::WARNUNG_OHNE_CONFIG . "\n");
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup failed: ' . $e->getMessage() . "\n");
    exit(1);
}
