<?php

declare(strict_types=1);

namespace App\Installer;

use App\Config\Paths;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\SettingRepository;
use App\Service\Migration\Migrator;
use App\Service\Update\UpdateService;
use App\View\View;

/**
 * /install - reachable only while shared/config.php is missing (the
 * bootstrap registers these routes in install mode only). Takes the
 * database credentials, tests the connection, runs every migration from 0
 * and writes the config, which closes the installer for good.
 *
 * Steps 3 to 6 of the flow in docs/spec/06-betrieb.md section 1 - first
 * admin, vault plus recovery key, mail settings, "fresh or restore" - need
 * the crypto core, the user management and the backup service and arrive
 * with milestones M1-4, M2 and M3-2. The step this milestone does cover
 * beyond the database is the server key, because only the installer can
 * create it (ConfigWriter).
 */
final readonly class InstallController
{
    public function __construct(
        private View $view,
        private Paths $paths,
        private Session $session,
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

        if ($errors !== []) {
            return $this->render(['errors' => $errors, 'values' => $values], 422);
        }
        assert($pdo instanceof \PDO);

        // Fresh install: every migration from 0. The channel setting is
        // written before the config, so a failure here leaves the installer
        // open instead of a half-configured installation behind.
        new Migrator($pdo, $this->paths->migrationsDir())->migrate();
        new SettingRepository($pdo)->set(UpdateService::SETTING_CHANNEL, $values['kanal']);

        ConfigWriter::write($this->paths->configFile(), [
            'host' => $values['db_host'],
            'port' => (int) ($values['db_port'] ?: 3306),
            'name' => $values['db_name'],
            'user' => $values['db_user'],
            'password' => $values['db_password'],
        ]);

        $this->cleanUpSetupLeftovers();

        return $this->render(['fertig' => true, 'errors' => [], 'values' => []]);
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
                'setupUebrig' => is_file($this->paths->webDir() . '/setup.php'),
                ...$data,
            ]),
            $status,
        );
    }
}
