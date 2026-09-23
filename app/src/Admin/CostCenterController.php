<?php

declare(strict_types=1);

namespace App\Admin;

use App\Domain\AuditAction;
use App\Domain\CostCenter;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\CostCenterRepository;
use App\Service\Audit\AuditLog;
use App\Service\MasterData\CostCenterRuleViolation;
use App\Service\MasterData\CostCenterService;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * Admin pages for cost centers (M4-1, issue #23, docs/spec/
 * 02-datenmodell.md "Fachdaten"): the list with reordering, a new cost
 * center, changing and deleting one.
 *
 * Behind `admin.settings` (app/src/routes.php), the same right as
 * Kategorien - both are stammdaten that shape later pages (Einreichung,
 * Posteingang, Auswertungen) rather than accounts or their rights.
 * Plaintext operating data - no vault involved.
 */
final readonly class CostCenterController
{
    public function __construct(
        private View $view,
        private Session $session,
        private CostCenterRepository $kostenstellen,
        private CostCenterService $service,
        private AuditLog $audit,
    ) {
    }

    public function liste(Request $request): ResponseInterface
    {
        $this->session->start();

        return Response::html($this->view->render('admin/kostenstellen', [
            'title' => 'Kostenstellen',
            'flash' => $this->session->pullFlash(),
            'kostenstellen' => $this->kostenstellen->all(),
            'anzahl' => $this->kostenstellen->userCounts(),
        ], Area::Admin));
    }

    public function neu(Request $request): ResponseInterface
    {
        $this->session->start();

        return $this->formular(null, '', true, null);
    }

    public function anlegen(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/admin/kostenstellen/neu');
        }

        $name = self::text($request, 'name');

        try {
            $id = $this->service->anlegen($name);
        } catch (CostCenterRuleViolation $e) {
            return $this->formular(null, $name, true, $e->getMessage(), 422);
        }

        $this->audit->record(AuditAction::KostenstelleAngelegt, $this->session->userId(), $request->ip, $id, self::details(trim($name), true));
        $this->session->flash(sprintf('Kostenstelle „%s“ angelegt.', trim($name)));

        return Response::redirect('/admin/kostenstellen');
    }

    /**
     * @param array<string, string> $params
     */
    public function bearbeiten(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $kostenstelle = $this->kostenstellen->find((int) $params['id']);
        if ($kostenstelle === null) {
            return $this->nichtGefunden();
        }

        return $this->formular($kostenstelle, $kostenstelle->name, $kostenstelle->active, null);
    }

    /**
     * @param array<string, string> $params
     */
    public function speichern(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/admin/kostenstellen/' . $id);
        }
        $kostenstelle = $this->kostenstellen->find($id);
        if ($kostenstelle === null) {
            return $this->nichtGefunden();
        }

        $name = self::text($request, 'name');
        $active = ($request->post['active'] ?? '') === '1';

        try {
            $this->service->aendern($id, $name, $active);
        } catch (CostCenterRuleViolation $e) {
            return $this->formular($kostenstelle, $name, $active, $e->getMessage(), 422);
        }

        $gespeichert = $this->kostenstellen->find($id) ?? $kostenstelle;
        $this->audit->record(
            AuditAction::KostenstelleGeaendert,
            $this->session->userId(),
            $request->ip,
            $id,
            self::details($gespeichert->name, $gespeichert->active),
        );
        $this->session->flash(sprintf('Kostenstelle „%s“ gespeichert.', trim($name)));

        return Response::redirect('/admin/kostenstellen');
    }

    /**
     * @param array<string, string> $params
     */
    public function loeschen(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/admin/kostenstellen/' . $id);
        }

        $kostenstelle = $this->kostenstellen->find($id);
        try {
            $this->service->loeschen($id);
        } catch (CostCenterRuleViolation $e) {
            $this->session->flash($e->getMessage(), FlashArt::Fehler);

            return Response::redirect($kostenstelle === null ? '/admin/kostenstellen' : '/admin/kostenstellen/' . $id);
        }

        $this->audit->record(AuditAction::KostenstelleGeloescht, $this->session->userId(), $request->ip, $id, ['name' => (string) $kostenstelle?->name]);
        $this->session->flash(sprintf('Kostenstelle „%s“ gelöscht.', (string) $kostenstelle?->name));

        return Response::redirect('/admin/kostenstellen');
    }

    /**
     * @param array<string, string> $params
     */
    public function nachOben(Request $request, array $params): ResponseInterface
    {
        return $this->verschieben($request, $params, true);
    }

    /**
     * @param array<string, string> $params
     */
    public function nachUnten(Request $request, array $params): ResponseInterface
    {
        return $this->verschieben($request, $params, false);
    }

    /**
     * @param array<string, string> $params
     */
    private function verschieben(Request $request, array $params, bool $nachOben): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/admin/kostenstellen');
        }

        try {
            $this->service->verschieben($id, $nachOben);
        } catch (CostCenterRuleViolation $e) {
            $this->session->flash($e->getMessage(), FlashArt::Fehler);
        }

        return Response::redirect('/admin/kostenstellen');
    }

    private function formular(?CostCenter $kostenstelle, string $name, bool $active, ?string $fehler, int $status = 200): ResponseInterface
    {
        return Response::html($this->view->render('admin/kostenstelle', [
            'title' => $kostenstelle === null ? 'Neue Kostenstelle' : 'Kostenstelle ' . $kostenstelle->name,
            'kostenstelle' => $kostenstelle,
            'name' => $name,
            'active' => $active,
            'fehler' => $fehler,
            'flash' => $this->session->pullFlash(),
            'anzahlKonten' => $kostenstelle === null ? 0 : $this->kostenstellen->userCount($kostenstelle->id),
        ], Area::Admin), $status);
    }

    /**
     * What the audit log keeps of a cost center: its name and whether it is
     * active.
     *
     * @return array{name: string, active: bool}
     */
    private static function details(string $name, bool $active): array
    {
        return ['name' => $name, 'active' => $active];
    }

    private static function text(Request $request, string $feld): string
    {
        $wert = $request->post[$feld] ?? '';

        return is_string($wert) ? $wert : '';
    }

    private function nichtGefunden(): ResponseInterface
    {
        return Response::html($this->view->render('error', [
            'title' => 'Kostenstelle nicht gefunden',
            'message' => 'Diese Kostenstelle gibt es nicht (mehr).',
            'startseite' => '/admin/kostenstellen',
        ], Area::Admin), 404);
    }

    private function csrfFailure(string $ziel): ResponseInterface
    {
        $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

        return Response::redirect($ziel);
    }
}
