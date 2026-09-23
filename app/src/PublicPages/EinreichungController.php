<?php

declare(strict_types=1);

namespace App\PublicPages;

use App\Http\Request;
use App\Http\Response;
use App\Repository\CostCenterRepository;
use App\Repository\VaultRepository;
use App\Service\Mail\MailSettingsRepository;
use App\Service\Mail\PublicUrl;
use App\Service\Submission\EinreichungsEinstellungen;
use App\Service\Submission\FormToken;
use App\Service\Submission\ProofOfWork;
use App\Service\Submission\Spamschutz;
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
 *
 * Spam defence (issue #25/M4-3, docs/spec/01-sicherheit.md section 5):
 * App\Service\Submission\Spamschutz is checked before
 * App\Service\Submission\SubmissionService ever sees the request - proof of
 * work, honeypot and minimum fill time are one mechanism there, shared with
 * App\Api\EinreichungUploadController.
 */
final readonly class EinreichungController
{
    private const string TOKEN_MESSAGE = 'Das Formular ist abgelaufen – bitte die Seite neu laden.';
    private const string VAULT_MESSAGE = 'Die Einreichung ist noch nicht eingerichtet. Bitte später erneut versuchen.';

    public function __construct(
        private View $view,
        private FormToken $formToken,
        private ProofOfWork $proofOfWork,
        private VaultRepository $vaults,
        private CostCenterRepository $kostenstellen,
        private SubmissionService $submissions,
        private Spamschutz $spamschutz,
        private EinreichungsEinstellungen $einstellungen,
        /** For the link in the notice to the inbox (issue #27/M4-5). */
        private ?MailSettingsRepository $mailSettings = null,
    ) {
    }

    public function formular(Request $request): Response
    {
        $token = $this->formToken->ausstellen();
        $tokenData = $this->formToken->pruefen($token);
        \assert($tokenData !== null); // just issued, always verifies.

        return Response::html($this->view->render('einreichen', [
            'title' => 'Beleg einreichen',
            'token' => $token,
            'pow' => $this->proofOfWork->challenge($tokenData),
            'kostenstellen' => $this->kostenstellen->active(),
            'hatTresor' => $this->vaults->current() !== null,
            'pausiert' => $this->einstellungen->pausiert,
            'maxSeiten' => $this->einstellungen->maxSeiten,
            'maxDateiMb' => $this->einstellungen->maxDateiMb,
            'scripts' => ['/js/upload.js', '/js/iban.js', '/js/einreichen.js'],
        ], Area::Oeffentlich));
    }

    public function absenden(Request $request): Response
    {
        $tokenData = $this->formToken->pruefen($request->header('x-csrf-token') ?? '');
        if ($tokenData === null) {
            return Response::json(['fehler' => self::TOKEN_MESSAGE], 403);
        }

        $abgelehnt = $this->spamschutz->pruefeAbsenden(
            $request->ip,
            $tokenData,
            $request->header('x-pow-loesung'),
            self::honeypotGefuellt($request),
        );
        if ($abgelehnt !== null) {
            return Response::json(['fehler' => $abgelehnt->message()], $abgelehnt->status());
        }

        $vault = $this->vaults->current();
        if ($vault === null) {
            return Response::json(['fehler' => self::VAULT_MESSAGE], 503);
        }

        $ergebnis = $this->submissions->einreichen(
            $request->post,
            $tokenData->hash(),
            $vault,
            linkBasis: $this->mailSettings === null
                ? null
                : PublicUrl::resolve($this->mailSettings->get(), $request->header('host') ?? '', Request::httpsFromGlobals()),
        );
        if (!$ergebnis->istErfolg()) {
            return Response::json(['fehler' => $ergebnis->fehler], 422);
        }

        // A field-error retry above never reaches this line - only a
        // submission that actually went through costs the hourly budget. A
        // retried request with the same token (the browser resending after
        // a lost response) counts again here, same as any other success;
        // one extra count on a rare retry is cheaper than tracking it.
        $this->spamschutz->zaehleErfolgreicheEinreichung($request->ip);

        return Response::json(['referenz' => $ergebnis->referenz], 201);
    }

    /**
     * For Menschen unsichtbar (CSS + tabindex), for a bot that fills in
     * every field it finds, a giveaway - docs/spec/01-sicherheit.md
     * section 5.
     */
    private static function honeypotGefuellt(Request $request): bool
    {
        $wert = $request->post['webseite'] ?? '';

        return is_string($wert) && trim($wert) !== '';
    }
}
