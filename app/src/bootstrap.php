<?php

declare(strict_types=1);

use App\Admin\MailController;
use App\Admin\StorageController;
use App\Admin\UpdateController;
use App\Api\CronController;
use App\Api\UploadController;
use App\Config\Config;
use App\Config\Paths;
use App\Database\ConnectionFactory;
use App\Http\Kernel;
use App\Http\Router;
use App\Http\Session;
use App\Http\StaticFileHandler;
use App\Installer\InstallController;
use App\Repository\CronLockRepository;
use App\Repository\BlobRepository;
use App\Repository\JobRepository;
use App\Repository\MailQueueRepository;
use App\Repository\SettingRepository;
use App\Repository\VaultRepository;
use App\Service\Backup\BackupService;
use App\Service\Crypto\ServerCrypto;
use App\Service\Cron\CronRunner;
use App\Service\Cron\JobCleanupTask;
use App\Service\Cron\MailCleanupTask;
use App\Service\Cron\MailQueueTask;
use App\Service\Cron\UploadCleanupTask;
use App\Service\Mail\Mailer;
use App\Service\Mail\MailSettingsRepository;
use App\Service\Mail\MailTemplates;
use App\Service\MaintenanceMode;
use App\Service\Migration\Migrator;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Service\Storage\StorageSwitchService;
use App\Service\Update\ReleaseDownloader;
use App\Service\Update\ReleaseSwitcher;
use App\Service\Update\UpdateService;
use App\Service\Upload\UploadService;
use App\Service\Upload\UploadStore;
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
// Belt to those braces: even where arguments ARE kept - a host that
// ignores the above, or an extension that reads the trace differently -
// this caps every string parameter to '...'. php.ini-production sets it
// to 0 for the same reason; the host does not.
ini_set('zend.exception_string_param_max_len', '0');

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
// no vault, so nothing but the installer can run. Writing the config is the
// last thing the installer does - that is what locks /install afterwards.
if (!is_file($configFile)) {
    $installer = new InstallController($view, $paths, new Session());

    $router = new Router();
    $router->get('/install', $installer->form(...));
    $router->post('/install', $installer->submit(...));
    $router->post('/install/wiederherstellen', $installer->restoreStep(...));
    // Registered last: the router takes the first matching route, and this
    // one matches everything.
    $router->get('/{rest:.*}', static fn(): \App\Http\Response => \App\Http\Response::redirect('/install'));

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

// One factory per collaborator that needs the database, called only when a
// route actually uses it: ConnectionFactory opens the connection lazily, and
// the public pages must not pay for one (see also issue #98 - a connection
// held open across a long external call dies on this host).
$connections = new ConnectionFactory($config);
$maintenance = new MaintenanceMode($paths->maintenanceFlagFile());

// Server key (level 1, CLAUDE.md section 4): needs no database, so it is
// built once here and shared by everything that reads or writes operating
// data - from M3-1 on that includes the mail queue and its settings.
$serverCrypto = new ServerCrypto($config->serverKey);

// Mail (docs/spec/06-betrieb.md section 3, issue #14): one small factory per
// collaborator, shared between the admin page and the cron task so both
// build the exact same Mailer stack from a given connection.
$mailQueueFor = static fn(\PDO $pdo): MailQueueRepository => new MailQueueRepository($pdo, $serverCrypto);
$mailSettingsFor = static fn(\PDO $pdo): MailSettingsRepository => new MailSettingsRepository(
    new SettingRepository($pdo),
    $serverCrypto,
);
$mailerFor = static function (\PDO $pdo) use ($mailQueueFor, $mailSettingsFor, $paths): Mailer {
    return new Mailer($mailQueueFor($pdo), $mailSettingsFor($pdo), new MailTemplates($paths->viewsDir() . '/mail'));
};

$updates = static fn(): UpdateController => new UpdateController(
    $view,
    new Session(),
    new UpdateService(
        paths: $paths,
        currentVersion: $version->value,
        settings: new SettingRepository($connections->pdo()),
        downloader: new ReleaseDownloader(),
        switcher: new ReleaseSwitcher(dirname($paths->releaseRoot), $maintenance),
        migrator: new Migrator($connections->pdo(), $paths->migrationsDir()),
        backups: new BackupService(
            $connections->pdo(),
            $paths->backupDir(),
            $paths->configFile(),
            $version->value,
            $paths->blobDir(),
        ),
    ),
    $maintenance,
);

// Cron: like $updates, built only once the token was right.
$cron = static fn(): CronController => new CronController($config, static function () use ($connections, $logger, $paths, $mailQueueFor, $mailerFor): CronRunner {
    $pdo = $connections->pdo();

    return new CronRunner(
        lock: new CronLockRepository($pdo),
        settings: new SettingRepository($pdo),
        // The mail queue (M3-1): nothing that decrypts, ever (CLAUDE.md
        // section 4) - Mailer reads and writes only server-key ciphertext.
        jedesMal: [new MailQueueTask($mailerFor($pdo))],
        aufraeumen: [
            new JobCleanupTask(new JobRepository($pdo)),
            new UploadCleanupTask(new UploadService($paths->uploadDir())),
            new MailCleanupTask($mailQueueFor($pdo)),
        ],
        logger: $logger,
    );
});

// Chunk upload (docs/spec/03-erfassung-und-ki.md section 4). The service
// itself only needs a directory, so opening, storing and aborting an upload
// costs no database connection; only the closing request builds the store.
$uploads = static fn(): UploadController => new UploadController(
    new Session(),
    new UploadService($paths->uploadDir()),
    static function () use ($connections, $paths): UploadStore {
        $pdo = $connections->pdo();
        $blobs = new BlobRepository($pdo);

        return new UploadStore(
            new BlobService($blobs, new DbBlobBackend($blobs), new FsBlobBackend($paths->blobDir())),
            new VaultRepository($pdo),
            new SettingRepository($pdo),
        );
    },
    logger: $logger,
);

// Storage administration (issue #12): the backend for new blobs and the step
// chain that carries the existing ones over. Built lazily like $updates - it
// needs the database, the public pages must not pay for that.
$storage = static function () use ($connections, $paths, $view): StorageController {
    $pdo = $connections->pdo();
    $blobs = new BlobRepository($pdo);

    return new StorageController(
        $view,
        new Session(),
        new StorageSwitchService(
            $blobs,
            new BlobService($blobs, new DbBlobBackend($blobs), new FsBlobBackend($paths->blobDir())),
            new SettingRepository($pdo),
        ),
    );
};

// Mail admin page (issue #14): built lazily like $storage - it needs the
// database, the public pages must not pay for that.
$mail = static function () use ($connections, $view, $mailSettingsFor, $mailerFor, $mailQueueFor): MailController {
    $pdo = $connections->pdo();

    return new MailController(
        $view,
        new Session(),
        $mailSettingsFor($pdo),
        $mailerFor($pdo),
        $mailQueueFor($pdo),
    );
};

$router = new Router();
(require __DIR__ . '/routes.php')($router, $view, $updates, $cron, $uploads, $storage, $mail);

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
