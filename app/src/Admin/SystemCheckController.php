<?php

declare(strict_types=1);

namespace App\Admin;

use App\Api\CronController;
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
 *
 * Below the findings the page shows the cron URL with the token from
 * shared/config.php (issue #244), so an admin can set up or repair the
 * hoster's cronjob without FTP - after a restore the token is a new one.
 * It is deliberately not one of the findings: those end up in the JSON that
 * is meant to be copied into an issue. Nothing logs it.
 */
final readonly class SystemCheckController
{
    public function __construct(
        private View $view,
        private Session $session,
        private SystemCheck $check,
        #[\SensitiveParameter] private ?string $cronToken = null,
    ) {
    }

    public function page(Request $request): ResponseInterface
    {
        $this->session->start();

        // One run for both the table and the JSON: the write probe on
        // shared/var must not happen twice, and the two views must agree.
        // The origin is where the config exposure check (issue #101)
        // knocks: the domain this page was opened under.
        $ergebnisse = $this->check->all($request->ownOrigin());

        $cronUrl = $this->cronToken === null || $this->cronToken === ''
            ? null
            : CronController::url($request->ownOrigin(), $this->cronToken);

        // Not cached although it carries the token: the session's cache
        // limiter sends no-store on every admin page (Session::OPTIONEN).
        return Response::html($this->view->render('admin/systemcheck', [
            'title' => 'Systemcheck',
            'cronUrl' => $cronUrl,
            // External file, never inline: script-src 'self' without
            // 'unsafe-inline' (CLAUDE.md section 4).
            'scripts' => ['/js/systemcheck.js'],
            'ergebnisse' => $ergebnisse,
            'gesamt' => SystemCheck::worstOf($ergebnisse),
            'json' => SystemCheck::toJson($ergebnisse),
        ], Area::Admin));
    }
}
