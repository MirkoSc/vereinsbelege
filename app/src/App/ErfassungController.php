<?php

declare(strict_types=1);

namespace App\App;

use App\Domain\Berechtigungen;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Repository\CostCenterRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Crypto\ServerCrypto;
use App\Service\Mail\MailSettingsRepository;
use App\Service\Mail\PublicUrl;
use App\Service\Submission\InterneErfassung;
use App\Service\Upload\UploadService;
use App\View\Area;
use App\View\View;

/**
 * The internal capture `/app/belege/neu` (docs/spec/03-erfassung-und-ki.md
 * section 1, issue #28/M4-6): the same capture component as `/einreichen`
 * for logged-in accounts with `document.submit_internal`, several receipts
 * in one pass, without mandatory reimbursement details.
 *
 * The page uploads through the session's `/api/upload`
 * (App\Api\UploadController) with the capture id it hands out here in
 * `X-Erfassung`; the final POST is JSON with the session's CSRF token in
 * `X-CSRF-Token`. The rules live in App\Service\Submission\InterneErfassung.
 *
 * No unlocked vault needed: capturing only seals to the vault's public key
 * (CLAUDE.md section 4) - an account without a vault grant can capture too.
 */
final readonly class ErfassungController
{
    private const string CSRF_MESSAGE = 'Sitzung abgelaufen – bitte die Seite neu laden.';
    private const string VAULT_MESSAGE = 'Die Belegerfassung ist noch nicht eingerichtet (kein Tresor).';

    public function __construct(
        private View $view,
        private Session $session,
        private VaultRepository $vaults,
        private CostCenterRepository $kostenstellen,
        private UserRepository $users,
        private ServerCrypto $crypto,
        private InterneErfassung $erfassung,
        /** For the link in the notice to the inbox (issue #27/M4-5). */
        private ?MailSettingsRepository $mailSettings = null,
    ) {
    }

    public function formular(Request $request): Response
    {
        $this->session->start();

        return Response::html($this->view->render('app/belege-neu', [
            'title' => 'Belege erfassen',
            'csrf' => $this->session->csrfToken(),
            'erfassung' => InterneErfassung::neueErfassungsId(),
            'kostenstellen' => $this->kostenstellen->active(),
            'hatTresor' => $this->vaults->current() !== null,
            'maxBelege' => InterneErfassung::MAX_BELEGE,
            'maxSeiten' => InterneErfassung::MAX_SEITEN,
            'maxDateiMb' => intdiv(UploadService::MAX_FILE_BYTES, 1024 * 1024),
            'posteingang' => ($this->view->berechtigungen() ?? Berechtigungen::keine())->darf(Permission::InboxView),
            'scripts' => ['/js/upload.js', '/js/iban.js', '/js/einreichen.js', '/js/erfassen.js'],
        ], Area::App));
    }

    public function absenden(Request $request): Response
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return Response::json(['fehler' => self::CSRF_MESSAGE], 403);
        }

        $userId = $this->session->userId();
        $user = $userId === null ? null : $this->users->findById($userId);
        if ($user === null) {
            // The guard already checked the login; this is only the race
            // with an account deleted in between.
            return Response::json(['fehler' => self::CSRF_MESSAGE], 403);
        }

        $vault = $this->vaults->current();
        if ($vault === null) {
            return Response::json(['fehler' => self::VAULT_MESSAGE], 503);
        }

        $erfassung = $request->post['erfassung'] ?? null;
        $ergebnis = $this->erfassung->erfassen(
            $request->post,
            $user->id,
            $this->crypto->decrypt($user->displayNameEnc),
            is_string($erfassung) ? $erfassung : '',
            $vault,
            $request->ip,
            linkBasis: $this->mailSettings === null
                ? null
                : PublicUrl::resolve($this->mailSettings->get(), $request->header('host') ?? '', Request::httpsFromGlobals()),
        );
        if (!$ergebnis->istErfolg()) {
            return Response::json(['fehler' => $ergebnis->fehler], 422);
        }

        return Response::json(['referenzen' => $ergebnis->referenzen], 201);
    }
}
