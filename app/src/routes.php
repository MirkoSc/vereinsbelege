<?php

declare(strict_types=1);

use App\Admin\CostCenterController;
use App\Admin\MailController;
use App\Admin\RoleController;
use App\Admin\UserController;
use App\Admin\VaultGrantController;
use App\Admin\VaultRecoveryController;
use App\Admin\StorageController;
use App\Admin\SubmissionSettingsController;
use App\Admin\UpdateController;
use App\Api\CronController;
use App\Api\EinreichungUploadController;
use App\Api\UploadController;
use App\App\AuditController;
use App\App\AuthController;
use App\App\ErfassungController;
use App\App\InboxController;
use App\App\InvitationController;
use App\App\MfaController;
use App\App\PasswordController;
use App\App\SecurityController;
use App\Domain\Berechtigungen;
use App\Domain\Permission;
use App\Http\HttpMethod;
use App\Http\LoginGuard;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Http\Zugriff;
use App\PublicPages\EinreichungController;
use App\View\Area;
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
): void {
    // Registers a route with its declaration and wraps the handler in the
    // guard check that declaration asks for - one value, both jobs. The
    // guard is built once per request and only where a protected route was
    // actually matched: pruefe() returns a wrapper, it runs nothing yet.
    $route = static function (HttpMethod $methode, string $muster, Zugriff $zugriff, \Closure $handler) use ($router, $guard): void {
        $router->add(
            $methode,
            $muster,
            $zugriff,
            $zugriff->brauchtAnmeldung()
                ? static fn(Request $r, array $p = []) => $guard()->pruefe($zugriff, $handler)($r, $p)
                : $handler,
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

    // Cron entry point for the host's control panel (06 section 4, issue #99).
    // Permission: cron token - no session, no login. The shared secret
    // cron_token from shared/config.php is the only credential, compared with
    // hash_equals. The cron never decrypts (CLAUDE.md section 4).
    $get('/cron', Zugriff::cron(), static fn(Request $r) => $cron()->run($r));

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

    // Permission: any `admin.*` right; sends the account to the first admin
    // page it may open (App\View\Area::adminStartFuer()).
    $get('/admin', Zugriff::adminBereich(), static fn(): Response => Response::redirect(
        Area::adminStartFuer($view->berechtigungen() ?? Berechtigungen::keine()),
    ));

    // Pattern page of the design system - the reference for new pages and
    // the place where the light/dark and 360 px checks happen.
    // Permission: any `admin.*` right.
    $get('/admin/designsystem', Zugriff::adminBereich(), static fn(): Response => Response::html(
        $view->render('admin/designsystem', ['title' => 'Designsystem'], Area::Admin),
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

    // Public submission's spam defence (01 §5, issue #25/M4-3): rate/size/
    // page limits and the pause switch. Permission: `admin.settings`, same
    // right as Mail/Speicher/Kostenstellen. CSRF on all writes.
    $get('/admin/einreichung', $einstellungen, static fn(Request $r) => $einreichungAdmin()->page($r));
    $post('/admin/einreichung', $einstellungen, static fn(Request $r) => $einreichungAdmin()->save($r));
    $post('/admin/einreichung/pausieren', $einstellungen, static fn(Request $r) => $einreichungAdmin()->pausieren($r));
    $post('/admin/einreichung/fortsetzen', $einstellungen, static fn(Request $r) => $einreichungAdmin()->fortsetzen($r));

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
};
