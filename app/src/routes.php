<?php

declare(strict_types=1);

use App\Admin\StorageController;
use App\Admin\UpdateController;
use App\Api\CronController;
use App\Api\UploadController;
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
 * hidden in the UI. The /app and /admin routes below are the ones waiting
 * for it; until M3-3 they carry no authentication at all, which the admin
 * chrome says out loud and UpdateController documents.
 *
 * @param \Closure(): UpdateController $updates built lazily: it opens the
 *        database connection, and the public routes must not pay for that.
 * @param \Closure(): CronController $cron built lazily for the same reason.
 * @param \Closure(): UploadController $uploads built lazily as well - opening
 *        and storing a chunk needs no database at all.
 * @param \Closure(): StorageController $storage built lazily, same reason as
 *        $updates: the storage page is the only thing that needs it.
 */
return static function (
    Router $router,
    View $view,
    \Closure $updates,
    \Closure $cron,
    \Closure $uploads,
    \Closure $storage,
): void {
    $router->get('/', static fn(Request $request, array $params): Response => Response::html(
        $view->render('home', ['title' => ''], Area::Oeffentlich),
    ));

    // Start page of the user area. The areas behind it (Posteingang, Belege,
    // Konten, ...) arrive from milestone M4 on; they are already in the
    // navigation as inactive entries (App\View\Area::navigation()).
    // Permission: requires a logged-in user from M3-3 on.
    $router->get('/app', static fn(): Response => Response::html(
        $view->render('app/start', ['title' => ''], Area::App),
    ));

    // Cron entry point for the host's control panel (06 section 4, issue #99).
    // Permission: none in the Permission sense - no session, no login. The
    // shared secret cron_token from shared/config.php is the only credential,
    // compared with hash_equals. The cron never decrypts (CLAUDE.md section 4).
    $router->get('/cron', static fn(Request $r) => $cron()->run($r));

    // Chunk upload (03 section 4, issue #11). Files arrive in 2 MiB pieces so
    // that no request runs long and the hoster's upload limit stops mattering.
    // Permission: none yet - there is no login (M3-3) and no Permission enum
    // (M3-6); the CSRF token of the session is the only credential, so today
    // these routes are usable from /app and /admin. From M3-6 on:
    // `document.submit_internal`. The public submission (/einreichen, M5) has
    // no session and needs the proof of work and the rate limit of 01 before
    // it may open an upload without a token.
    //
    // The id placeholder is [0-9a-f]+ and not [0-9a-f]{32}: Route::compile()
    // reads a placeholder's regex up to the first brace, so a quantifier in
    // there would not compile. The exact length is checked where it has to be
    // anyway - UploadService validates the id before it becomes a path, and
    // anything else is a 404.
    $router->post('/api/upload', static fn(Request $r) => $uploads()->create($r));
    $router->post(
        '/api/upload/{id:[0-9a-f]+}/chunk/{n:\d+}',
        static fn(Request $r, array $params) => $uploads()->chunk($r, $params),
    );
    $router->post(
        '/api/upload/{id:[0-9a-f]+}/finish',
        static fn(Request $r, array $params) => $uploads()->finish($r, $params),
    );
    $router->post(
        '/api/upload/{id:[0-9a-f]+}/abort',
        static fn(Request $r, array $params) => $uploads()->abort($r, $params),
    );

    $router->get('/admin', static fn(): Response => Response::redirect('/admin/update'));

    // Pattern page of the design system - the reference for new pages and
    // the place where the light/dark and 360 px checks happen.
    // Permission: administration, from M3-6 on.
    $router->get('/admin/designsystem', static fn(): Response => Response::html(
        $view->render('admin/designsystem', ['title' => 'Designsystem'], Area::Admin),
    ));

    // Blob storage (02 "Dateien", issue #12): which backend new files go to,
    // moving the stock over, integrity check. The chain copies ciphertext and
    // never decrypts, so it needs no vault - but it moves every stored file.
    // Permission: administration, from M3-6 on; CSRF on all writes today.
    $router->get('/admin/speicher', static fn(Request $r) => $storage()->page($r));
    $router->post('/admin/speicher/ziel', static fn(Request $r) => $storage()->setTarget($r));
    $router->post(
        '/admin/speicher/schritt/{schritt:[a-z]+}',
        static fn(Request $r, array $params) => $storage()->step($r, $params),
    );

    $router->get('/admin/update', static fn(Request $r) => $updates()->page($r));
    $router->post('/admin/update/kanal', static fn(Request $r) => $updates()->setChannel($r));
    $router->post('/admin/update/reset', static fn(Request $r) => $updates()->resetState($r));
    $router->post(
        '/admin/update/schritt/{schritt:[a-z]+}',
        static fn(Request $r, array $params) => $updates()->step($r, $params),
    );
    $router->post('/admin/wartung/aufheben', static fn(Request $r) => $updates()->releaseMaintenance($r));
};
