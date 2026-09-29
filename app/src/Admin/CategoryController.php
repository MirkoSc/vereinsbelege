<?php

declare(strict_types=1);

namespace App\Admin;

use App\Domain\AuditAction;
use App\Domain\Category;
use App\Domain\CategoryDirection;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\CategoryRepository;
use App\Service\Audit\AuditLog;
use App\Service\MasterData\CategoryRuleViolation;
use App\Service\MasterData\CategoryService;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * Admin pages for categories (M6-1, issue #35, docs/spec/
 * 02-datenmodell.md "Kategorien"): the list grouped by direction with
 * reordering, a new category, changing and deleting one.
 *
 * Behind `admin.settings` (app/src/routes.php), the same right as
 * Kostenstellen. Plaintext master data - no vault involved.
 */
final readonly class CategoryController
{
    public function __construct(
        private View $view,
        private Session $session,
        private CategoryRepository $kategorien,
        private CategoryService $service,
        private AuditLog $audit,
    ) {
    }

    public function liste(Request $request): ResponseInterface
    {
        $this->session->start();

        $gruppen = [];
        foreach (CategoryDirection::cases() as $richtung) {
            $gruppen[$richtung->value] = $this->kategorien->byDirection($richtung);
        }

        return Response::html($this->view->render('admin/kategorien', [
            'title' => 'Kategorien',
            'flash' => $this->session->pullFlash(),
            'gruppen' => $gruppen,
        ], Area::Admin));
    }

    public function neu(Request $request): ResponseInterface
    {
        $this->session->start();

        return $this->formular(null, self::leer(), null);
    }

    public function anlegen(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/admin/kategorien/neu');
        }

        $felder = self::felder($request);

        try {
            $id = $this->service->anlegen($felder['name'], $felder['richtung'], $felder['farbe'], $felder['ki_hinweis']);
        } catch (CategoryRuleViolation $e) {
            return $this->formular(null, $felder, $e->getMessage(), 422);
        }

        $gespeichert = $this->kategorien->find($id);
        $this->audit->record(AuditAction::KategorieAngelegt, $this->session->userId(), $request->ip, $id, $gespeichert === null ? [] : self::details($gespeichert));
        $this->session->flash(sprintf('Kategorie „%s“ angelegt.', trim($felder['name'])));

        return Response::redirect('/admin/kategorien');
    }

    /**
     * @param array<string, string> $params
     */
    public function bearbeiten(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $kategorie = $this->kategorien->find((int) $params['id']);
        if ($kategorie === null) {
            return $this->nichtGefunden();
        }

        return $this->formular($kategorie, [
            'name' => $kategorie->name,
            'richtung' => $kategorie->direction->value,
            'farbe' => $kategorie->color->value ?? '',
            'ki_hinweis' => $kategorie->aiHint,
            'active' => $kategorie->active,
        ], null);
    }

    /**
     * @param array<string, string> $params
     */
    public function speichern(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/admin/kategorien/' . $id);
        }
        $kategorie = $this->kategorien->find($id);
        if ($kategorie === null) {
            return $this->nichtGefunden();
        }

        $felder = self::felder($request);

        try {
            $this->service->aendern($id, $felder['name'], $felder['richtung'], $felder['farbe'], $felder['ki_hinweis'], $felder['active']);
        } catch (CategoryRuleViolation $e) {
            return $this->formular($kategorie, $felder, $e->getMessage(), 422);
        }

        $gespeichert = $this->kategorien->find($id) ?? $kategorie;
        $this->audit->record(AuditAction::KategorieGeaendert, $this->session->userId(), $request->ip, $id, self::details($gespeichert));
        $this->session->flash(sprintf('Kategorie „%s“ gespeichert.', $gespeichert->name));

        return Response::redirect('/admin/kategorien');
    }

    /**
     * @param array<string, string> $params
     */
    public function loeschen(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/admin/kategorien/' . $id);
        }

        $kategorie = $this->kategorien->find($id);
        try {
            $this->service->loeschen($id);
        } catch (CategoryRuleViolation $e) {
            $this->session->flash($e->getMessage(), FlashArt::Fehler);

            return Response::redirect($kategorie === null ? '/admin/kategorien' : '/admin/kategorien/' . $id);
        }

        $this->audit->record(AuditAction::KategorieGeloescht, $this->session->userId(), $request->ip, $id, ['name' => (string) $kategorie?->name]);
        $this->session->flash(sprintf('Kategorie „%s“ gelöscht.', (string) $kategorie?->name));

        return Response::redirect('/admin/kategorien');
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
            return $this->csrfFailure('/admin/kategorien');
        }

        try {
            $this->service->verschieben($id, $nachOben);
        } catch (CategoryRuleViolation $e) {
            $this->session->flash($e->getMessage(), FlashArt::Fehler);
        }

        return Response::redirect('/admin/kategorien');
    }

    /**
     * @param array{name: string, richtung: string, farbe: string, ki_hinweis: string, active: bool} $felder
     */
    private function formular(?Category $kategorie, array $felder, ?string $fehler, int $status = 200): ResponseInterface
    {
        return Response::html($this->view->render('admin/kategorie', [
            'title' => $kategorie === null ? 'Neue Kategorie' : 'Kategorie ' . $kategorie->name,
            'kategorie' => $kategorie,
            'felder' => $felder,
            'fehler' => $fehler,
            'flash' => $this->session->pullFlash(),
            'verwendungen' => $kategorie === null ? 0 : $this->kategorien->usageCount($kategorie->id),
        ], Area::Admin), $status);
    }

    /**
     * @return array{name: string, richtung: string, farbe: string, ki_hinweis: string, active: bool}
     */
    private static function leer(): array
    {
        return ['name' => '', 'richtung' => CategoryDirection::Ausgabe->value, 'farbe' => '', 'ki_hinweis' => '', 'active' => true];
    }

    /**
     * @return array{name: string, richtung: string, farbe: string, ki_hinweis: string, active: bool}
     */
    private static function felder(Request $request): array
    {
        return [
            'name' => self::text($request, 'name'),
            'richtung' => self::text($request, 'richtung'),
            'farbe' => self::text($request, 'farbe'),
            'ki_hinweis' => self::text($request, 'ki_hinweis'),
            'active' => ($request->post['active'] ?? '') === '1',
        ];
    }

    /**
     * What the audit log keeps of a category: the fields the page edits.
     *
     * @return array{name: string, richtung: string, farbe: string|null, aktiv: bool}
     */
    private static function details(Category $kategorie): array
    {
        return [
            'name' => $kategorie->name,
            'richtung' => $kategorie->direction->value,
            'farbe' => $kategorie->color?->value,
            'aktiv' => $kategorie->active,
        ];
    }

    private static function text(Request $request, string $feld): string
    {
        $wert = $request->post[$feld] ?? '';

        return is_string($wert) ? $wert : '';
    }

    private function nichtGefunden(): ResponseInterface
    {
        return Response::html($this->view->render('error', [
            'title' => 'Kategorie nicht gefunden',
            'message' => 'Diese Kategorie gibt es nicht (mehr).',
            'startseite' => '/admin/kategorien',
        ], Area::Admin), 404);
    }

    private function csrfFailure(string $ziel): ResponseInterface
    {
        $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

        return Response::redirect($ziel);
    }
}
