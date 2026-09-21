<?php

declare(strict_types=1);

use App\Admin\UpdateController;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\View\View;

/**
 * Route table of the installed application.
 *
 * Rights: every route that does more than render a public page gets its
 * permission declared here once the Permission enum exists (CLAUDE.md
 * section 4, milestone M3-6) - checked server side per action, never only
 * hidden in the UI. The /admin routes below are the ones waiting for it;
 * until M3-3 they carry no authentication at all, which the page itself
 * says out loud and UpdateController documents.
 *
 * @param \Closure(): UpdateController $updates built lazily: it opens the
 *        database connection, and the public routes must not pay for that.
 */
return static function (Router $router, View $view, \Closure $updates): void {
    $router->get('/', static fn(Request $request, array $params): Response => Response::html(
        $view->render('home', ['title' => '']),
    ));

    // The admin area has exactly one page at this milestone.
    $router->get('/admin', static fn(): Response => Response::redirect('/admin/update'));

    $router->get('/admin/update', static fn(Request $r) => $updates()->page($r));
    $router->post('/admin/update/kanal', static fn(Request $r) => $updates()->setChannel($r));
    $router->post('/admin/update/reset', static fn(Request $r) => $updates()->resetState($r));
    $router->post(
        '/admin/update/schritt/{schritt:[a-z]+}',
        static fn(Request $r, array $params) => $updates()->step($r, $params),
    );
    $router->post('/admin/wartung/aufheben', static fn(Request $r) => $updates()->releaseMaintenance($r));
};
