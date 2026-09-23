<?php

declare(strict_types=1);

namespace App\PublicPages;

use App\Http\Request;
use App\Http\Response;
use App\Repository\CostCenterRepository;
use App\Repository\VaultRepository;
use App\Service\Submission\FormToken;
use App\Service\Submission\SubmissionService;
use App\View\Area;
use App\View\View;

/**
 * `/einreichen` (docs/spec/03-erfassung-und-ki.md section 1, issue #24/M4-2):
 * the public submission page and its final submit. No session
 * (App\Http\Session's class docblock) - the page's only credential is the
 * stateless App\Service\Submission\FormToken it hands out on GET and that
 * every later request, including the upload sub-routes
 * (App\Api\EinreichungUploadController), carries back in `X-CSRF-Token`.
 */
final readonly class EinreichungController
{
    private const string TOKEN_MESSAGE = 'Das Formular ist abgelaufen – bitte die Seite neu laden.';
    private const string VAULT_MESSAGE = 'Die Einreichung ist noch nicht eingerichtet. Bitte später erneut versuchen.';

    public function __construct(
        private View $view,
        private FormToken $formToken,
        private VaultRepository $vaults,
        private CostCenterRepository $kostenstellen,
        private SubmissionService $submissions,
    ) {
    }

    public function formular(Request $request): Response
    {
        return Response::html($this->view->render('einreichen', [
            'title' => 'Beleg einreichen',
            'token' => $this->formToken->ausstellen(),
            'kostenstellen' => $this->kostenstellen->active(),
            'hatTresor' => $this->vaults->current() !== null,
            'maxSeiten' => SubmissionService::MAX_SEITEN,
            'scripts' => ['/js/upload.js', '/js/iban.js', '/js/einreichen.js'],
        ], Area::Oeffentlich));
    }

    public function absenden(Request $request): Response
    {
        $tokenData = $this->formToken->pruefen($request->header('x-csrf-token') ?? '');
        if ($tokenData === null) {
            return Response::json(['fehler' => self::TOKEN_MESSAGE], 403);
        }

        $vault = $this->vaults->current();
        if ($vault === null) {
            return Response::json(['fehler' => self::VAULT_MESSAGE], 503);
        }

        $ergebnis = $this->submissions->einreichen($request->post, $tokenData->hash(), $vault);
        if (!$ergebnis->istErfolg()) {
            return Response::json(['fehler' => $ergebnis->fehler], 422);
        }

        return Response::json(['referenz' => $ergebnis->referenz], 201);
    }
}
