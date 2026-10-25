<?php

declare(strict_types=1);

use App\Admin\BackupController;
use App\Admin\CategoryController;
use App\Admin\CostCenterController;
use App\Admin\KiAnbieterController;
use App\Admin\MailController;
use App\Admin\NextcloudSettingsController;
use App\Admin\RoleController;
use App\Admin\UserController;
use App\Admin\VaultGrantController;
use App\Admin\VaultRecoveryController;
use App\Admin\StorageController;
use App\Admin\ExportSettingsController;
use App\Admin\SubmissionSettingsController;
use App\Admin\SystemCheckController;
use App\Admin\UpdateController;
use App\Admin\WorkerController;
use App\Api\CronController;
use App\Api\EinreichungUploadController;
use App\Api\JobController;
use App\Api\RasterungController;
use App\Api\UploadController;
use App\Api\WorkerApiController;
use App\App\AccountController;
use App\App\ArchivImportController;
use App\App\AuditController;
use App\App\AuthController;
use App\App\BuchungController;
use App\App\AbgleichController;
use App\App\ErstattungController;
use App\App\BuchungsregelController;
use App\App\CsvFormatController;
use App\App\ErfassungController;
use App\App\ExportController;
use App\App\InboxController;
use App\App\InvitationController;
use App\App\KontoauszugController;
use App\App\MfaController;
use App\App\PasswordController;
use App\App\PruefungController;
use App\App\SecurityController;
use App\App\SerienController;
use App\App\SucheController;
use App\App\SupplierController;
use App\App\UebersichtController;
use App\Domain\Berechtigungen;
use App\Domain\Permission;
use App\Domain\Worker;
use App\Http\HttpMethod;
use App\Http\LoginGuard;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Http\Zugriff;
use App\Http\ZugriffArt;
use App\PublicPages\EinreichungController;
use App\Service\Worker\WorkerAuth;
use App\View\Area;
use App\View\ScannerSkripte;
use App\View\View;

/**
 * Route table of the installed application.
 *
 * Every route names its area (App\View\Area): that picks the chrome, and it
 * decides whether the page may have a session at all - the public area never
 * starts one (App\Http\Session).
 *
 * Rights (docs/spec/01-sicherheit.md section 4, issue #19/M3-6): every
 * route declares who may call it - an App\Http\Zugriff, a required
 * argument of Router::get()/post(), so a route without one cannot be
 * registered. $route below registers the declaration AND derives the guard
 * wrapper from the very same value, so what a route declares is what is
 * checked, server side, per action (App\Http\LoginGuard::pruefe()).
 * tests/Http/RoutePermissionMatrixTest.php runs every route against every
 * shipped role.
 *
 * @param \Closure(): AuthController $auth built lazily like the rest - the
 *        login form itself renders without a database, only the attempt
 *        behind it needs one.
 * @param \Closure(): LoginGuard $guard the gate in front of /app, /admin and
 *        the upload API; checks each route's declared right.
 * @param \Closure(): MfaController $mfa built lazily: the confirmation form
 *        itself needs no database, only submitting a code does (M3-4,
 *        issue #17).
 * @param \Closure(): SecurityController $sicherheit built lazily, same
 *        reason as $storage/$mail below - every route here is already
 *        behind $guard, but the controller still should not open a
 *        connection before a matched route needs one.
 * @param \Closure(): UpdateController $updates built lazily: it opens the
 *        database connection, and the public routes must not pay for that.
 * @param \Closure(): CronController $cron built lazily for the same reason.
 * @param \Closure(): UploadController $uploads built lazily as well - opening
 *        and storing a chunk needs no database at all.
 * @param \Closure(): StorageController $storage built lazily, same reason as
 *        $updates: the storage page is the only thing that needs it.
 * @param \Closure(): MailController $mail built lazily, same reason as
 *        $updates: only the mail page and the cron need the database, and
 *        the cron builds its own Mailer instead (bootstrap.php).
 * @param \Closure(): PasswordController $passwort built lazily like $auth -
 *        "Passwort vergessen" (M3-5, issue #18); the controller itself
 *        defers its database work until a form is submitted or a link is
 *        checked.
 * @param \Closure(): RoleController $rollen built lazily, same reason as
 *        $mail: only the role pages need the database (M3-6, issue #19).
 * @param \Closure(): UserController $benutzer built lazily, same reason
 *        (M3-7, issue #20).
 * @param \Closure(): VaultGrantController $tresor built lazily, same reason
 *        (M3-7, issue #20).
 * @param \Closure(): VaultRecoveryController $wiederherstellung built lazily,
 *        same reason (M3-9, issue #22).
 * @param \Closure(): InvitationController $einladung built lazily like
 *        $passwort - a public page, but checking the link needs the
 *        database (M3-7, issue #20).
 * @param \Closure(): AuditController $audit built lazily, same reason as
 *        $mail: only the audit log page needs the database (M3-8, issue
 *        #21).
 * @param \Closure(): CostCenterController $kostenstellen built lazily, same
 *        reason as $mail: only the cost-center pages need the database
 *        (M4-1, issue #23).
 * @param \Closure(): EinreichungUploadController $einreichenUploads built
 *        lazily like $uploads - opening and storing a chunk needs no
 *        database at all (issue #24/M4-2).
 * @param \Closure(): EinreichungController $einreichen built lazily, same
 *        reason as $mail: rendering the form and validating cost centers
 *        both need the database (issue #24/M4-2).
 * @param \Closure(): SubmissionSettingsController $einreichungAdmin built
 *        lazily, same reason as $mail: only this admin page needs the
 *        database (issue #25/M4-3).
 * @param \Closure(): InboxController $posteingang built lazily, same reason
 *        as $mail: only the inbox pages need the database (issue #27/M4-5).
 * @param \Closure(): ErfassungController $erfassung built lazily, same
 *        reason: only the internal capture needs the database (issue
 *        #28/M4-6).
 * @param \Closure(): JobController $jobs built lazily, same reason as
 *        $mail: only the job step itself needs the database (issue
 *        #29/M4-7).
 * @param \Closure(): RasterungController $rasterung built lazily, same
 *        reason as $mail: only claiming a task or storing a page needs the
 *        database (issue #30/M4-8).
 * @param \Closure(): CategoryController $kategorien built lazily, same
 *        reason as $mail: only the category pages need the database
 *        (issue #35/M6-1).
 * @param \Closure(): SupplierController $lieferanten built lazily, same
 *        reason as $mail: only the supplier pages need the database
 *        (issue #36/M6-2).
 * @param \Closure(): PruefungController $pruefung built lazily, same
 *        reason as $mail: only the review pages need the database
 *        (issue #37/M6-3).
 * @param \Closure(): AccountController $konten built lazily, same reason
 *        as $mail: only the account pages need the database (issue #59/M9-1).
 * @param \Closure(): SystemCheckController $systemcheck built lazily, same
 *        reason as $mail: the check reads wait_timeout from the database
 *        (issue #107/M3-10).
 * @param \Closure(): CsvFormatController $csvFormate built lazily, same
 *        reason as $mail: only the CSV format pages need the database
 *        (issue #61/M9-3).
 * @param \Closure(): ExportSettingsController $exportAdmin built lazily,
 *        same reason as $mail: only the export pattern page needs the
 *        database (issue #75/M12-1).
 * @param \Closure(): KiAnbieterController $kiAnbieter built lazily, same
 *        reason as $mail: only the AI provider pages need the database
 *        (issue #41/M7-1).
 * @param \Closure(): KontoauszugController $kontoauszuege built lazily, same
 *        reason as $mail: only the statement import pages need the database
 *        (issue #62/M9-4).
 * @param \Closure(): BuchungController $buchungen built lazily, same reason
 *        as $mail: only the booking pages need the database (issue #63/M9-5).
 * @param \Closure(): BuchungsregelController $buchungsregeln built lazily,
 *        same reason as $mail: only the rule pages need the database
 *        (issue #64/M9-6).
 * @param \Closure(): ExportController $export built lazily, same reason as
 *        $mail: only the ZIP export needs the database (issue #76/M12-2).
 * @param \Closure(): ArchivImportController $archivImport built lazily, same
 *        reason as $mail: only the archive import needs the database (issue
 *        #77/M12-3).
 * @param \Closure(): SucheController $suche built lazily, same reason as
 *        $mail: only the full-text search needs the database (issue #94).
 * @param (\Closure(): NextcloudSettingsController)|null $nextcloudAdmin
 *        built lazily, same reason as $mail (issue #95). Optional and last,
 *        so route tables built for other pages need not name it; without it
 *        the routes exist but answer with an error.
 * @param (\Closure(): BackupController)|null $backups built lazily, same
 *        reason as $mail (issue #112). Optional, like $nextcloudAdmin.
 * @param (\Closure(): SerienController)|null $serien built lazily, same
 *        reason as $mail: only the recurring series pages need the database
 *        (issue #57/M8-1). Optional like $nextcloudAdmin.
 * @param (\Closure(): AbgleichController)|null $abgleich built lazily, same
 *        reason as $mail: only the matching page needs the database
 *        (issue #66/M10-2). Optional like $nextcloudAdmin.
 * @param (\Closure(): UebersichtController)|null $offen built lazily, same
 *        reason as $mail: only the work lists "Offen" need the database
 *        (issue #69/M10-5). Optional like $nextcloudAdmin.
 * @param (\Closure(): VaultRotationController)|null $tresorRotation built
 *        lazily (issue #90); optional for the same reason as
 *        $nextcloudAdmin.
 * @param (\Closure(): ErstattungController)|null $erstattungen built lazily,
 *        same reason as $mail: only the reimbursement page needs the
 *        database (issue #67/M10-3). Optional and last like $nextcloudAdmin.
 */
return static function (
    Router $router,
    View $view,
    \Closure $auth,
    \Closure $guard,
    \Closure $mfa,
    \Closure $sicherheit,
    \Closure $updates,
    \Closure $cron,
    \Closure $uploads,
    \Closure $storage,
    \Closure $mail,
    \Closure $passwort,
    \Closure $rollen,
    \Closure $benutzer,
    \Closure $tresor,
    \Closure $wiederherstellung,
    \Closure $einladung,
    \Closure $audit,
    \Closure $kostenstellen,
    \Closure $einreichenUploads,
    \Closure $einreichen,
    \Closure $einreichungAdmin,
    \Closure $posteingang,
    \Closure $erfassung,
    \Closure $jobs,
    \Closure $rasterung,
    \Closure $kategorien,
    \Closure $lieferanten,
    \Closure $pruefung,
    \Closure $konten,
    \Closure $systemcheck,
    \Closure $csvFormate,
    \Closure $exportAdmin,
    \Closure $kiAnbieter,
    \Closure $kontoauszuege,
    \Closure $buchungen,
    \Closure $buchungsregeln,
    \Closure $export,
    \Closure $archivImport,
    \Closure $suche,
    ?\Closure $nextcloudAdmin = null,
    ?\Closure $backups = null,
    ?\Closure $serien = null,
    ?\Closure $workerAuth = null,
    ?\Closure $workerApi = null,
    ?\Closure $workerAdmin = null,
    ?\Closure $abgleich = null,
    ?\Closure $offen = null,
    ?\Closure $tresorRotation = null,
    ?\Closure $erstattungen = null,
): void {
    $nextcloudAdmin ??= static fn(): never => throw new \LogicException('The Nextcloud settings are not wired into this route table.');
    $backups ??= static fn(): never => throw new \LogicException('The backups page is not wired into this route table.');
    $serien ??= static fn(): never => throw new \LogicException('The recurring series pages are not wired into this route table.');
    // Not wired = closed: a worker route answers with an error, never
    // without its signature check.
    $workerAuth ??= static fn(): never => throw new \LogicException('The worker signature check is not wired into this route table.');
    $workerApi ??= static fn(): never => throw new \LogicException('The worker API is not wired into this route table.');
    $workerAdmin ??= static fn(): never => throw new \LogicException('The worker admin page is not wired into this route table.');
    $abgleich ??= static fn(): never => throw new \LogicException('The matching page is not wired into this route table.');
    $offen ??= static fn(): never => throw new \LogicException('The work lists are not wired into this route table.');
    $tresorRotation ??= static fn(): never => throw new \LogicException('The vault key rotation is not wired into this route table.');
    $erstattungen ??= static fn(): never => throw new \LogicException('The reimbursement page is not wired into this route table.');

    // Registers a route with its declaration and wraps the handler in the
    // guard check that declaration asks for - one value, both jobs. The
    // guard is built once per request and only where a protected route was
    // actually matched: pruefe() returns a wrapper, it runs nothing yet.
    // A worker route's handler gets the authenticated worker as third
    // argument - it cannot be reached without a valid signature.
    $route = static function (HttpMethod $methode, string $muster, Zugriff $zugriff, \Closure $handler) use ($router, $guard, $workerAuth): void {
        $router->add(
            $methode,
            $muster,
            $zugriff,
            match (true) {
                $zugriff->art === ZugriffArt::Worker => static fn(Request $r, array $p = []) => $workerAuth()->ausfuehren($r, $p, $handler),
                $zugriff->brauchtAnmeldung() => static fn(Request $r, array $p = []) => $guard()->pruefe($zugriff, $handler)($r, $p),
                default => $handler,
            },
        );
    };
    $get = static fn(string $muster, Zugriff $zugriff, \Closure $handler) => $route(HttpMethod::Get, $muster, $zugriff, $handler);
    $post = static fn(string $muster, Zugriff $zugriff, \Closure $handler) => $route(HttpMethod::Post, $muster, $zugriff, $handler);

    $oeffentlich = Zugriff::oeffentlich();
    $angemeldet = Zugriff::angemeldet();

    // Permission: public - the start page.
    $get('/', $oeffentlich, static fn(Request $request, array $params): Response => Response::html(
        $view->render('home', ['title' => ''], Area::Oeffentlich),
    ));

    // Login and logout (M3-3, docs/spec/01-sicherheit.md section 3).
    // Permission: public - these are the way in. They are the only public
    // pages with a session (they need a CSRF token), and both writes carry
    // it; the brute force protection is the rate limit inside LoginService,
    // per IP and per account.
    $get('/anmelden', $oeffentlich, static fn(Request $r) => $auth()->form($r));
    $post('/anmelden', $oeffentlich, static fn(Request $r) => $auth()->submit($r));
    $post('/abmelden', $oeffentlich, static fn(Request $r) => $auth()->logout($r));

    // Second factor (M3-4, docs/spec/01-sicherheit.md section 3, issue #17).
    // Permission: public, same reasoning as /anmelden - the account is not
    // logged in yet in the App\Http\Session sense, only in the pending-login
    // sense (App\Service\Account\PendingLogin). Rate limiting is
    // App\Service\Account\MfaService's own counters, per IP and per account,
    // the same shape as LoginService's.
    $get('/anmelden/bestaetigen', $oeffentlich, static fn(Request $r) => $mfa()->form($r));
    $post('/anmelden/bestaetigen', $oeffentlich, static fn(Request $r) => $mfa()->submitCode($r));
    $post('/anmelden/code-senden', $oeffentlich, static fn(Request $r) => $mfa()->sendEmailCode($r));
    $post('/anmelden/backup-code', $oeffentlich, static fn(Request $r) => $mfa()->submitBackupCode($r));

    // Password reset by mail link (M3-5, issue #18, docs/spec/
    // 01-sicherheit.md sections 2 and 3). Permission: public, same reasoning
    // as /anmelden - the way back in for somebody who cannot log in. Public
    // pages with a session (CSRF on both writes). Protection: rate limit per
    // IP and per address inside App\Service\Account\PasswordReset, one and
    // the same answer whether an account exists, and a token that is 32
    // random bytes, stored only as a hash, 30 minutes, single use.
    $get('/anmelden/passwort-vergessen', $oeffentlich, static fn(Request $r) => $passwort()->vergessenForm($r));
    $post('/anmelden/passwort-vergessen', $oeffentlich, static fn(Request $r) => $passwort()->vergessenSubmit($r));
    $get('/anmelden/passwort-neu', $oeffentlich, static fn(Request $r) => $passwort()->neuForm($r));
    $post('/anmelden/passwort-neu', $oeffentlich, static fn(Request $r) => $passwort()->neuSubmit($r));

    // Accepting an invitation (M3-7, issue #20, docs/spec/01-sicherheit.md
    // section 2). Permission: public - the invited person has no password
    // yet, so there is nothing to log in with. Public page with a session
    // (CSRF on the write). Protection: the link token is 32 random bytes,
    // stored only as a hash, 72 hours, single use, and only for an account
    // still in status `eingeladen` (App\Service\Account\Invitation).
    $get('/anmelden/einladung', $oeffentlich, static fn(Request $r) => $einladung()->form($r));
    $post('/anmelden/einladung', $oeffentlich, static fn(Request $r) => $einladung()->submit($r));

    // Start page of the user area. The areas behind it (Belege, Konten, ...)
    // arrive milestone by milestone - the Posteingang since M4-5; the rest are
    // already in the
    // navigation as inactive entries (App\View\Area::navigation()), each with
    // the right its page will need. Permission: any logged-in account.
    $get('/app', $angemeldet, static fn(): Response => Response::html(
        $view->render('app/start', [
            'title' => '',
            'posteingang' => ($view->berechtigungen() ?? Berechtigungen::keine())->darf(Permission::InboxView),
            'erfassen' => ($view->berechtigungen() ?? Berechtigungen::keine())->darf(Permission::DocumentSubmitInternal),
        ], Area::App),
    ));

    // One job step (M4-7, issue #29, docs/spec/06-betrieb.md section 4):
    // `public/js/jobs.js` polls this from the browser of a signed-in person
    // while a tab is open. Permission: any logged-in account - WHICH job
    // types it may touch is checked per type inside App\Service\Job\
    // JobRunner (JobHandler::recht()), the same split InboxController and
    // ErfassungController use for their own rights, so a new job type needs
    // no route change. CSRF and JSON answers like the other fetch()-driven
    // routes.
    $post('/api/jobs/step', $angemeldet->alsApi(), static fn(Request $r) => $jobs()->step($r));

    // The `render_pages` browser job (issue #30/M4-8, docs/spec/06-betrieb.md
    // section 4): `public/js/rasterung.js`, only on the inbox pages
    // (docs/spec/03-erfassung-und-ki.md section 3). Permission:
    // `document.edit` on every route - the same right `pdf_erzeugen` and the
    // inbox decision need, and unlike /api/jobs/step there is exactly one
    // job type behind these routes, so the route itself declares it instead
    // of a generic "logged in". The lock App\Service\Document\PdfRasterung::
    // naechste() hands out travels in the path like the upload component's
    // chunk id (App\Api\UploadController); `quelle` is a GET that only
    // streams bytes and skips CSRF, the same way the inbox's own file route
    // does (App\App\InboxController::datei()).
    $rasterungRecht = Zugriff::recht(Permission::DocumentEdit)->alsApi();
    $post('/api/rasterung/naechste', $rasterungRecht, static fn(Request $r) => $rasterung()->naechste($r));
    $get(
        '/api/rasterung/{job:\d+}/{lock}/quelle/{quelle:\d+}',
        $rasterungRecht,
        static fn(Request $r, array $p) => $rasterung()->quelle($r, $p),
    );
    $post(
        '/api/rasterung/{job:\d+}/{lock}/seite/{quelle:\d+}/{seite:\d+}/{seiten:\d+}',
        $rasterungRecht,
        static fn(Request $r, array $p) => $rasterung()->seite($r, $p),
    );
    $post(
        '/api/rasterung/{job:\d+}/{lock}/abbruch',
        $rasterungRecht,
        static fn(Request $r, array $p) => $rasterung()->abbruch($r, $p),
    );

    // Cron entry point for the host's control panel (06 section 4, issue #99).
    // Permission: cron token - no session, no login. The shared secret
    // cron_token from shared/config.php is the only credential, compared with
    // hash_equals. The cron never decrypts (CLAUDE.md section 4).
    $get('/cron', Zugriff::cron(), static fn(Request $r) => $cron()->run($r));

    // The optional worker's API (issue #51/M7b-1, docs/spec/07-worker.md
    // sections 4 and 5). The worker only calls out - polling, no inbound
    // connection. No session and no CSRF: `pair` is public, its one-time
    // pairing code is the credential (15 minutes, stored as an HMAC, rate
    // limited per IP and in total - App\Service\Worker\WorkerKopplung).
    // Every other route is `Zugriff::worker()`: HMAC-SHA256 over method,
    // path, timestamp, nonce and body hash, +-5 minutes, nonce replay
    // protection, failure limit per IP, request limit per worker
    // (App\Service\Worker\WorkerAuth); claims, completes and fails need a
    // confirmed (`aktiv`) worker.
    $post('/api/worker/pair', $oeffentlich->alsApi(), static fn(Request $r) => $workerApi()->pair($r));
    $worker = Zugriff::worker()->alsApi();
    $post('/api/worker/heartbeat', $worker, static fn(Request $r, array $p, Worker $w) => $workerApi()->heartbeat($r, $w));
    $post('/api/worker/jobs/claim', $worker, static fn(Request $r, array $p, Worker $w) => $workerApi()->claim($r, $w));
    $post('/api/worker/jobs/{id:\d+}/extend', $worker, static fn(Request $r, array $p, Worker $w) => $workerApi()->extend($r, $p, $w));
    $post('/api/worker/jobs/{id:\d+}/complete', $worker, static fn(Request $r, array $p, Worker $w) => $workerApi()->complete($r, $p, $w));
    $post('/api/worker/jobs/{id:\d+}/fail', $worker, static fn(Request $r, array $p, Worker $w) => $workerApi()->fail($r, $p, $w));
    // Issue #52/M7b-2 (07 section 3): ciphertext plus a data key sealed to
    // the worker, only with a grant and a running claim on the document;
    // the job's context; results uploaded as ciphertext sealed to VK_pub.
    $get('/api/worker/blobs/{id:\d+}', $worker, static fn(Request $r, array $p, Worker $w) => $workerApi()->blob($r, $p, $w));
    $get('/api/worker/jobs/{id:\d+}/context', $worker, static fn(Request $r, array $p, Worker $w) => $workerApi()->context($r, $p, $w));
    $post('/api/worker/jobs/{id:\d+}/artifacts', $worker, static fn(Request $r, array $p, Worker $w) => $workerApi()->artefaktAnmelden($r, $p, $w));
    $post('/api/worker/jobs/{id:\d+}/artifacts/{artefakt:\d+}/chunks/{index:\d+}', $worker, static fn(Request $r, array $p, Worker $w) => $workerApi()->artefaktChunk($r, $p, $w));
    $post('/api/worker/jobs/{id:\d+}/artifacts/{artefakt:\d+}/commit', $worker, static fn(Request $r, array $p, Worker $w) => $workerApi()->artefaktFestschreiben($r, $p, $w));

    // Chunk upload (03 section 4, issue #11). Files arrive in 2 MiB pieces so
    // that no request runs long and the hoster's upload limit stops mattering.
    // Permission: `document.submit_internal`. The guard answers 401/403 JSON
    // here instead of redirecting - these routes are driven from fetch(),
    // where a login page would arrive as garbage. The public submission
    // (/einreichen, issue #24/M4-2, below) has no session and uses its own
    // upload routes under App\Api\EinreichungUploadController instead.
    //
    // The id placeholder is [0-9a-f]+ and not [0-9a-f]{32}: Route::compile()
    // reads a placeholder's regex up to the first brace, so a quantifier in
    // there would not compile. The exact length is checked where it has to be
    // anyway - UploadService validates the id before it becomes a path, and
    // anything else is a 404.
    $hochladen = Zugriff::recht(Permission::DocumentSubmitInternal)->alsApi();
    $post('/api/upload', $hochladen, static fn(Request $r) => $uploads()->create($r));
    $post(
        '/api/upload/{id:[0-9a-f]+}/chunk/{n:\d+}',
        $hochladen,
        static fn(Request $r, array $params) => $uploads()->chunk($r, $params),
    );
    $post(
        '/api/upload/{id:[0-9a-f]+}/finish',
        $hochladen,
        static fn(Request $r, array $params) => $uploads()->finish($r, $params),
    );
    $post(
        '/api/upload/{id:[0-9a-f]+}/abort',
        $hochladen,
        static fn(Request $r, array $params) => $uploads()->abort($r, $params),
    );

    // The public submission (03 section 1, issue #24/M4-2): no login, no
    // session (App\Http\Session's class docblock), writes only into the
    // inbox. Permission: public - the credential is the stateless form token
    // (App\Service\Submission\FormToken), checked by the controllers
    // themselves, not by the guard. Spam protection (rate limit, proof of
    // work, honeypot, minimum fill time - issue #25/M4-3, 01 section 5) is
    // App\Service\Submission\Spamschutz, also checked by the controllers
    // themselves.
    $get('/einreichen', $oeffentlich, static fn(Request $r) => $einreichen()->formular($r));
    $post('/einreichen', $oeffentlich->alsApi(), static fn(Request $r) => $einreichen()->absenden($r));

    // Chunk upload for the page above, the same contract as /api/upload
    // (issue #11/M2-4) under its own path: the internal upload needs
    // `document.submit_internal`, this one has no session to hold a right in
    // at all. See App\Api\EinreichungUploadController for why it cannot
    // simply reuse App\Api\UploadController.
    $einreichenHochladen = $oeffentlich->alsApi();
    $post('/einreichen/upload', $einreichenHochladen, static fn(Request $r) => $einreichenUploads()->create($r));
    $post(
        '/einreichen/upload/{id:[0-9a-f]+}/chunk/{n:\d+}',
        $einreichenHochladen,
        static fn(Request $r, array $params) => $einreichenUploads()->chunk($r, $params),
    );
    $post(
        '/einreichen/upload/{id:[0-9a-f]+}/finish',
        $einreichenHochladen,
        static fn(Request $r, array $params) => $einreichenUploads()->finish($r, $params),
    );
    $post(
        '/einreichen/upload/{id:[0-9a-f]+}/abort',
        $einreichenHochladen,
        static fn(Request $r, array $params) => $einreichenUploads()->abort($r, $params),
    );

    // Managing an already logged-in account's second factor (M3-4, issue
    // #17). Permission: any logged-in account - every account manages its
    // own factor. CSRF on all writes. The guard (App\Http\LoginGuard) sends
    // here on its own, to `/app/sicherheit/einrichten`, whenever
    // `mfa_required` has no configured method yet - these routes are exactly
    // what it exempts from that redirect.
    $get('/app/sicherheit', $angemeldet, static fn(Request $r) => $sicherheit()->page($r));
    $get('/app/sicherheit/einrichten', $angemeldet, static fn(Request $r) => $sicherheit()->einrichten($r));
    $post('/app/sicherheit/einrichten/totp/starten', $angemeldet, static fn(Request $r) => $sicherheit()->totpStarten($r));
    $post('/app/sicherheit/einrichten/totp/bestaetigen', $angemeldet, static fn(Request $r) => $sicherheit()->totpBestaetigen($r));
    $post('/app/sicherheit/einrichten/email/starten', $angemeldet, static fn(Request $r) => $sicherheit()->emailStarten($r));
    $post('/app/sicherheit/einrichten/email/bestaetigen', $angemeldet, static fn(Request $r) => $sicherheit()->emailBestaetigen($r));
    $post('/app/sicherheit/backup-codes/neu', $angemeldet, static fn(Request $r) => $sicherheit()->backupCodesNeu($r));
    $post(
        '/app/sicherheit/geraete/{id:\d+}/widerrufen',
        $angemeldet,
        static fn(Request $r, array $params) => $sicherheit()->geraetWiderrufen($r, $params),
    );

    // Password change with the old password known (M3-5, issue #18).
    // Permission: any logged-in account; every account changes its own
    // password. CSRF on the write; wrong old passwords are rate limited per
    // account (App\Service\Account\PasswordChange).
    $get('/app/sicherheit/passwort', $angemeldet, static fn(Request $r) => $sicherheit()->passwort($r));
    $post('/app/sicherheit/passwort', $angemeldet, static fn(Request $r) => $sicherheit()->passwortAendern($r));

    // Audit log (M3-8, issue #21, docs/spec/01-sicherheit.md section 6):
    // the filtered list and the integrity check of the hash chain.
    // Permission: `audit.view` - Admin, Vorstand, Kassenprüfer. In /app, not
    // /admin: /admin needs an `admin.*` right, which Vorstand and
    // Kassenprüfer do not have. Both routes only read; the check is a POST
    // with CSRF because it is a step chain driven by fetch() (JSON 401/403).
    $get('/app/audit', Zugriff::recht(Permission::AuditView), static fn(Request $r) => $audit()->liste($r));
    $post('/app/audit/pruefen', Zugriff::recht(Permission::AuditView)->alsApi(), static fn(Request $r) => $audit()->pruefen($r));

    // The inbox (M4-5, issue #27, docs/spec/02-datenmodell.md "Statusmodell").
    // Permission: reading `inbox.view` (every shipped role, the
    // Vereinsverantwortlicher only for his cost centers - filtered in SQL by
    // App\Repository\DocumentRepository); deciding and the cost center
    // `document.edit` (Admin, Finanzen). CSRF on every write. The page route
    // streams one decrypted page, never a file (CLAUDE.md section 4).
    $inboxView = Zugriff::recht(Permission::InboxView);
    $documentEdit = Zugriff::recht(Permission::DocumentEdit);
    $get('/app/posteingang', $inboxView, static fn(Request $r) => $posteingang()->liste($r));
    $get('/app/posteingang/{id:\d+}', $inboxView, static fn(Request $r, array $params) => $posteingang()->detail($r, $params));
    $get('/app/posteingang/{id:\d+}/datei/{blob:\d+}', $inboxView, static fn(Request $r, array $params) => $posteingang()->datei($r, $params));
    $post('/app/posteingang/{id:\d+}/annehmen', $documentEdit, static fn(Request $r, array $params) => $posteingang()->annehmen($r, $params));
    $post('/app/posteingang/{id:\d+}/ablehnen', $documentEdit, static fn(Request $r, array $params) => $posteingang()->ablehnen($r, $params));
    $post('/app/posteingang/{id:\d+}/wiedervorlage', $documentEdit, static fn(Request $r, array $params) => $posteingang()->wiedervorlage($r, $params));
    $post('/app/posteingang/{id:\d+}/kostenstelle', $documentEdit, static fn(Request $r, array $params) => $posteingang()->kostenstelle($r, $params));
    // Duplicate detection (M6-6, issue #40): resolving a suspected duplicate
    // - discard it (rejected) or keep it deliberately. `document.edit`, the
    // inbox scope, CSRF, the unlocked vault (App\App\InboxController).
    $post('/app/posteingang/{id:\d+}/duplikat-verwerfen', $documentEdit, static fn(Request $r, array $params) => $posteingang()->duplikatVerwerfen($r, $params));
    $post('/app/posteingang/{id:\d+}/duplikat-behalten', $documentEdit, static fn(Request $r, array $params) => $posteingang()->duplikatBehalten($r, $params));

    // Full-text search (issue #94, docs/spec/03-erfassung-und-ki.md
    // section 10). Permission: `inbox.view` with its scope, like the inbox
    // the hits link to - indexed and shown is only what that scope allows
    // (App\Service\Suche\Volltextsuche). The search is a POST with CSRF so
    // the terms stay out of URLs and logs; the index step is fetch()-driven
    // (JSON 401/403), "neu aufbauen" a plain form.
    $get('/app/suche', $inboxView, static fn(Request $r) => $suche()->seite($r));
    $post('/app/suche', $inboxView, static fn(Request $r) => $suche()->suchen($r));
    $post('/app/suche/index', $inboxView->alsApi(), static fn(Request $r) => $suche()->schritt($r));
    $post('/app/suche/neu', $inboxView, static fn(Request $r) => $suche()->neu($r));

    // Internal capture (M4-6, issue #28, docs/spec/03-erfassung-und-ki.md
    // section 1): several receipts in one pass, straight into the inbox.
    // Permission: `document.submit_internal` - the same right as the
    // /api/upload routes the page uploads through. The submit is JSON from
    // fetch() (401/403 JSON rather than a redirect) with the session CSRF
    // token in X-CSRF-Token. It only seals to the vault's public key, so no
    // unlocked vault is needed.
    $intern = Zugriff::recht(Permission::DocumentSubmitInternal);
    $get('/app/belege/neu', $intern, static fn(Request $r) => $erfassung()->formular($r));
    $post('/app/belege/neu', $intern->alsApi(), static fn(Request $r) => $erfassung()->absenden($r));

    // Suppliers and payers (M6-2, issue #36, docs/spec/03-erfassung-und-ki.md
    // section 7). Permission: `supplier.manage` (Admin, Finanzen) for reading
    // and writing alike - the list is the maintenance page. CSRF on every
    // write. Names and IBANs are vault data, so the pages show nothing and
    // write nothing without the unlocked vault (App\App\SupplierController).
    $lieferantenPflege = Zugriff::recht(Permission::SupplierManage);
    $get('/app/lieferanten', $lieferantenPflege, static fn(Request $r) => $lieferanten()->liste($r));
    $get('/app/lieferanten/neu', $lieferantenPflege, static fn(Request $r) => $lieferanten()->neu($r));
    $post('/app/lieferanten', $lieferantenPflege, static fn(Request $r) => $lieferanten()->anlegen($r));
    $get('/app/lieferanten/{id:\d+}', $lieferantenPflege, static fn(Request $r, array $params) => $lieferanten()->bearbeiten($r, $params));
    $post('/app/lieferanten/{id:\d+}', $lieferantenPflege, static fn(Request $r, array $params) => $lieferanten()->speichern($r, $params));
    $post('/app/lieferanten/{id:\d+}/loeschen', $lieferantenPflege, static fn(Request $r, array $params) => $lieferanten()->loeschen($r, $params));
    // Issue #48/M7-6: a supplier the AI created leaves "Neue Lieferanten prüfen".
    $post('/app/lieferanten/{id:\d+}/geprueft', $lieferantenPflege, static fn(Request $r, array $params) => $lieferanten()->geprueft($r, $params));
    // Merging a duplicate (M6-5, issue #39): preview, then the merge.
    $get('/app/lieferanten/{id:\d+}/zusammenfuehren', $lieferantenPflege, static fn(Request $r, array $params) => $lieferanten()->zusammenfuehrenVorschau($r, $params));
    $post('/app/lieferanten/{id:\d+}/zusammenfuehren', $lieferantenPflege, static fn(Request $r, array $params) => $lieferanten()->zusammenfuehren($r, $params));

    // The review page (M6-3, issue #37, docs/spec/03-erfassung-und-ki.md
    // section 6 "Prüfansicht"): the queue and one document captured by hand
    // next to its pages. Permission: `document.edit` (Admin, Finanzen) -
    // capturing is editing the receipt; its scope narrows the queue in SQL
    // (App\Repository\DocumentRepository). Creating a supplier from the form
    // additionally needs `supplier.manage`, checked by App\Service\Invoice\
    // Pruefung itself. CSRF on the write. Everything shown is vault data:
    // without the unlocked vault the page shows and writes nothing, and the
    // file route streams one decrypted page like the inbox's.
    $get('/app/belege/pruefen', $documentEdit, static fn(Request $r) => $pruefung()->liste($r));
    $get('/app/belege/pruefen/{id:\d+}', $documentEdit, static fn(Request $r, array $params) => $pruefung()->formular($r, $params));
    $post('/app/belege/pruefen/{id:\d+}', $documentEdit, static fn(Request $r, array $params) => $pruefung()->speichern($r, $params));
    $get('/app/belege/pruefen/{id:\d+}/datei/{blob:\d+}', $documentEdit, static fn(Request $r, array $params) => $pruefung()->datei($r, $params));

    // Festschreibung (M6-4, issue #38, docs/spec/01-sicherheit.md section 7,
    // E-09): locking a checked receipt and - with a mandatory reason - lifting
    // the lock again. Same right as capturing it (`document.edit`: no
    // four-eyes principle), same scope, CSRF on both writes, the unlocked
    // vault required like for everything on the review page.
    $post('/app/belege/pruefen/{id:\d+}/festschreiben', $documentEdit, static fn(Request $r, array $params) => $pruefung()->festschreiben($r, $params));
    $post('/app/belege/pruefen/{id:\d+}/festschreibung-aufheben', $documentEdit, static fn(Request $r, array $params) => $pruefung()->festschreibungAufheben($r, $params));
    // The AI extraction once more (issue #47/M7-5): after `ki_fehler`, or
    // for a receipt still waiting that was never read.
    $post('/app/belege/pruefen/{id:\d+}/ki-erneut', $documentEdit, static fn(Request $r, array $params) => $pruefung()->kiErneut($r, $params));

    // Duplicate detection (M6-6, issue #40) from the review page: the same
    // two decisions as in the inbox, with the review page's scope.
    $post('/app/belege/pruefen/{id:\d+}/duplikat-verwerfen', $documentEdit, static fn(Request $r, array $params) => $pruefung()->duplikatVerwerfen($r, $params));
    $post('/app/belege/pruefen/{id:\d+}/duplikat-behalten', $documentEdit, static fn(Request $r, array $params) => $pruefung()->duplikatBehalten($r, $params));

    // Recurring invoices (M8-1, issue #57, docs/spec/03-erfassung-und-ki.md
    // section 9): the suggestions of the `detect_recurring` job and the
    // confirmed series - confirm, change, dismiss. Permission:
    // `document.edit` like the review page whose receipts they are; a series
    // spans a supplier's receipts of every cost center and period, so an
    // account with a scope gets a refusal (App\App\SerienController). CSRF
    // on the writes, the unlocked vault for everything (amounts, names).
    $get('/app/serien', $documentEdit, static fn(Request $r) => $serien()->liste($r));
    $get('/app/serien/{id:\d+}', $documentEdit, static fn(Request $r, array $params) => $serien()->formular($r, $params));
    $post('/app/serien/{id:\d+}', $documentEdit, static fn(Request $r, array $params) => $serien()->speichern($r, $params));
    $post('/app/serien/{id:\d+}/verwerfen', $documentEdit, static fn(Request $r, array $params) => $serien()->verwerfen($r, $params));

    // Matching receipt <-> booking (M10-2, issue #66, docs/spec/
    // 04-bank-und-abgleich.md section 5 "Punktebewertung"): automatic
    // allocation, suggestions, the points as settings - all `matching.edit`,
    // CSRF on the writes, the unlocked vault for scoring (amounts, names).
    $abgleichRecht = Zugriff::recht(Permission::MatchingEdit);
    $get('/app/abgleich', $abgleichRecht, static fn(Request $r) => $abgleich()->seite($r));
    $post('/app/abgleich/ausfuehren', $abgleichRecht, static fn(Request $r) => $abgleich()->ausfuehren($r));
    $post('/app/abgleich/uebernehmen', $abgleichRecht, static fn(Request $r) => $abgleich()->uebernehmen($r));
    $post('/app/abgleich/einstellungen', $abgleichRecht, static fn(Request $r) => $abgleich()->einstellungen($r));
    // Work lists "Offen" (M10-5, issue #69, docs/spec/04-bank-und-abgleich.md
    // section 5 "Übersichten", E-37), read-only: unpaid receipts and open
    // reimbursements `report.view` (its scope filters in SQL), bookings
    // without a receipt `bank.view`, expected receipts of series
    // `document.edit` without a scope like the series pages. /app/offen
    // only forwards to the first list the account may open.
    $get('/app/offen', $angemeldet, static fn(Request $r) => $offen()->start($r));
    $get('/app/offen/rechnungen', Zugriff::recht(Permission::ReportView), static fn(Request $r) => $offen()->unbezahlt($r));
    $get('/app/offen/rechnungen/{id:\d+}/girocode', Zugriff::recht(Permission::ReportView), static fn(Request $r, array $params) => $offen()->girocode($r, $params));
    $get('/app/offen/erstattungen', Zugriff::recht(Permission::ReportView), static fn(Request $r) => $offen()->erstattungen($r));
    $get('/app/offen/buchungen', Zugriff::recht(Permission::BankView), static fn(Request $r) => $offen()->buchungen($r));
    $get('/app/offen/erwartet', $documentEdit, static fn(Request $r) => $offen()->erwartet($r));

    // Bank accounts and cash boxes (M9-1, issue #59, docs/spec/
    // 04-bank-und-abgleich.md section 1). Permission: `bank.view` (Admin,
    // Vorstand, Finanzen, Kassenprüfer, Steuerberater) to see the list, an
    // account and the cash counts of a cash box - the period scope of
    // external roles narrows the counts in SQL; `bank.book` (Admin, Finanzen)
    // for everything that writes: creating, changing, deleting an account
    // and recording a cash count. CSRF on every write. Names, IBANs and
    // amounts are vault data, so the pages show nothing and write nothing
    // without the unlocked vault (App\App\AccountController).
    $kontenLesen = Zugriff::recht(Permission::BankView);
    $kontenPflege = Zugriff::recht(Permission::BankBook);
    $get('/app/konten', $kontenLesen, static fn(Request $r) => $konten()->liste($r));
    $get('/app/konten/neu', $kontenPflege, static fn(Request $r) => $konten()->neu($r));
    $post('/app/konten', $kontenPflege, static fn(Request $r) => $konten()->anlegen($r));
    $get('/app/konten/{id:\d+}', $kontenLesen, static fn(Request $r, array $params) => $konten()->ansicht($r, $params));
    $post('/app/konten/{id:\d+}', $kontenPflege, static fn(Request $r, array $params) => $konten()->speichern($r, $params));
    $post('/app/konten/{id:\d+}/loeschen', $kontenPflege, static fn(Request $r, array $params) => $konten()->loeschen($r, $params));
    $get('/app/konten/{id:\d+}/kassensturz', $kontenPflege, static fn(Request $r, array $params) => $konten()->kassensturz($r, $params));
    $post('/app/konten/{id:\d+}/kassensturz', $kontenPflege, static fn(Request $r, array $params) => $konten()->kassensturzErfassen($r, $params));

    // CSV formats for the statement import (M9-3, issue #61, docs/spec/
    // 04-bank-und-abgleich.md section 3). Permission: `bank.import` (Admin,
    // Finanzen) on every route - whoever imports statements teaches the
    // formats. The preview takes the sample file with every change of the
    // assistant and stores nothing; CSRF on every POST, the preview
    // included. Shipped formats are read-only (App\App\CsvFormatController).
    $csvImport = Zugriff::recht(Permission::BankImport);
    $get('/app/konten/csv-formate', $csvImport, static fn(Request $r) => $csvFormate()->liste($r));
    $get('/app/konten/csv-formate/neu', $csvImport, static fn(Request $r) => $csvFormate()->neu($r));
    $post('/app/konten/csv-formate', $csvImport, static fn(Request $r) => $csvFormate()->anlegen($r));
    $post('/app/konten/csv-formate/vorschau', $csvImport, static fn(Request $r) => $csvFormate()->vorschau($r));
    $get('/app/konten/csv-formate/{id:\d+}', $csvImport, static fn(Request $r, array $params) => $csvFormate()->ansicht($r, $params));
    $get('/app/konten/csv-formate/{id:\d+}/bearbeiten', $csvImport, static fn(Request $r, array $params) => $csvFormate()->bearbeiten($r, $params));
    $post('/app/konten/csv-formate/{id:\d+}', $csvImport, static fn(Request $r, array $params) => $csvFormate()->speichern($r, $params));
    $post('/app/konten/csv-formate/{id:\d+}/loeschen', $csvImport, static fn(Request $r, array $params) => $csvFormate()->loeschen($r, $params));

    // Statement import (M9-4, issue #62, docs/spec/04-bank-und-abgleich.md
    // section 4). Permission: `bank.import` (Admin, Finanzen) on every route,
    // the same as the CSV formats. Upload → preview (nothing written) →
    // confirm → the page drives the step chain one POST at a time
    // (`…/schritt`, JSON, hence alsApi()). CSRF on every POST. The bookings
    // are vault data: without the unlocked vault nothing is read or written
    // (App\App\KontoauszugController).
    $get('/app/konten/import', $csvImport, static fn(Request $r) => $kontoauszuege()->start($r));
    $post('/app/konten/import', $csvImport, static fn(Request $r) => $kontoauszuege()->hochladen($r));
    $get('/app/konten/import/{id:\d+}', $csvImport, static fn(Request $r, array $params) => $kontoauszuege()->ansicht($r, $params));
    $post('/app/konten/import/{id:\d+}/konto', $csvImport, static fn(Request $r, array $params) => $kontoauszuege()->konto($r, $params));
    $post('/app/konten/import/{id:\d+}/uebernehmen', $csvImport, static fn(Request $r, array $params) => $kontoauszuege()->uebernehmen($r, $params));
    $post('/app/konten/import/{id:\d+}/schritt', Zugriff::recht(Permission::BankImport)->alsApi(), static fn(Request $r, array $params) => $kontoauszuege()->schritt($r, $params));
    $post('/app/konten/import/{id:\d+}/verwerfen', $csvImport, static fn(Request $r, array $params) => $kontoauszuege()->verwerfen($r, $params));

    // Bookings (M9-5, issue #63, docs/spec/04-bank-und-abgleich.md
    // section 1). Permission: reading `bank.view` (the list is narrowed to
    // the reader's period in SQL), entering, changing and deleting a manual
    // booking `bank.book` - the same split as the accounts. CSRF on every
    // POST; without the unlocked vault nothing is read or written
    // (App\App\BuchungController).
    $get('/app/buchungen', $kontenLesen, static fn(Request $r) => $buchungen()->liste($r));
    $get('/app/buchungen/neu', $kontenPflege, static fn(Request $r) => $buchungen()->neu($r));
    $post('/app/buchungen', $kontenPflege, static fn(Request $r) => $buchungen()->anlegen($r));
    $get('/app/buchungen/{id:\d+}', $kontenLesen, static fn(Request $r, array $params) => $buchungen()->ansicht($r, $params));
    $post('/app/buchungen/{id:\d+}', $kontenPflege, static fn(Request $r, array $params) => $buchungen()->speichern($r, $params));
    $post('/app/buchungen/{id:\d+}/loeschen', $kontenPflege, static fn(Request $r, array $params) => $buchungen()->loeschen($r, $params));
    $post('/app/buchungen/{id:\d+}/einordnung', $kontenPflege, static fn(Request $r, array $params) => $buchungen()->einordnen($r, $params));
    // Transfers between own accounts (M10-6, issue #70, 04 section 5 "Stand
    // M10-6"): pairing, unpairing and the recognition write structure of
    // bookings - `bank.book`, like every other change to a booking; the
    // partner is shown with `bank.view`. CSRF on every POST, vault needed.
    $post('/app/buchungen/umbuchungen', $kontenPflege, static fn(Request $r) => $buchungen()->umbuchungenErkennen($r));
    $post('/app/buchungen/{id:\d+}/umbuchung', $kontenPflege, static fn(Request $r, array $params) => $buchungen()->umbuchungPaaren($r, $params));
    $post('/app/buchungen/{id:\d+}/umbuchung/loesen', $kontenPflege, static fn(Request $r, array $params) => $buchungen()->umbuchungLoesen($r, $params));

    // Reimbursements for one booking (M10-3, issue #67, docs/spec/
    // 04-bank-und-abgleich.md section 5): open reimbursements of the
    // counterparty's IBAN and their combinations. Permission `matching.edit`
    // for reading and allocating - it is the matching. CSRF on the POST;
    // without the unlocked vault nothing is read or written
    // (App\App\ErstattungController).
    $get('/app/buchungen/{id:\d+}/erstattung', $abgleichRecht, static fn(Request $r, array $params) => $erstattungen()->ansicht($r, $params));
    $post('/app/buchungen/{id:\d+}/erstattung', $abgleichRecht, static fn(Request $r, array $params) => $erstattungen()->zuordnen($r, $params));

    // Rules "kein Beleg nötig" / category (M9-6, issue #64, docs/spec/
    // 04-bank-und-abgleich.md section 5). Permission: reading `bank.view` -
    // auditors see what the rules do -, everything that writes `bank.book`,
    // the same split as the bookings. CSRF on every POST; label and pattern
    // are vault data (App\App\BuchungsregelController).
    $get('/app/buchungen/regeln', $kontenLesen, static fn(Request $r) => $buchungsregeln()->liste($r));
    $get('/app/buchungen/regeln/neu', $kontenPflege, static fn(Request $r) => $buchungsregeln()->neu($r));
    $post('/app/buchungen/regeln', $kontenPflege, static fn(Request $r) => $buchungsregeln()->anlegen($r));
    $post('/app/buchungen/regeln/standard', $kontenPflege, static fn(Request $r) => $buchungsregeln()->standard($r));
    $get('/app/buchungen/regeln/{id:\d+}', $kontenLesen, static fn(Request $r, array $params) => $buchungsregeln()->ansicht($r, $params));
    $post('/app/buchungen/regeln/{id:\d+}', $kontenPflege, static fn(Request $r, array $params) => $buchungsregeln()->speichern($r, $params));
    $post('/app/buchungen/regeln/{id:\d+}/anwenden', $kontenPflege, static fn(Request $r, array $params) => $buchungsregeln()->anwenden($r, $params));
    $post('/app/buchungen/regeln/{id:\d+}/aktiv', $kontenPflege, static fn(Request $r, array $params) => $buchungsregeln()->aktiv($r, $params));
    $post('/app/buchungen/regeln/{id:\d+}/loeschen', $kontenPflege, static fn(Request $r, array $params) => $buchungsregeln()->loeschen($r, $params));

    // ZIP export (issue #76/M12-2, docs/spec/05-auswertung-und-export.md
    // section 2). Permission: `export.zip` - on the user side, because the
    // board, the auditors and the tax advisor hold it without the admin
    // area; the period scope narrows it in SQL. Every export is a run
    // (issue #179/M12-4): created from the filter, downloaded from its page,
    // and only a confirmation marks its receipts as exported. Every POST
    // carries CSRF; creating and downloading need the unlocked vault
    // (App\App\ExportController).
    $exportZip = Zugriff::recht(Permission::ExportZip);
    $get('/app/export', $exportZip, static fn(Request $r) => $export()->seite($r));
    $post('/app/export/lauf', $exportZip, static fn(Request $r) => $export()->anlegen($r));
    $get('/app/export/lauf/{id:\d+}', $exportZip, static fn(Request $r, array $params) => $export()->lauf($r, $params));
    $post('/app/export/lauf/{id:\d+}/zip', $exportZip, static fn(Request $r, array $params) => $export()->zip($r, $params));
    $post('/app/export/lauf/{id:\d+}/bestaetigen', $exportZip, static fn(Request $r, array $params) => $export()->bestaetigen($r, $params));
    $post('/app/export/lauf/{id:\d+}/zuruecksetzen', $exportZip, static fn(Request $r, array $params) => $export()->zuruecksetzen($r, $params));
    // Filing a run into the club's Nextcloud (issue #95): start, then one
    // JSON step at a time from public/js/nextcloud.js. `export.zip` without
    // a scope only - the controller answers 404 to a scoped role.
    $post('/app/export/lauf/{id:\d+}/nextcloud', $exportZip, static fn(Request $r, array $params) => $export()->nextcloudStarten($r, $params));
    $post('/app/export/lauf/{id:\d+}/nextcloud/schritt', $exportZip->alsApi(), static fn(Request $r, array $params) => $export()->nextcloudSchritt($r, $params));

    // Archive import (issue #77/M12-3, docs/spec/05-auswertung-und-
    // export.md section 3). Permission: `archive.import` (Admin, Finanzen)
    // on every route. The ZIP arrives through the chunk upload under its own
    // prefix (larger limit, finish() checks a ZIP instead of a receipt) →
    // preview (nothing written) → start → the page drives the step chain one
    // POST at a time. The upload routes and `…/schritt` answer JSON, hence
    // alsApi(). CSRF on every POST; without the unlocked vault nothing is
    // read or written (App\App\ArchivImportController).
    $archiv = Zugriff::recht(Permission::ArchiveImport);
    $archivApi = Zugriff::recht(Permission::ArchiveImport)->alsApi();
    $get('/app/archiv-import', $archiv, static fn(Request $r) => $archivImport()->start($r));
    $post('/app/archiv-import/upload', $archivApi, static fn(Request $r) => $archivImport()->uploadOeffnen($r));
    $post('/app/archiv-import/upload/{id:[0-9a-f]+}/chunk/{n:\d+}', $archivApi, static fn(Request $r, array $params) => $archivImport()->uploadChunk($r, $params));
    $post('/app/archiv-import/upload/{id:[0-9a-f]+}/finish', $archivApi, static fn(Request $r, array $params) => $archivImport()->uploadAbschliessen($r, $params));
    $post('/app/archiv-import/upload/{id:[0-9a-f]+}/abort', $archivApi, static fn(Request $r, array $params) => $archivImport()->uploadAbbrechen($r, $params));
    $get('/app/archiv-import/{id:\d+}', $archiv, static fn(Request $r, array $params) => $archivImport()->ansicht($r, $params));
    $post('/app/archiv-import/{id:\d+}/starten', $archiv, static fn(Request $r, array $params) => $archivImport()->starten($r, $params));
    $post('/app/archiv-import/{id:\d+}/schritt', $archivApi, static fn(Request $r, array $params) => $archivImport()->schritt($r, $params));
    $post('/app/archiv-import/{id:\d+}/verwerfen', $archiv, static fn(Request $r, array $params) => $archivImport()->verwerfen($r, $params));

    // Permission: any `admin.*` right; sends the account to the first admin
    // page it may open (App\View\Area::adminStartFuer()).
    $get('/admin', Zugriff::adminBereich(), static fn(): Response => Response::redirect(
        Area::adminStartFuer($view->berechtigungen() ?? Berechtigungen::keine()),
    ));

    // Pattern page of the design system - the reference for new pages and
    // the place where the light/dark and 360 px checks happen. Also the
    // demo page of the corner editor (issue #33/M5-3): the scanner scripts
    // load in their dependency order (App\View\ScannerSkripte),
    // designsystem.js last (the demo glue).
    // Permission: any `admin.*` right.
    $get('/admin/designsystem', Zugriff::adminBereich(), static fn(): Response => Response::html(
        $view->render('admin/designsystem', [
            'title' => 'Designsystem',
            'scripts' => [...ScannerSkripte::LISTE, '/js/designsystem.js'],
        ], Area::Admin),
    ));

    // Roles (M3-6, issue #19, docs/spec/01-sicherheit.md section 4): the
    // shipped six and the club's own. Permission: `admin.users` - who may
    // do what is part of managing accounts. CSRF on all writes; the rules
    // live in App\Service\Account\RoleService.
    $rollenVerwalten = Zugriff::recht(Permission::AdminUsers);
    $get('/admin/rollen', $rollenVerwalten, static fn(Request $r) => $rollen()->liste($r));
    $get('/admin/rollen/neu', $rollenVerwalten, static fn(Request $r) => $rollen()->neu($r));
    $post('/admin/rollen', $rollenVerwalten, static fn(Request $r) => $rollen()->anlegen($r));
    $get('/admin/rollen/{id:\d+}', $rollenVerwalten, static fn(Request $r, array $params) => $rollen()->bearbeiten($r, $params));
    $post('/admin/rollen/{id:\d+}', $rollenVerwalten, static fn(Request $r, array $params) => $rollen()->speichern($r, $params));
    $post('/admin/rollen/{id:\d+}/loeschen', $rollenVerwalten, static fn(Request $r, array $params) => $rollen()->loeschen($r, $params));

    // Users (M3-7, issue #20, docs/spec/01-sicherheit.md sections 2 and 4):
    // invite, change roles/scopes/end date, lock, unlock. Permission:
    // `admin.users`. CSRF on all writes; the rules live in
    // App\Service\Account\Invitation, AccessAssignment and
    // UserAdministration.
    $benutzerVerwalten = Zugriff::recht(Permission::AdminUsers);
    $get('/admin/benutzer', $benutzerVerwalten, static fn(Request $r) => $benutzer()->liste($r));
    $get('/admin/benutzer/neu', $benutzerVerwalten, static fn(Request $r) => $benutzer()->neu($r));
    $post('/admin/benutzer', $benutzerVerwalten, static fn(Request $r) => $benutzer()->anlegen($r));
    $get('/admin/benutzer/{id:\d+}', $benutzerVerwalten, static fn(Request $r, array $params) => $benutzer()->bearbeiten($r, $params));
    $post('/admin/benutzer/{id:\d+}', $benutzerVerwalten, static fn(Request $r, array $params) => $benutzer()->speichern($r, $params));
    $post('/admin/benutzer/{id:\d+}/sperren', $benutzerVerwalten, static fn(Request $r, array $params) => $benutzer()->sperren($r, $params));
    $post('/admin/benutzer/{id:\d+}/entsperren', $benutzerVerwalten, static fn(Request $r, array $params) => $benutzer()->entsperren($r, $params));
    $post(
        '/admin/benutzer/{id:\d+}/einladung-erneut',
        $benutzerVerwalten,
        static fn(Request $r, array $params) => $benutzer()->einladungErneut($r, $params),
    );

    // Vault grants (M3-7, issue #20, docs/spec/01-sicherheit.md section 2
    // "Freigabe"). Permission: `admin.vault_grant`. CSRF on all writes;
    // granting additionally needs this session's own unlocked vault.
    $freigaben = Zugriff::recht(Permission::AdminVaultGrant);
    $get('/admin/tresor', $freigaben, static fn(Request $r) => $tresor()->seite($r));
    $post('/admin/tresor/{id:\d+}/freigeben', $freigaben, static fn(Request $r, array $params) => $tresor()->freigeben($r, $params));
    $post('/admin/tresor/{id:\d+}/entziehen', $freigaben, static fn(Request $r, array $params) => $tresor()->entziehen($r, $params));

    // Vault key rotation (issue #90, docs/spec/01-sicherheit.md section 2
    // "Schlüsselrotation"). Same permission as granting - it is the same
    // kind of act, for every user at once. CSRF on all writes; every write
    // needs this session's unlocked key ring. While a rotation runs,
    // App\Http\LoginGuard keeps these routes (and /app/sicherheit) open and
    // everything else in /app and /admin closed.
    $get('/admin/tresor/rotation', $freigaben, static fn(Request $r) => $tresorRotation()->seite($r));
    $post('/admin/tresor/rotation/starten', $freigaben, static fn(Request $r) => $tresorRotation()->starten($r));
    $post('/admin/tresor/rotation/bestaetigen', $freigaben, static fn(Request $r) => $tresorRotation()->bestaetigen($r));
    $post('/admin/tresor/rotation/schritt', $freigaben->alsApi(), static fn(Request $r) => $tresorRotation()->schritt($r));
    $post('/admin/tresor/rotation/abschliessen', $freigaben, static fn(Request $r) => $tresorRotation()->abschliessen($r));
    $post('/admin/tresor/rotation/abbrechen', $freigaben, static fn(Request $r) => $tresorRotation()->abbrechen($r));

    // Vault recovery with the paper recovery key (M3-9, issue #22,
    // docs/spec/01-sicherheit.md section 2 "Letzter Admin hat Passwort
    // vergessen"). Same permission as /admin/tresor - it is the same act,
    // unlocking the vault for an account, just self-service and without
    // needing anyone else's unlocked vault first. CSRF on the write; wrong or
    // mistyped keys are rate limited per account
    // (App\Service\Account\VaultRecovery).
    $get('/admin/wiederherstellen', $freigaben, static fn(Request $r) => $wiederherstellung()->seite($r));
    $post('/admin/wiederherstellen', $freigaben, static fn(Request $r) => $wiederherstellung()->absenden($r));

    // Blob storage (02 "Dateien", issue #12): which backend new files go to,
    // moving the stock over, integrity check. The chain copies ciphertext and
    // never decrypts, so it needs no vault - but it moves every stored file.
    // Permission: `admin.settings` ("Speicher", 01 section 4); CSRF on all
    // writes.
    $einstellungen = Zugriff::recht(Permission::AdminSettings);
    $get('/admin/speicher', $einstellungen, static fn(Request $r) => $storage()->page($r));
    $post('/admin/speicher/ziel', $einstellungen, static fn(Request $r) => $storage()->setTarget($r));
    $post(
        '/admin/speicher/schritt/{schritt:[a-z]+}',
        $einstellungen,
        static fn(Request $r, array $params) => $storage()->step($r, $params),
    );

    // Mail (06 §3, issue #14): SMTP settings, a test mail, the retry queue.
    // The controller and the cron share one Mailer - the queue holds only
    // server-key ciphertext, so sending never needs a vault.
    // Permission: `admin.settings` ("Mail", 01 section 4); CSRF on all
    // writes.
    $get('/admin/mail', $einstellungen, static fn(Request $r) => $mail()->page($r));
    $post('/admin/mail/einstellungen', $einstellungen, static fn(Request $r) => $mail()->save($r));
    $post('/admin/mail/testmail', $einstellungen, static fn(Request $r) => $mail()->test($r));

    // Cost centers (02 "Fachdaten", issue #23/M4-1): CRUD and reordering.
    // Permission: `admin.settings`, the same right as Kategorien - both are
    // stammdaten, not accounts or their rights. CSRF on all writes; the
    // rules live in App\Service\MasterData\CostCenterService.
    $get('/admin/kostenstellen', $einstellungen, static fn(Request $r) => $kostenstellen()->liste($r));
    $get('/admin/kostenstellen/neu', $einstellungen, static fn(Request $r) => $kostenstellen()->neu($r));
    $post('/admin/kostenstellen', $einstellungen, static fn(Request $r) => $kostenstellen()->anlegen($r));
    $get('/admin/kostenstellen/{id:\d+}', $einstellungen, static fn(Request $r, array $params) => $kostenstellen()->bearbeiten($r, $params));
    $post('/admin/kostenstellen/{id:\d+}', $einstellungen, static fn(Request $r, array $params) => $kostenstellen()->speichern($r, $params));
    $post('/admin/kostenstellen/{id:\d+}/loeschen', $einstellungen, static fn(Request $r, array $params) => $kostenstellen()->loeschen($r, $params));
    $post('/admin/kostenstellen/{id:\d+}/nach-oben', $einstellungen, static fn(Request $r, array $params) => $kostenstellen()->nachOben($r, $params));
    $post('/admin/kostenstellen/{id:\d+}/nach-unten', $einstellungen, static fn(Request $r, array $params) => $kostenstellen()->nachUnten($r, $params));

    // Categories (02 "Kategorien", issue #35/M6-1): CRUD and reordering
    // within a direction. Permission: `admin.settings`, the same right as
    // Kostenstellen. CSRF on all writes; the rules live in
    // App\Service\MasterData\CategoryService.
    $get('/admin/kategorien', $einstellungen, static fn(Request $r) => $kategorien()->liste($r));
    $get('/admin/kategorien/neu', $einstellungen, static fn(Request $r) => $kategorien()->neu($r));
    $post('/admin/kategorien', $einstellungen, static fn(Request $r) => $kategorien()->anlegen($r));
    $get('/admin/kategorien/{id:\d+}', $einstellungen, static fn(Request $r, array $params) => $kategorien()->bearbeiten($r, $params));
    $post('/admin/kategorien/{id:\d+}', $einstellungen, static fn(Request $r, array $params) => $kategorien()->speichern($r, $params));
    $post('/admin/kategorien/{id:\d+}/loeschen', $einstellungen, static fn(Request $r, array $params) => $kategorien()->loeschen($r, $params));
    $post('/admin/kategorien/{id:\d+}/nach-oben', $einstellungen, static fn(Request $r, array $params) => $kategorien()->nachOben($r, $params));
    $post('/admin/kategorien/{id:\d+}/nach-unten', $einstellungen, static fn(Request $r, array $params) => $kategorien()->nachUnten($r, $params));

    // AI provider profiles (03 section 6 "Client", issue #41/M7-1): CRUD,
    // the default profile, the optional fallback profile (issue #180/M7-2b)
    // and the connection test. Permission:
    // `admin.settings` ("KI", 01 section 4). CSRF on all writes; the rules
    // live in App\Service\Ki\KiAnbieterService. The API key is server-key
    // ciphertext, no vault needed.
    // The optional worker, pairing part (issue #51/M7b-1, 07 section 4):
    // pairing code, fingerprint confirmation, unpairing. Permission:
    // `admin.settings`, the same right as the AI providers. CSRF on all
    // writes; the code is shown once and never stored in plaintext.
    $get('/admin/worker', $einstellungen, static fn(Request $r) => $workerAdmin()->page($r));
    $post('/admin/worker/koppeln', $einstellungen, static fn(Request $r) => $workerAdmin()->koppeln($r));
    $post('/admin/worker/einstellungen', $einstellungen, static fn(Request $r) => $workerAdmin()->einstellungen($r));
    $post('/admin/worker/ocr-nachholen', $einstellungen, static fn(Request $r) => $workerAdmin()->ocrNachholen($r));
    $post('/admin/worker/{id:\d+}/bestaetigen', $einstellungen, static fn(Request $r, array $params) => $workerAdmin()->bestaetigen($r, $params));
    $post('/admin/worker/{id:\d+}/entkoppeln', $einstellungen, static fn(Request $r, array $params) => $workerAdmin()->entkoppeln($r, $params));

    $get('/admin/ki-anbieter', $einstellungen, static fn(Request $r) => $kiAnbieter()->liste($r));
    $get('/admin/ki-protokoll', $einstellungen, static fn(Request $r) => $kiAnbieter()->protokoll($r));
    $get('/admin/ki-anbieter/neu', $einstellungen, static fn(Request $r) => $kiAnbieter()->neu($r));
    $post('/admin/ki-anbieter', $einstellungen, static fn(Request $r) => $kiAnbieter()->anlegen($r));
    $get('/admin/ki-anbieter/{id:\d+}', $einstellungen, static fn(Request $r, array $params) => $kiAnbieter()->bearbeiten($r, $params));
    $post('/admin/ki-anbieter/{id:\d+}', $einstellungen, static fn(Request $r, array $params) => $kiAnbieter()->speichern($r, $params));
    $post('/admin/ki-anbieter/{id:\d+}/loeschen', $einstellungen, static fn(Request $r, array $params) => $kiAnbieter()->loeschen($r, $params));
    $post('/admin/ki-anbieter/{id:\d+}/standard', $einstellungen, static fn(Request $r, array $params) => $kiAnbieter()->standard($r, $params));
    $post('/admin/ki-anbieter/{id:\d+}/fallback', $einstellungen, static fn(Request $r, array $params) => $kiAnbieter()->fallback($r, $params));
    $post('/admin/ki-anbieter/fallback-entfernen', $einstellungen, static fn(Request $r) => $kiAnbieter()->fallbackEntfernen($r));
    $post('/admin/ki-anbieter/{id:\d+}/testen', $einstellungen, static fn(Request $r, array $params) => $kiAnbieter()->testen($r, $params));

    // Public submission's spam defence (01 §5, issue #25/M4-3): rate/size/
    // page limits and the pause switch. Permission: `admin.settings`, same
    // right as Mail/Speicher/Kostenstellen. CSRF on all writes.
    $get('/admin/einreichung', $einstellungen, static fn(Request $r) => $einreichungAdmin()->page($r));
    $post('/admin/einreichung', $einstellungen, static fn(Request $r) => $einreichungAdmin()->save($r));
    $post('/admin/einreichung/pausieren', $einstellungen, static fn(Request $r) => $einreichungAdmin()->pausieren($r));
    $post('/admin/einreichung/fortsetzen', $einstellungen, static fn(Request $r) => $einreichungAdmin()->fortsetzen($r));

    // ZIP export path pattern (05 §2, issue #75/M12-1): folders and file
    // names of the export. Permission: `admin.settings`, same right as
    // Einreichung. The preview uses made-up receipts, so no vault; CSRF on
    // both writes (the preview is a POST because it carries the input).
    $get('/admin/export', $einstellungen, static fn(Request $r) => $exportAdmin()->page($r));
    $post('/admin/export', $einstellungen, static fn(Request $r) => $exportAdmin()->save($r));
    $post('/admin/export/vorschau', $einstellungen, static fn(Request $r) => $exportAdmin()->vorschau($r));
    // Nextcloud target of the export (05 §2, issue #95): WebDAV address,
    // user, app password - vault data, so the page needs the unlocked
    // vault. Permission: `admin.settings`; CSRF on all writes.
    $get('/admin/export/nextcloud', $einstellungen, static fn(Request $r) => $nextcloudAdmin()->page($r));
    $post('/admin/export/nextcloud', $einstellungen, static fn(Request $r) => $nextcloudAdmin()->save($r));
    $post('/admin/export/nextcloud/testen', $einstellungen, static fn(Request $r) => $nextcloudAdmin()->testen($r));
    $post('/admin/export/nextcloud/entfernen', $einstellungen, static fn(Request $r) => $nextcloudAdmin()->entfernen($r));

    // Update and maintenance. Permission: `admin.system` ("Backup, Update,
    // Wartung", 01 section 4); CSRF on all writes.
    $system = Zugriff::recht(Permission::AdminSystem);
    $get('/admin/update', $system, static fn(Request $r) => $updates()->page($r));
    $post('/admin/update/kanal', $system, static fn(Request $r) => $updates()->setChannel($r));
    $post('/admin/update/reset', $system, static fn(Request $r) => $updates()->resetState($r));
    $post(
        '/admin/update/schritt/{schritt:[a-z]+}',
        $system,
        static fn(Request $r, array $params) => $updates()->step($r, $params),
    );
    $post('/admin/wartung/aufheben', $system, static fn(Request $r) => $updates()->releaseMaintenance($r));

    // Backups (06 section 2, issue #112): list, create as a step chain,
    // download. Permission: `admin.system`, like Update ("Backup, Update,
    // Wartung", 01 section 4); CSRF on all writes. The name pattern only
    // pre-filters - BackupService::path() is what guards against
    // traversal. A backup with config.php is downloaded only by POST with
    // the account's password (BackupController).
    $backupName = '{name:backup_[0-9_]+\\.zip}';
    $get('/admin/backups', $system, static fn(Request $r) => $backups()->page($r));
    $post('/admin/backups', $system, static fn(Request $r) => $backups()->starten($r));
    $post('/admin/backups/schritt', $system, static fn(Request $r) => $backups()->schritt($r));
    $post('/admin/backups/abbrechen', $system, static fn(Request $r) => $backups()->abbrechen($r));
    $get('/admin/backups/' . $backupName, $system, static fn(Request $r, array $p) => $backups()->download($r, $p));
    $post('/admin/backups/' . $backupName, $system, static fn(Request $r, array $p) => $backups()->downloadBestaetigt($r, $p));

    // System check (06 section 5, issue #107/M3-10): do the M0 hosting
    // assumptions still hold? Read only, so no CSRF and no write route.
    // Permission: `admin.system`, the same right as Update - the findings are
    // what an update or a maintenance run depends on.
    $get('/admin/systemcheck', $system, static fn(Request $r) => $systemcheck()->page($r));
};
