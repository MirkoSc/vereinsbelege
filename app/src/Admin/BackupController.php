<?php

declare(strict_types=1);

namespace App\Admin;

use App\Domain\AuditAction;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Http\StreamResponse;
use App\Repository\UserRepository;
use App\Service\Account\PasswordChange;
use App\Service\Account\PasswordHasher;
use App\Service\Audit\AuditLog;
use App\Service\Backup\BackupErstellung;
use App\Service\Backup\BackupLaeuftBereits;
use App\Service\Backup\BackupService;
use App\Service\RateLimiter;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * /admin/backups (issue #112, docs/spec/06-betrieb.md section 2): the list
 * of backup ZIPs, "Backup jetzt erstellen" as a step chain driven by
 * public/js/backups.js, and the download.
 *
 * A backup holds business data only as ciphertext, but operational data
 * (mail addresses, AI keys) is readable to whoever also has the server key -
 * and a backup made with "config.php mitsichern" carries exactly that key.
 * Such a backup is therefore downloaded only by POST with CSRF and the
 * account's current password (rate limited per account like a password
 * change, sharing its counter); the plain GET answers 403 for it. Every download and every
 * finished backup is in the audit log.
 *
 * ---------------------------------------------------------------------
 * RIGHT REQUIRED: `admin.system` ("Backup, Update, Wartung", 01 section 4),
 * declared on every route in app/src/routes.php and checked by
 * App\Http\LoginGuard. CSRF on every write.
 * ---------------------------------------------------------------------
 */
final readonly class BackupController
{
    /** Bytes per piece of the download; the time limit restarts after each. */
    private const int BLOCK = 1024 * 1024;

    /** Seconds allowed per piece - a stalled client ends the request. */
    public const int SEKUNDEN_JE_BLOCK = 30;

    public function __construct(
        private View $view,
        private Session $session,
        private BackupService $backups,
        private BackupErstellung $erstellung,
        private AuditLog $audit,
        private UserRepository $users,
        private PasswordHasher $passwords,
        private RateLimiter $limits,
        /**
         * 0 leaves the time limit alone - for tests, where
         * set_time_limit() would bound the whole test run (as ZipExport).
         */
        private int $sekundenJeBlock = self::SEKUNDEN_JE_BLOCK,
    ) {
    }

    public function page(Request $request): ResponseInterface
    {
        $this->session->start();

        $liste = [];
        foreach ($this->backups->list() as $backup) {
            $liste[] = [...$backup, 'mit_config' => $this->backups->containsConfig($backup['name'])];
        }

        return Response::html($this->view->render('admin/backups', [
            'title' => 'Backups',
            'scripts' => ['/js/backups.js'],
            'csrf' => $this->session->csrfToken(),
            'flash' => $this->session->pullFlash(),
            'backups' => $liste,
            'laeuft' => $this->erstellung->laeuft(),
            'stand' => $this->erstellung->stand(),
            'behalten' => BackupService::KEEP,
        ], Area::Admin));
    }

    /**
     * Starts the step chain; the page's script then calls schritt() until
     * the backup is finished. Answers JSON.
     */
    public function starten(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return Response::json(['fehler' => 'Sitzung abgelaufen – bitte die Seite neu laden.'], 403);
        }

        try {
            $stand = $this->erstellung->starten(($request->post['mit_config'] ?? '') === '1');
        } catch (BackupLaeuftBereits $e) {
            return Response::json(['fehler' => $e->getMessage()], 409);
        }

        return Response::json(self::antwort($stand));
    }

    /**
     * One step of the chain (CLAUDE.md section 1: no long request).
     */
    public function schritt(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return Response::json(['fehler' => 'Sitzung abgelaufen – bitte die Seite neu laden.'], 403);
        }

        try {
            $stand = $this->erstellung->schritt();
        } catch (BackupLaeuftBereits $e) {
            return Response::json(['fehler' => $e->getMessage()], 409);
        } catch (\Throwable $e) {
            return Response::json(['fehler' => $e->getMessage()], 500);
        }

        // Only the step that finished the backup writes a row - decided
        // under the chain's lock, so a second tab never writes it again.
        if ($stand['gerade_fertig'] === true) {
            $this->audit->record(AuditAction::BackupErstellt, $this->session->userId(), $request->ip, details: [
                'name' => (string) $stand['name'],
                'config_enthalten' => (bool) $stand['config_enthalten'],
                'blob_dateien' => (int) $stand['blob_dateien'],
            ]);
            $this->session->flash('Backup ' . $stand['name'] . ' erstellt.');
        }

        return Response::json(self::antwort($stand));
    }

    public function abbrechen(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

            return Response::redirect('/admin/backups');
        }

        try {
            $this->erstellung->abbrechen();
            $this->session->flash('Backup-Erstellung abgebrochen.');
        } catch (BackupLaeuftBereits $e) {
            $this->session->flash($e->getMessage() . ' Bitte gleich noch einmal versuchen.', FlashArt::Fehler);
        }

        return Response::redirect('/admin/backups');
    }

    /**
     * GET download of a backup WITHOUT config.php.
     *
     * @param array<string, string> $params
     */
    public function download(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $name = $params['name'] ?? '';
        $pfad = $this->backups->path($name);
        if ($pfad === null) {
            return $this->nichtGefunden();
        }
        if ($this->backups->containsConfig($name)) {
            return Response::html($this->view->render('error', [
                'title' => 'Bestätigung nötig',
                'message' => 'Dieses Backup enthält config.php mit dem Server-Schlüssel. '
                    . 'Bitte auf der Seite „Backups“ mit Ihrem Passwort herunterladen.',
                'startseite' => '/admin/backups',
            ], Area::Admin), 403);
        }

        return $this->datei($request, $name, $pfad, false);
    }

    /**
     * POST download: CSRF, and for a backup with config.php the current
     * password of the account.
     *
     * @param array<string, string> $params
     */
    public function downloadBestaetigt(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

            return Response::redirect('/admin/backups');
        }
        $name = $params['name'] ?? '';
        $pfad = $this->backups->path($name);
        if ($pfad === null) {
            return $this->nichtGefunden();
        }
        $mitConfig = $this->backups->containsConfig($name);
        if ($mitConfig) {
            $fehler = $this->passwortFehler((string) ($request->post['passwort'] ?? ''));
            if ($fehler !== null) {
                $this->session->flash($fehler, FlashArt::Fehler);

                return Response::redirect('/admin/backups');
            }
        }

        return $this->datei($request, $name, $pfad, $mitConfig);
    }

    private function datei(Request $request, string $name, string $pfad, bool $mitConfig): ResponseInterface
    {
        // Logged before the first byte: once streaming starts there is no
        // later point that is sure to run.
        $this->audit->record(AuditAction::BackupHeruntergeladen, $this->session->userId(), $request->ip, details: [
            'name' => $name,
            'config_enthalten' => $mitConfig,
        ]);
        // The session lock would block the admin's other tabs for the whole
        // download.
        $this->session->schliessen();

        return new StreamResponse(self::stuecke($pfad, $this->sekundenJeBlock), [
            'Content-Type' => 'application/zip',
            // $name passed BackupService::path(): backup_<digits>[_n].zip,
            // nothing that needs quoting.
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Content-Length' => (string) filesize($pfad),
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The file in pieces, the time limit restarted for each (CLAUDE.md
     * section 1: the streamed download is the one request allowed to run
     * long - it holds no state on the server, and a broken one is simply
     * started again).
     *
     * @return \Generator<int, string>
     */
    private static function stuecke(string $pfad, int $sekundenJeBlock): \Generator
    {
        $datei = fopen($pfad, 'rb');
        if ($datei === false) {
            throw new \RuntimeException('Backup kann nicht gelesen werden.');
        }
        try {
            while (!feof($datei)) {
                if ($sekundenJeBlock > 0 && function_exists('set_time_limit')) {
                    set_time_limit($sekundenJeBlock);
                }
                $stueck = fread($datei, self::BLOCK);
                if ($stueck === false) {
                    return;
                }
                yield $stueck;
            }
        } finally {
            fclose($datei);
        }
    }

    private function passwortFehler(#[\SensitiveParameter] string $passwort): ?string
    {
        $userId = $this->session->userId();
        if ($userId === null) {
            return 'Bitte erneut anmelden.';
        }
        $konto = (string) $userId;
        // Counted before the check, atomically (N-09, issue #246).
        if (!$this->limits->versuch(PasswordChange::LIMIT_ACCOUNT, $konto, RateLimiter::LOGIN_LIMIT_PER_ACCOUNT)) {
            return 'Zu viele Versuche. Bitte warten Sie einige Minuten und versuchen Sie es erneut.';
        }
        $user = $this->users->findById($userId);
        if ($user === null || !$this->passwords->verify($passwort, $user->passwordHash)) {
            return 'Das Passwort ist falsch – das Backup wurde nicht heruntergeladen.';
        }
        $this->limits->reset(PasswordChange::LIMIT_ACCOUNT, $konto);

        return null;
    }

    private function nichtGefunden(): ResponseInterface
    {
        return Response::html($this->view->render('error', [
            'title' => 'Nicht gefunden',
            'message' => 'Dieses Backup gibt es nicht (mehr).',
            'startseite' => '/admin/backups',
        ], Area::Admin), 404);
    }

    /**
     * What the page's script needs - no paths, nothing about the club.
     *
     * @param array<string, mixed> $stand
     * @return array<string, mixed>
     */
    private static function antwort(array $stand): array
    {
        return [
            'phase' => $stand['phase'],
            'fertig' => $stand['phase'] === BackupErstellung::PHASE_FERTIG,
            'name' => $stand['name'],
            'dump_zeilen' => (int) ($stand['dump_zeilen'] ?? 0),
            'blob_dateien' => $stand['blob_dateien'],
            'blob_gesamt' => $stand['blob_gesamt'],
        ];
    }
}
