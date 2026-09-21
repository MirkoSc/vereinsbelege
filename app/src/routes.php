<?php

declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\View\View;

/**
 * Route table. Every route that does more than render a static page gets its
 * permission declared here once the permission enum exists (CLAUDE.md
 * section 4, milestone M3) - rights are checked server side per action, never
 * only hidden in the UI.
 */
return static function (Router $router, View $view): void {
    $router->get('/', static fn(Request $request, array $params): Response => Response::html(
        $view->render('home', ['title' => '']),
    ));
};
