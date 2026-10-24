<?php

declare(strict_types=1);

namespace App\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Service\SystemCheck\SystemCheck;
use App\View\Area;
use App\View\View;

/**
 * Admin page "Systemcheck" (M3-10, issue #107, docs/spec/06-betrieb.md
 * section 5): whether the assumptions of the M0 hosting check still hold on
 * the running system.
 *
 * Read only, so no CSRF token and no audit entry. Nothing in here needs the
 * vault - the values are ini settings and server variables, none of them
 * business data - which is why the page also works for an admin whose vault
 * is locked.
 *
 * ---------------------------------------------------------------------
 * RIGHT REQUIRED: `admin.system` (docs/spec/01-sicherheit.md section 4,
 * "System"), declared on the route and checked by App\Http\LoginGuard
 * (app/src/routes.php) - the same right as Update and Wartung, because the
 * findings are the hoster's settings an update or a maintenance run depends
 * on.
 * ---------------------------------------------------------------------
 */
final readonly class SystemCheckController
{
    public function __construct(
        private View $view,
        private Session $session,
        private SystemCheck $check,
    ) {
    }

    public function page(Request $request): ResponseInterface
    {
        $this->session->start();

        // One run for both the table and the JSON: the write probe on
        // shared/var must not happen twice, and the two views must agree.
        $ergebnisse = $this->check->all($this->origin($request));

        return Response::html($this->view->render('admin/systemcheck', [
            'title' => 'Systemcheck',
            // External file, never inline: script-src 'self' without
            // 'unsafe-inline' (CLAUDE.md section 4).
            'scripts' => ['/js/systemcheck.js'],
            'ergebnisse' => $ergebnisse,
            'gesamt' => SystemCheck::worstOf($ergebnisse),
            'json' => SystemCheck::toJson($ergebnisse),
        ], Area::Admin));
    }

    /**
     * Where the config exposure check (issue #101) knocks: the domain this
     * page was opened under. Same rule as UpdateController::baseUrl(): the
     * Host header is client controlled, so it is accepted only in the shape
     * of a host name with an optional port; the request is a GET whose body
     * is discarded, and the route needs `admin.system`.
     */
    private function origin(Request $request): string
    {
        $host = (string) ($request->header('host') ?? '');
        if (preg_match('/^[A-Za-z0-9.-]+(:\d{1,5})?$/', $host) !== 1) {
            $host = 'localhost';
        }

        return (Request::httpsFromGlobals() ? 'https://' : 'http://') . $host;
    }
}
