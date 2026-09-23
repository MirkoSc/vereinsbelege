<?php

declare(strict_types=1);

use App\Admin\MailController;
use App\Admin\RoleController;
use App\Admin\StorageController;
use App\Admin\UpdateController;
use App\Admin\UserController;
use App\Admin\VaultGrantController;
use App\Api\CronController;
use App\Api\UploadController;
use App\App\AuditController;
use App\App\AuthController;
use App\App\InvitationController;
use App\App\LoginCompleter;
use App\App\MfaController;
use App\App\MfaToolbox;
use App\App\PasswordController;
use App\App\PasswordToolbox;
use App\App\SecurityController;
use App\Config\Config;
use App\Config\Paths;
use App\Database\ConnectionFactory;
use App\Http\Kernel;
use App\Http\LoginGuard;
use App\Http\Router;
use App\Http\Session;
use App\Http\StaticFileHandler;
use App\Http\Zugriff;
use App\Installer\InstallController;
use App\Repository\AuditLogRepository;
use App\Repository\AuthTokenRepository;
use App\Repository\CostCenterRepository;
use App\Repository\CronLockRepository;
use App\Repository\BlobRepository;
use App\Repository\JobRepository;
use App\Repository\MailQueueRepository;
use App\Repository\MfaBackupCodeRepository;
use App\Repository\MfaEmailCodeRepository;
use App\Repository\MfaTotpRepository;
use App\Repository\RateLimitRepository;
use App\Repository\RoleRepository;
use App\Repository\SettingRepository;
use App\Repository\TrustedDeviceRepository;
use App\Repository\UserKeyRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Repository\VaultGrantRepository;
use App\Repository\VaultRepository;
use App\Service\Account\AccessAssignment;
use App\Service\Account\Invitation;
use App\Service\Account\LoginService;
use App\Service\Account\MfaEnrollment;
use App\Service\Account\MfaService;
use App\Service\Account\PasswordChange;
use App\Service\Account\PasswordHasher;
use App\Service\Account\PasswordPolicy;
use App\Service\Account\PasswordReset;
use App\Service\Account\PendingLogin;
use App\Service\Account\RoleService;
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\Service\Account\SessionVault;
use App\Service\Account\UserAdministration;
use App\Service\Audit\AuditLog;
use App\Service\Backup\BackupService;
use App\Service\Crypto\ServerCrypto;
use App\Service\Cron\CronRunner;
use App\Service\Cron\AuthTokenCleanupTask;
use App\Service\Cron\JobCleanupTask;
use App\Service\Cron\MailCleanupTask;
use App\Service\Cron\MailQueueTask;
use App\Service\Cron\RateLimitCleanupTask;
use App\Service\Cron\TrustedDeviceCleanupTask;
use App\Service\Cron\UploadCleanupTask;
use App\Service\Mail\FreigabeBenachrichtigung;
use App\Service\Mail\Mailer;
use App\Service\Mail\MailSettingsRepository;
use App\Service\Mail\MailTemplates;
use App\Service\MaintenanceMode;
use App\Service\Migration\Migrator;
use App\Service\RateLimiter;
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

    // Permission: public - there is no account yet. What locks /install is
    // the config file this chain writes last.
    $offen = Zugriff::oeffentlich();
    $router = new Router();
    $router->get('/install', $offen, $installer->form(...));
    $router->post('/install', $offen, $installer->submit(...));
    $router->post('/install/schluessel', $offen, $installer->confirmKey(...));
    $router->post('/install/neu', $offen, $installer->restart(...));
    $router->post('/install/wiederherstellen', $offen, $installer->restoreStep(...));
    // Registered last: the router takes the first matching route, and this
    // one matches everything.
    $router->get('/{rest:.*}', $offen, static fn(): \App\Http\Response => \App\Http\Response::redirect('/install'));

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

// Audit log (M3-8, issue #21, docs/spec/01-sicherheit.md section 6): every
// controller that changes an account, a right or a setting writes through
// the same small factory. Writing needs no vault - the details are sealed to
// its public key.
$auditFor = static fn(\PDO $pdo): AuditLog => new AuditLog(
    new AuditLogRepository($pdo),
    new VaultRepository($pdo),
    $serverCrypto,
);

// Second factor (M3-4, issue #17, docs/spec/01-sicherheit.md section 3):
// one small factory, shared by the login (trusted-device check only), the
// confirmation controller and the account's own security page.
$mfaServiceFor = static fn(\PDO $pdo): MfaService => new MfaService(
    new MfaTotpRepository($pdo),
    new MfaEmailCodeRepository($pdo),
    new MfaBackupCodeRepository($pdo),
    new TrustedDeviceRepository($pdo),
    $serverCrypto,
    new RateLimiter(new RateLimitRepository($pdo), MfaService::WINDOW_SECONDS),
);

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
    $auditFor($connections->pdo()),
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
            // M3-3: the login counters. Same window the login uses, so a
            // row is only ever swept once it can no longer block anybody.
            new RateLimitCleanupTask(
                new RateLimiter(new RateLimitRepository($pdo), RateLimiter::LOGIN_WINDOW_SECONDS),
            ),
            // M3-4: remembered devices whose 30 days are over.
            new TrustedDeviceCleanupTask(new TrustedDeviceRepository($pdo)),
            // M3-5: password reset links whose 30 minutes are over.
            new AuthTokenCleanupTask(new AuthTokenRepository($pdo)),
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
$storage = static function () use ($connections, $paths, $view, $auditFor): StorageController {
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
        $auditFor($pdo),
    );
};

// Mail admin page (issue #14): built lazily like $storage - it needs the
// database, the public pages must not pay for that.
$mail = static function () use ($connections, $view, $mailSettingsFor, $mailerFor, $mailQueueFor, $auditFor): MailController {
    $pdo = $connections->pdo();

    return new MailController(
        $view,
        new Session(),
        $mailSettingsFor($pdo),
        $mailerFor($pdo),
        $mailQueueFor($pdo),
        $auditFor($pdo),
    );
};

// Roles (M3-6, issue #19): plaintext operating data, no vault - only the
// role pages open the connection.
$rollen = static function () use ($connections, $view, $auditFor): RoleController {
    $pdo = $connections->pdo();
    $repository = new RoleRepository($pdo);

    return new RoleController($view, new Session(), $repository, new RoleService($repository), $auditFor($pdo));
};

// User management and vault grants (M3-7, issue #20). One small factory
// for the collaborators the admin pages, the invitation page and the reset
// share: the notice to the admins who can grant goes out from all three.
$verwaltungFor = static fn(\PDO $pdo): UserAdministration => new UserAdministration($pdo, $serverCrypto);
$freigabeHinweisFor = static fn(\PDO $pdo): FreigabeBenachrichtigung => new FreigabeBenachrichtigung(
    $mailerFor($pdo),
    $serverCrypto,
    $verwaltungFor($pdo),
);
$zuweisungFor = static fn(\PDO $pdo): AccessAssignment => new AccessAssignment(
    $pdo,
    new RoleRepository($pdo),
    new UserAccessRepository($pdo),
    new UserRepository($pdo),
);

$benutzer = static function () use ($connections, $view, $serverCrypto, $paths, $mailerFor, $mailSettingsFor, $logger, $verwaltungFor, $freigabeHinweisFor, $zuweisungFor, $auditFor): UserController {
    $pdo = $connections->pdo();

    return new UserController(
        $view,
        new Session(),
        new UserRepository($pdo),
        new RoleRepository($pdo),
        new UserAccessRepository($pdo),
        new AuthTokenRepository($pdo),
        new CostCenterRepository($pdo),
        new Invitation(
            $pdo,
            $serverCrypto,
            new PasswordHasher(),
            new PasswordPolicy($paths->dataDir() . '/haeufige-passwoerter.txt'),
            $zuweisungFor($pdo),
        ),
        $verwaltungFor($pdo),
        $zuweisungFor($pdo),
        $mailerFor($pdo),
        $mailSettingsFor($pdo),
        $serverCrypto,
        $freigabeHinweisFor($pdo),
        $auditFor($pdo),
        $logger,
    );
};

$tresor = static function () use ($connections, $view, $serverCrypto, $mailerFor, $verwaltungFor, $auditFor): VaultGrantController {
    $pdo = $connections->pdo();

    return new VaultGrantController(
        $view,
        new Session(),
        new SessionVault(),
        new UserRepository($pdo),
        new VaultRepository($pdo),
        new VaultGrantRepository($pdo),
        $verwaltungFor($pdo),
        $mailerFor($pdo),
        $serverCrypto,
        $auditFor($pdo),
    );
};

// Accepting an invitation: a public page, built lazily like $passwort.
$einladung = static function () use ($connections, $view, $serverCrypto, $paths, $mailSettingsFor, $freigabeHinweisFor, $zuweisungFor, $auditFor): InvitationController {
    $pdo = $connections->pdo();

    return new InvitationController(
        $view,
        new Session(),
        new Invitation(
            $pdo,
            $serverCrypto,
            new PasswordHasher(),
            new PasswordPolicy($paths->dataDir() . '/haeufige-passwoerter.txt'),
            $zuweisungFor($pdo),
        ),
        $freigabeHinweisFor($pdo),
        $mailSettingsFor($pdo),
        $auditFor($pdo),
    );
};

// Login and vault unlock (M3-3/M3-4, issues #16/#17). Built lazily like the
// rest: the login FORM needs no database, only the attempt behind it does -
// and the guard reads the timeout settings only once a protected route
// matched.
$auth = static fn(): AuthController => new AuthController(
    $view,
    new Session(),
    new SessionVault(),
    new PendingLogin(),
    new LoginCompleter(new Session(), new SessionVault()),
    static function () use ($connections, $serverCrypto): LoginService {
        $pdo = $connections->pdo();

        return new LoginService(
            new UserRepository($pdo),
            new UserKeyRepository($pdo),
            new VaultGrantRepository($pdo),
            new VaultRepository($pdo),
            $serverCrypto,
            new RateLimiter(new RateLimitRepository($pdo), RateLimiter::LOGIN_WINDOW_SECONDS),
            new PasswordHasher(),
        );
    },
    static fn(): MfaService => $mfaServiceFor($connections->pdo()),
    static fn(): AuditLog => $auditFor($connections->pdo()),
);

// The second factor's own confirmation controller (M3-4, issue #17): built
// lazily the same way, for the same reason - the confirmation FORM needs no
// database either.
$mfaController = static fn(): MfaController => new MfaController(
    $view,
    new Session(),
    new PendingLogin(),
    new LoginCompleter(new Session(), new SessionVault()),
    static function () use ($connections, $serverCrypto, $mfaServiceFor, $mailerFor, $auditFor): MfaToolbox {
        $pdo = $connections->pdo();

        return new MfaToolbox(
            $mfaServiceFor($pdo),
            new UserRepository($pdo),
            $mailerFor($pdo),
            new SettingRepository($pdo),
            $serverCrypto,
            $auditFor($pdo),
        );
    },
);

// Managing an already set-up (or not-yet-set-up) second factor (M3-4, issue
// #17). Built lazily like $storage/$mail below - every route here is
// already behind $guard, but the controller still should not open a
// connection before a matched route needs one.
$sicherheit = static function () use ($connections, $serverCrypto, $view, $mfaServiceFor, $mailerFor, $paths, $auditFor): SecurityController {
    $pdo = $connections->pdo();

    return new SecurityController(
        $view,
        new Session(),
        $mfaServiceFor($pdo),
        new MfaEnrollment(new MfaTotpRepository($pdo), new MfaBackupCodeRepository($pdo), new UserRepository($pdo), $serverCrypto),
        new UserRepository($pdo),
        $mailerFor($pdo),
        $serverCrypto,
        new PasswordChange(
            $pdo,
            new PasswordHasher(),
            new PasswordPolicy($paths->dataDir() . '/haeufige-passwoerter.txt'),
            new RateLimiter(new RateLimitRepository($pdo), RateLimiter::LOGIN_WINDOW_SECONDS),
        ),
        $auditFor($pdo),
    );
};

// "Passwort vergessen" (M3-5, issue #18): built lazily like $auth - the
// forms need no database, only a submitted form or a checked link does.
$passwort = static fn(): PasswordController => new PasswordController(
    $view,
    new Session(),
    new SessionVault(),
    static function () use ($connections, $serverCrypto, $paths, $mailerFor, $mailSettingsFor, $logger, $freigabeHinweisFor, $auditFor): PasswordToolbox {
        $pdo = $connections->pdo();

        return new PasswordToolbox(
            new PasswordReset(
                $pdo,
                $serverCrypto,
                new PasswordHasher(),
                new PasswordPolicy($paths->dataDir() . '/haeufige-passwoerter.txt'),
                new RateLimiter(new RateLimitRepository($pdo), RateLimiter::LOGIN_WINDOW_SECONDS),
            ),
            new UserRepository($pdo),
            $mailerFor($pdo),
            $mailSettingsFor($pdo),
            $serverCrypto,
            $auditFor($pdo),
            $logger,
            $freigabeHinweisFor($pdo),
        );
    },
);

// The audit log page (M3-8, issue #21): reads only; built lazily like the
// other pages.
$auditSeite = static function () use ($connections, $view, $serverCrypto, $auditFor): AuditController {
    $pdo = $connections->pdo();

    return new AuditController(
        $view,
        new Session(),
        new SessionVault(),
        new AuditLogRepository($pdo),
        $auditFor($pdo),
        new UserRepository($pdo),
        $serverCrypto,
    );
};

$guard = static fn(): LoginGuard => new LoginGuard(
    new Session(),
    $view,
    static fn(): SessionTimeouts => SessionTimeouts::fromSettings(new SettingRepository($connections->pdo())),
    static function (int $userId) use ($connections, $serverCrypto): ?SessionUser {
        $user = new UserRepository($connections->pdo())->findById($userId);

        return $user === null
            ? null
            : new SessionUser(
                $user,
                $serverCrypto->decrypt($user->displayNameEnc),
                new UserAccessRepository($connections->pdo())->berechtigungen($userId),
            );
    },
    // The banner "N Freigaben ausstehend" (M3-7, issue #20): one COUNT,
    // asked only for an account that may grant.
    static function () use ($connections): int {
        $vault = new VaultRepository($connections->pdo())->current();

        return $vault === null ? 0 : new VaultGrantRepository($connections->pdo())->countPending($vault->version, new \DateTimeImmutable());
    },
);

$router = new Router();
(require __DIR__ . '/routes.php')(
    $router,
    $view,
    $auth,
    $guard,
    $mfaController,
    $sicherheit,
    $updates,
    $cron,
    $uploads,
    $storage,
    $mail,
    $passwort,
    $rollen,
    $benutzer,
    $tresor,
    $einladung,
    $auditSeite,
);

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
