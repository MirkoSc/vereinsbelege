<?php

declare(strict_types=1);

use App\Admin\UpdateController;
use App\Api\CronController;
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
 */
return static function (Router $router, View $view, \Closure $updates, \Closure $cron): void {
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

    $router->get('/admin', static fn(): Response => Response::redirect('/admin/update'));

    // Pattern page of the design system - the reference for new pages and
    // the place where the light/dark and 360 px checks happen.
    // Permission: administration, from M3-6 on.
    $router->get('/admin/designsystem', static fn(): Response => Response::html(
        $view->render('admin/designsystem', ['title' => 'Designsystem'], Area::Admin),
    ));

    $router->get('/admin/update', static fn(Request $r) => $updates()->page($r));
    $router->post('/admin/update/kanal', static fn(Request $r) => $updates()->setChannel($r));
    $router->post('/admin/update/reset', static fn(Request $r) => $updates()->resetState($r));
    $router->post(
        '/admin/update/schritt/{schritt:[a-z]+}',
        static fn(Request $r, array $params) => $updates()->step($r, $params),
    );
    $router->post('/admin/wartung/aufheben', static fn(Request $r) => $updates()->releaseMaintenance($r));
};
