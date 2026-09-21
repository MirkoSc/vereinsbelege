<?php

declare(strict_types=1);

namespace App\Installer;

use App\Config\Paths;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\SettingRepository;
use App\Service\Backup\RestoreService;
use App\Service\Migration\Migrator;
use App\Service\Update\UpdateService;
use App\View\View;

/**
 * /install - reachable only while shared/config.php is missing (the
 * bootstrap registers these routes in install mode only). Takes the
 * database credentials, tests the connection and then either installs fresh
 * (every migration from 0) or restores an uploaded backup ZIP. Writing the
 * config is the last thing either path does, which closes the installer for
 * good.
 *
 * Steps 3 to 5 of the flow in docs/spec/06-betrieb.md section 1 - first
 * admin, vault plus recovery key, mail settings - need the crypto core and
 * the user management and arrive with milestones M2 and M3-2. Step 6, fresh
 * or restore, is here. The server key is created here too, because only the
 * installer can (ConfigWriter) - or taken over from a backup that carried
 * its config.php.
 *
 * A restore is a step chain of short requests (CLAUDE.md section 1): submit
 * stores the dump in shared/var/ and the progress in the session, the page's
 * JavaScript then calls restoreStep() until it reports "fertig".
 */
final readonly class InstallController
{
    public function __construct(
        private View $view,
        private Paths $paths,
        private Session $session,
        /**
         * Replaces is_uploaded_file() in tests: PHP only answers true for a
         * real multipart upload, which a test cannot produce.
         *
         * @var (\Closure(string): bool)|null
         */
        private ?\Closure $istUpload = null,
    ) {
    }

    public function form(Request $request): ResponseInterface
    {
        $this->session->start();

        return $this->render(['errors' => [], 'values' => ['kanal' => $this->channelFromSetup()]]);
    }

    public function submit(Request $request): ResponseInterface
    {
        $this->session->start();

        if (!$this->session->checkCsrf($request)) {
            return $this->render([
                'errors' => ['csrf' => 'Die Sitzung ist abgelaufen. Bitte das Formular erneut absenden.'],
                'values' => ['kanal' => $this->channelFromSetup()],
            ], 403);
        }

        $values = [
            'db_host' => trim((string) ($request->post['db_host'] ?? 'localhost')),
            'db_port' => trim((string) ($request->post['db_port'] ?? '3306')),
            'db_name' => trim((string) ($request->post['db_name'] ?? '')),
            'db_user' => trim((string) ($request->post['db_user'] ?? '')),
            'db_password' => (string) ($request->post['db_password'] ?? ''),
            'kanal' => ((string) ($request->post['kanal'] ?? '')) === 'beta' ? 'beta' : 'stable',
            'modus' => ((string) ($request->post['modus'] ?? '')) === 'restore' ? 'restore' : 'frisch',
        ];

        $errors = [];
        if ($values['db_host'] === '' || $values['db_name'] === '' || $values['db_user'] === '') {
            $errors['db'] = 'Bitte Host, Datenbankname und Benutzer angeben.';
        }

        $pdo = null;
        if ($errors === []) {
            try {
                $pdo = $this->connect($values);
            } catch (\PDOException $e) {
                // The PDO message names host and database, never the
                // password - and there is no business data here yet.
                $errors['db'] = 'Verbindung fehlgeschlagen: ' . $e->getMessage();
            }
        }

        $upload = null;
        if ($values['modus'] === 'restore' && $errors === []) {
            $upload = $this->uploadedBackup($request);
            if ($upload === null) {
                $errors['backup'] = 'Bitte ein Backup-ZIP hochladen.';
            }
        }

        if ($errors !== []) {
            return $this->render(['errors' => $errors, 'values' => $values], 422);
        }
        assert($pdo instanceof \PDO);

        if ($upload !== null) {
            return $this->startRestore($values, $upload);
        }

        // Fresh install: every migration from 0. The channel setting is
        // written before the config, so a failure here leaves the installer
        // open instead of a half-configured installation behind.
        new Migrator($pdo, $this->paths->migrationsDir())->migrate();
        new SettingRepository($pdo)->set(UpdateService::SETTING_CHANNEL, $values['kanal']);

        ConfigWriter::write($this->paths->configFile(), $this->dbConfig($values));

        $this->cleanUpSetupLeftovers();

        return $this->render(['fertig' => true, 'errors' => [], 'values' => []]);
    }

    /**
     * One block of the restore, called by install.js until "fertig". State
     * (credentials, offset) lives in the session, the dump in shared/var/.
     */
    public function restoreStep(Request $request): ResponseInterface
    {
        $this->session->start();

        if (!$this->session->checkCsrf($request)) {
            return Response::json(['fehler' => 'Sitzung abgelaufen - bitte die Seite neu laden.'], 403);
        }

        $stand = $_SESSION['install_restore'] ?? null;
        if (!is_array($stand) || !is_array($stand['values'] ?? null)) {
            return Response::json(['fehler' => 'Keine Wiederherstellung aktiv.'], 409);
        }
        /** @var array<string, string> $values */
        $values = $stand['values'];
        $dumpDatei = (string) ($stand['dump_datei'] ?? '');

        try {
            $pdo = $this->connect($values);
            $fortschritt = new RestoreService()->anwenden($pdo, $dumpDatei, (int) ($stand['offset'] ?? 0));
            $_SESSION['install_restore']['offset'] = $fortschritt['offset'];

            if ($fortschritt['offset'] < $fortschritt['gesamt']) {
                return Response::json(['fertig' => false, ...$fortschritt]);
            }

            // Dump imported: only migrations newer than the backup remain.
            $migriert = new Migrator($pdo, $this->paths->migrationsDir())->migrate();
            new SettingRepository($pdo)->set(UpdateService::SETTING_CHANNEL, $values['kanal']);

            $serverSchluessel = is_string($stand['server_key'] ?? null) ? $stand['server_key'] : null;
            ConfigWriter::write($this->paths->configFile(), $this->dbConfig($values), $serverSchluessel);

            @unlink($dumpDatei);
            unset($_SESSION['install_restore']);
            $this->cleanUpSetupLeftovers();

            return Response::json([
                'fertig' => true,
                'offset' => $fortschritt['gesamt'],
                'gesamt' => $fortschritt['gesamt'],
                'migrationen' => count($migriert->applied),
            ]);
        } catch (\PDOException $e) {
            // Deliberately not getMessage(): a failed INSERT quotes a part
            // of the statement, and that is the row data of the backup.
            return Response::json([
                'fehler' => sprintf(
                    'Datenbankfehler beim Einspielen (SQLSTATE %s, Code %s).',
                    (string) $e->getCode(),
                    (string) ($e->errorInfo[1] ?? '?'),
                ),
            ], 500);
        } catch (\Throwable $e) {
            return Response::json([
                'fehler' => $e instanceof \RuntimeException ? $e->getMessage() : 'Unerwarteter Fehler beim Einspielen.',
            ], 500);
        }
    }

    /**
     * Unpacks dump.sql into shared/var/ and hands the page over to
     * install.js.
     *
     * @param array<string, string> $values
     * @param array{tmp_name: string} $upload
     */
    private function startRestore(array $values, array $upload): ResponseInterface
    {
        $varDir = $this->paths->varDir();
        if (!is_dir($varDir)) {
            mkdir($varDir, 0775, true);
        }
        $dumpDatei = $varDir . '/install_restore_' . bin2hex(random_bytes(8)) . '.sql';

        $restore = new RestoreService();
        try {
            $restore->dumpAusZip($upload['tmp_name'], $dumpDatei);
            $serverSchluessel = $restore->serverSchluesselAusZip($upload['tmp_name']);
            $manifest = $restore->manifestAusZip($upload['tmp_name']);
        } catch (\RuntimeException $e) {
            @unlink($dumpDatei);

            return $this->render(['errors' => ['backup' => $e->getMessage()], 'values' => $values], 422);
        }

        $_SESSION['install_restore'] = [
            'values' => $values,
            'dump_datei' => $dumpDatei,
            'offset' => 0,
            'server_key' => $serverSchluessel,
        ];

        return $this->render([
            'restore' => true,
            'serverKeyUebernommen' => $serverSchluessel !== null,
            'backupVersion' => is_string($manifest['app_version'] ?? null) ? $manifest['app_version'] : null,
            'errors' => [],
            'values' => $values,
        ]);
    }

    /**
     * @return array{tmp_name: string}|null
     */
    private function uploadedBackup(Request $request): ?array
    {
        $datei = $request->files['backup'] ?? null;
        if (!is_array($datei) || ($datei['error'] ?? \UPLOAD_ERR_NO_FILE) !== \UPLOAD_ERR_OK) {
            return null;
        }
        $pfad = (string) ($datei['tmp_name'] ?? '');
        $istUpload = $this->istUpload ?? is_uploaded_file(...);

        // The path is opened as a ZIP later; make sure it is a file PHP
        // received as an upload and not a path the client made up.
        return $pfad !== '' && $istUpload($pfad) ? ['tmp_name' => $pfad] : null;
    }

    /**
     * @param array<string, string> $values
     * @return array<string, mixed>
     */
    private function dbConfig(array $values): array
    {
        return [
            'host' => $values['db_host'],
            'port' => (int) ($values['db_port'] ?: 3306),
            'name' => $values['db_name'],
            'user' => $values['db_user'],
            'password' => $values['db_password'],
        ];
    }

    /**
     * The channel the admin picked in setup.php. setup.php runs before there
     * is a database, so it leaves the choice in shared/ and the installer
     * carries it into the setting - otherwise an instance installed from a
     * pre-release would silently look for updates on stable afterwards.
     */
    private function channelFromSetup(): string
    {
        $file = $this->paths->setupChannelFile();
        if (!is_file($file)) {
            return 'stable';
        }

        return trim((string) file_get_contents($file)) === 'beta' ? 'beta' : 'stable';
    }

    /**
     * @param array<string, string> $values
     */
    private function connect(array $values): \PDO
    {
        return new \PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $values['db_host'],
                (int) ($values['db_port'] ?: 3306),
                $values['db_name'],
            ),
            $values['db_user'],
            $values['db_password'],
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT => 5,
            ],
        );
    }

    /**
     * setup.php has served its purpose and would otherwise stay in the
     * docroot as a second, unauthenticated entry point. Best effort: on some
     * hosts the FTP user owns it and PHP may not delete it, which the
     * finished page points out.
     */
    private function cleanUpSetupLeftovers(): void
    {
        foreach ([$this->paths->webDir() . '/setup.php', $this->paths->setupChannelFile()] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(array $data, int $status = 200): Response
    {
        return Response::html(
            $this->view->render('install', [
                'title' => 'Installation',
                'csrf' => $this->session->csrfToken(),
                'scripts' => ['/js/install.js'],
                'setupUebrig' => is_file($this->paths->webDir() . '/setup.php'),
                ...$data,
            ]),
            $status,
        );
    }
}
