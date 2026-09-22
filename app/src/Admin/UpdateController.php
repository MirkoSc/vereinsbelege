<?php

declare(strict_types=1);

namespace App\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Service\MaintenanceMode;
use App\Service\Update\UpdateService;
use App\Service\Update\UpdateState;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * Admin page for the update step chain: the page's JavaScript calls one
 * endpoint per step; on an error it offers a retry (steps are idempotent)
 * or a rollback.
 *
 * It also owns the manual release of the maintenance flag. A crashed update
 * can leave the flag behind, and without this button the only way to clear
 * it would be FTP.
 *
 * ---------------------------------------------------------------------
 * LOGIN REQUIRED since M3-3, rights still missing. Every route below is
 * wrapped in App\Http\LoginGuard (app/src/routes.php), so no anonymous
 * visitor can switch a release or roll one back anymore. What is not there
 * yet is WHICH account may: roles and the Permission enum are M3-6
 * (docs/spec/01-sicherheit.md section 4), so until then every account that
 * can log in can do this. CSRF is enforced regardless, so no foreign page
 * can trigger a step in a logged-in user's browser.
 * ---------------------------------------------------------------------
 */
final readonly class UpdateController
{
    public function __construct(
        private View $view,
        private Session $session,
        private UpdateService $updates,
        private MaintenanceMode $maintenance,
    ) {
    }

    public function page(Request $request): ResponseInterface
    {
        $this->session->start();

        return Response::html($this->view->render('admin/update', [
            'title' => 'Update',
            // External file, never inline: script-src 'self' without
            // 'unsafe-inline' (CLAUDE.md section 4).
            'scripts' => ['/js/update.js'],
            'csrf' => $this->session->csrfToken(),
            'flash' => $this->session->pullFlash(),
            'state' => $this->updates->state(),
            'kanal' => $this->updates->channel(),
            'wartung' => $this->maintenance->state(),
            'schritte' => UpdateService::STEPS,
        ], Area::Admin));
    }

    public function setChannel(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure();
        }

        $this->updates->setChannel((string) ($request->post['kanal'] ?? 'stable'));
        // A half-finished chain belongs to the old channel's release.
        $this->updates->reset();
        $this->session->flash('Update-Kanal gespeichert.');

        return Response::redirect('/admin/update');
    }

    /**
     * One step per request (CLAUDE.md section 1: no long-running request).
     *
     * @param array<string, string> $params
     */
    public function step(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return Response::json(['fehler' => 'Sitzung abgelaufen – bitte die Seite neu laden.'], 403);
        }

        try {
            $state = match ($params['schritt']) {
                'check' => $this->updates->check(),
                'download' => $this->updates->download(),
                'extract' => $this->updates->extract(),
                'backup' => $this->updates->backup(),
                'switch' => $this->updates->switchRelease(),
                'migrate' => $this->updates->migrate(),
                'finish' => $this->updates->finish($this->baseUrl($request)),
                'rollback' => $this->updates->rollback(),
                default => null,
            };
        } catch (\Throwable $e) {
            return Response::json(['fehler' => $e->getMessage()], 500);
        }

        if (!$state instanceof UpdateState) {
            return Response::json(['fehler' => 'Unbekannter Schritt.'], 404);
        }

        return Response::json($state->toArray(), $state->fehler !== null ? 500 : 200);
    }

    public function resetState(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure();
        }

        $this->updates->reset();
        $this->session->flash('Update-Status zurückgesetzt.');

        return Response::redirect('/admin/update');
    }

    public function releaseMaintenance(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure();
        }

        $this->maintenance->disable();
        $this->session->flash('Wartungsmodus aufgehoben – die Seite ist wieder öffentlich erreichbar.');

        // Fixed target, deliberately not a "back to where you came from"
        // parameter: that would be an open redirect to save one click.
        return Response::redirect('/admin/update');
    }

    private function csrfFailure(): ResponseInterface
    {
        $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

        return Response::redirect('/admin/update');
    }

    /**
     * Own address for the self-test. It can only come from the request:
     * nothing configures the site's URL, and the installation may be
     * reached under any (sub-)domain the hoster points at it.
     *
     * The Host header is client controlled, so it is accepted only in the
     * shape of a host name with an optional port - that keeps the self-test
     * from being turned into a request to an arbitrary URL. It can still
     * name a foreign host, which is one more reason why this route belongs
     * behind the login of M3-3.
     */
    private function baseUrl(Request $request): string
    {
        $host = (string) ($request->header('host') ?? '');
        if (preg_match('/^[A-Za-z0-9.-]+(:\d{1,5})?$/', $host) !== 1) {
            $host = 'localhost';
        }

        return (Request::httpsFromGlobals() ? 'https://' : 'http://') . $host;
    }
}
