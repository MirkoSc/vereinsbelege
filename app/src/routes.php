<?php

declare(strict_types=1);

use App\Admin\MailController;
use App\Admin\StorageController;
use App\Admin\UpdateController;
use App\Api\CronController;
use App\Api\UploadController;
use App\App\AuthController;
use App\App\MfaController;
use App\App\SecurityController;
use App\Http\LoginGuard;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\View\Area;
use App\View\View;

/**
 * Route table of the installed application.
 *
 * Every route names its area (App\View\Area): that picks the chrome, and it
 * decides whether the page may have a session at all - the public area never
 * starts one (App\Http\Session).
 *
 * Rights: every route that does more than render a public page gets its
 * permission declared here once the Permission enum exists (CLAUDE.md
 * section 4, milestone M3-6) - checked server side per action, never only
 * hidden in the UI. Since M3-3 the coarse half of that is real: everything
 * behind the login is wrapped in $guard (App\Http\LoginGuard), visibly, on
 * the route itself. M3-6 refines those wrappers into per-action permissions;
 * it does not have to go looking for the routes that need one.
 *
 * @param \Closure(): AuthController $auth built lazily like the rest - the
 *        login form itself renders without a database, only the attempt
 *        behind it needs one.
 * @param \Closure(): LoginGuard $guard the gate in front of /app, /admin and
 *        the upload API.
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
): void {
    // The guard is built once per request and only where a protected route
    // was actually matched: page()/api() return a wrapper, they do not run
    // anything yet.
    $geschuetzt = static fn(\Closure $handler): \Closure => static fn(Request $r, array $p = [])
        => $guard()->page($handler)($r, $p);
    $geschuetzteApi = static fn(\Closure $handler): \Closure => static fn(Request $r, array $p = [])
        => $guard()->api($handler)($r, $p);

    $router->get('/', static fn(Request $request, array $params): Response => Response::html(
        $view->render('home', ['title' => ''], Area::Oeffentlich),
    ));

    // Login and logout (M3-3, docs/spec/01-sicherheit.md section 3).
    // Permission: none - these are the way in. They are the only public
    // pages with a session (they need a CSRF token), and both writes carry
    // it; the brute force protection is the rate limit inside LoginService,
    // per IP and per account.
    $router->get('/anmelden', static fn(Request $r) => $auth()->form($r));
    $router->post('/anmelden', static fn(Request $r) => $auth()->submit($r));
    $router->post('/abmelden', static fn(Request $r) => $auth()->logout($r));

    // Second factor (M3-4, docs/spec/01-sicherheit.md section 3, issue #17).
    // Permission: none, same reasoning as /anmelden - the account is not
    // logged in yet in the App\Http\Session sense, only in the pending-login
    // sense (App\Service\Account\PendingLogin). Rate limiting is
    // App\Service\Account\MfaService's own counters, per IP and per account,
    // the same shape as LoginService's.
    $router->get('/anmelden/bestaetigen', static fn(Request $r) => $mfa()->form($r));
    $router->post('/anmelden/bestaetigen', static fn(Request $r) => $mfa()->submitCode($r));
    $router->post('/anmelden/code-senden', static fn(Request $r) => $mfa()->sendEmailCode($r));
    $router->post('/anmelden/backup-code', static fn(Request $r) => $mfa()->submitBackupCode($r));

    // Start page of the user area. The areas behind it (Posteingang, Belege,
    // Konten, ...) arrive from milestone M4 on; they are already in the
    // navigation as inactive entries (App\View\Area::navigation()).
    // Permission: logged in. Which role may see what follows is M3-6.
    $router->get('/app', $geschuetzt(static fn(): Response => Response::html(
        $view->render('app/start', ['title' => ''], Area::App),
    )));

    // Cron entry point for the host's control panel (06 section 4, issue #99).
    // Permission: none in the Permission sense - no session, no login. The
    // shared secret cron_token from shared/config.php is the only credential,
    // compared with hash_equals. The cron never decrypts (CLAUDE.md section 4).
    $router->get('/cron', static fn(Request $r) => $cron()->run($r));

    // Chunk upload (03 section 4, issue #11). Files arrive in 2 MiB pieces so
    // that no request runs long and the hoster's upload limit stops mattering.
    // Permission: logged in (M3-3); from M3-6 on `document.submit_internal`.
    // The guard answers 401 JSON here instead of redirecting - these routes
    // are driven from fetch(), where a login page would arrive as garbage.
    // The public submission (/einreichen, M5) has no session and needs the
    // proof of work and the rate limit of 01 before it may open an upload
    // without a token; it will not go through this guard.
    //
    // The id placeholder is [0-9a-f]+ and not [0-9a-f]{32}: Route::compile()
    // reads a placeholder's regex up to the first brace, so a quantifier in
    // there would not compile. The exact length is checked where it has to be
    // anyway - UploadService validates the id before it becomes a path, and
    // anything else is a 404.
    $router->post('/api/upload', $geschuetzteApi(static fn(Request $r) => $uploads()->create($r)));
    $router->post(
        '/api/upload/{id:[0-9a-f]+}/chunk/{n:\d+}',
        $geschuetzteApi(static fn(Request $r, array $params) => $uploads()->chunk($r, $params)),
    );
    $router->post(
        '/api/upload/{id:[0-9a-f]+}/finish',
        $geschuetzteApi(static fn(Request $r, array $params) => $uploads()->finish($r, $params)),
    );
    $router->post(
        '/api/upload/{id:[0-9a-f]+}/abort',
        $geschuetzteApi(static fn(Request $r, array $params) => $uploads()->abort($r, $params)),
    );

    // Managing an already logged-in account's second factor (M3-4, issue
    // #17). Permission: logged in (M3-3); administration is not needed -
    // every account manages its own factor. CSRF on all writes. The guard
    // (App\Http\LoginGuard) sends here on its own, to `/app/sicherheit/
    // einrichten`, whenever `mfa_required` has no configured method yet -
    // these routes are exactly what it exempts from that redirect.
    $router->get('/app/sicherheit', $geschuetzt(static fn(Request $r) => $sicherheit()->page($r)));
    $router->get('/app/sicherheit/einrichten', $geschuetzt(static fn(Request $r) => $sicherheit()->einrichten($r)));
    $router->post(
        '/app/sicherheit/einrichten/totp/starten',
        $geschuetzt(static fn(Request $r) => $sicherheit()->totpStarten($r)),
    );
    $router->post(
        '/app/sicherheit/einrichten/totp/bestaetigen',
        $geschuetzt(static fn(Request $r) => $sicherheit()->totpBestaetigen($r)),
    );
    $router->post(
        '/app/sicherheit/einrichten/email/starten',
        $geschuetzt(static fn(Request $r) => $sicherheit()->emailStarten($r)),
    );
    $router->post(
        '/app/sicherheit/einrichten/email/bestaetigen',
        $geschuetzt(static fn(Request $r) => $sicherheit()->emailBestaetigen($r)),
    );
    $router->post('/app/sicherheit/backup-codes/neu', $geschuetzt(static fn(Request $r) => $sicherheit()->backupCodesNeu($r)));
    $router->post(
        '/app/sicherheit/geraete/{id:\d+}/widerrufen',
        $geschuetzt(static fn(Request $r, array $params) => $sicherheit()->geraetWiderrufen($r, $params)),
    );

    $router->get('/admin', $geschuetzt(static fn(): Response => Response::redirect('/admin/update')));

    // Pattern page of the design system - the reference for new pages and
    // the place where the light/dark and 360 px checks happen.
    // Permission: logged in (M3-3); administration, from M3-6 on.
    $router->get('/admin/designsystem', $geschuetzt(static fn(): Response => Response::html(
        $view->render('admin/designsystem', ['title' => 'Designsystem'], Area::Admin),
    )));

    // Blob storage (02 "Dateien", issue #12): which backend new files go to,
    // moving the stock over, integrity check. The chain copies ciphertext and
    // never decrypts, so it needs no vault - but it moves every stored file.
    // Permission: logged in (M3-3), administration from M3-6 on; CSRF on
    // all writes.
    $router->get('/admin/speicher', $geschuetzt(static fn(Request $r) => $storage()->page($r)));
    $router->post('/admin/speicher/ziel', $geschuetzt(static fn(Request $r) => $storage()->setTarget($r)));
    $router->post(
        '/admin/speicher/schritt/{schritt:[a-z]+}',
        $geschuetzt(static fn(Request $r, array $params) => $storage()->step($r, $params)),
    );

    // Mail (06 §3, issue #14): SMTP settings, a test mail, the retry queue.
    // The controller and the cron share one Mailer - the queue holds only
    // server-key ciphertext, so sending never needs a vault.
    // Permission: logged in (M3-3), administration from M3-6 on; CSRF on
    // all writes.
    $router->get('/admin/mail', $geschuetzt(static fn(Request $r) => $mail()->page($r)));
    $router->post('/admin/mail/einstellungen', $geschuetzt(static fn(Request $r) => $mail()->save($r)));
    $router->post('/admin/mail/testmail', $geschuetzt(static fn(Request $r) => $mail()->test($r)));

    // Permission: logged in (M3-3), administration from M3-6 on; CSRF on
    // all writes.
    $router->get('/admin/update', $geschuetzt(static fn(Request $r) => $updates()->page($r)));
    $router->post('/admin/update/kanal', $geschuetzt(static fn(Request $r) => $updates()->setChannel($r)));
    $router->post('/admin/update/reset', $geschuetzt(static fn(Request $r) => $updates()->resetState($r)));
    $router->post(
        '/admin/update/schritt/{schritt:[a-z]+}',
        $geschuetzt(static fn(Request $r, array $params) => $updates()->step($r, $params)),
    );
    $router->post('/admin/wartung/aufheben', $geschuetzt(static fn(Request $r) => $updates()->releaseMaintenance($r)));
};
