<?php

declare(strict_types=1);

namespace App\Admin;

use App\Domain\Permission;
use App\Domain\PermissionScope;
use App\Domain\Role;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\RoleRepository;
use App\Service\Account\RoleRuleViolation;
use App\Service\Account\RoleService;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * Admin pages for roles (M3-6, issue #19, docs/spec/01-sicherheit.md
 * section 4): the list, a new role, changing and deleting one.
 *
 * Behind `admin.users` (app/src/routes.php). Roles are plaintext operating
 * data - no vault involved. Which account holds which role is the user
 * management of M3-7; this page only shows how many do.
 *
 * Form format: `recht[<permission>]=1` for every checked right, and for the
 * rights that can be narrowed to cost centers `reichweite[<permission>]` =
 * `alle` | `kostenstelle`.
 */
final readonly class RoleController
{
    public function __construct(
        private View $view,
        private Session $session,
        private RoleRepository $rollen,
        private RoleService $service,
    ) {
    }

    public function liste(Request $request): ResponseInterface
    {
        $this->session->start();

        return Response::html($this->view->render('admin/rollen', [
            'title' => 'Rollen',
            'flash' => $this->session->pullFlash(),
            'rollen' => $this->rollen->all(),
            'anzahl' => $this->rollen->userCounts(),
        ], Area::Admin));
    }

    public function neu(Request $request): ResponseInterface
    {
        $this->session->start();

        return $this->formular(null, '', false, [], null);
    }

    public function anlegen(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/admin/rollen/neu');
        }

        $name = self::text($request, 'name');
        $extern = ($request->post['extern'] ?? '') === '1';
        $rechte = self::rechteAus($request);

        try {
            $this->service->anlegen($name, $extern, $rechte);
        } catch (RoleRuleViolation $e) {
            return $this->formular(null, $name, $extern, $rechte, $e->getMessage(), 422);
        }

        $this->session->flash(sprintf('Rolle „%s“ angelegt.', trim($name)));

        return Response::redirect('/admin/rollen');
    }

    /**
     * @param array<string, string> $params
     */
    public function bearbeiten(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $rolle = $this->rollen->find((int) $params['id']);
        if ($rolle === null) {
            return $this->nichtGefunden();
        }

        return $this->formular($rolle, $rolle->name, $rolle->extern, $rolle->rechte(), null);
    }

    /**
     * @param array<string, string> $params
     */
    public function speichern(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/admin/rollen/' . $id);
        }
        $rolle = $this->rollen->find($id);
        if ($rolle === null) {
            return $this->nichtGefunden();
        }

        $name = self::text($request, 'name');
        $extern = ($request->post['extern'] ?? '') === '1';
        $rechte = self::rechteAus($request);

        try {
            $this->service->aendern($id, $name, $extern, $rechte);
        } catch (RoleRuleViolation $e) {
            return $this->formular($rolle, $name, $extern, $rechte, $e->getMessage(), 422);
        }

        $this->session->flash(sprintf('Rolle „%s“ gespeichert.', $rolle->istSystem() ? $rolle->name : trim($name)));

        return Response::redirect('/admin/rollen');
    }

    /**
     * @param array<string, string> $params
     */
    public function loeschen(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/admin/rollen/' . $id);
        }

        $rolle = $this->rollen->find($id);
        try {
            $this->service->loeschen($id);
        } catch (RoleRuleViolation $e) {
            $this->session->flash($e->getMessage(), FlashArt::Fehler);

            return Response::redirect($rolle === null ? '/admin/rollen' : '/admin/rollen/' . $id);
        }

        $this->session->flash(sprintf('Rolle „%s“ gelöscht.', (string) $rolle?->name));

        return Response::redirect('/admin/rollen');
    }

    /**
     * @param array<string, PermissionScope> $rechte
     */
    private function formular(?Role $rolle, string $name, bool $extern, array $rechte, ?string $fehler, int $status = 200): ResponseInterface
    {
        return Response::html($this->view->render('admin/rolle', [
            'title' => $rolle === null ? 'Neue Rolle' : 'Rolle ' . $rolle->name,
            'rolle' => $rolle,
            'name' => $name,
            'extern' => $extern,
            'rechte' => $rechte,
            'alleRechte' => Permission::cases(),
            'fehler' => $fehler,
            'flash' => $this->session->pullFlash(),
            'anzahlKonten' => $rolle === null ? 0 : $this->rollen->countUsers($rolle->id),
        ], Area::Admin), $status);
    }

    /**
     * @return array<string, PermissionScope>
     */
    private static function rechteAus(Request $request): array
    {
        $gewaehlt = is_array($request->post['recht'] ?? null) ? $request->post['recht'] : [];
        $reichweiten = is_array($request->post['reichweite'] ?? null) ? $request->post['reichweite'] : [];

        $rechte = [];
        foreach (Permission::cases() as $recht) {
            if (($gewaehlt[$recht->value] ?? '') !== '1') {
                continue;
            }
            $rechte[$recht->value] = ($reichweiten[$recht->value] ?? '') === PermissionScope::Kostenstelle->value
                ? PermissionScope::Kostenstelle
                : PermissionScope::Alle;
        }

        return $rechte;
    }

    private static function text(Request $request, string $feld): string
    {
        $wert = $request->post[$feld] ?? '';

        return is_string($wert) ? $wert : '';
    }

    private function nichtGefunden(): ResponseInterface
    {
        return Response::html($this->view->render('error', [
            'title' => 'Rolle nicht gefunden',
            'message' => 'Diese Rolle gibt es nicht (mehr).',
            'startseite' => '/admin/rollen',
        ], Area::Admin), 404);
    }

    private function csrfFailure(string $ziel): ResponseInterface
    {
        $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

        return Response::redirect($ziel);
    }
}
