<?php

declare(strict_types=1);

namespace App\Api;

use App\Domain\Berechtigungen;
use App\Http\Cookie;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Service\Account\SessionVault;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\Job\JobRunner;
use App\View\View;

/**
 * `POST /api/jobs/step` (issue #29/M4-7, docs/spec/06-betrieb.md section 4):
 * one job step per call, driven by `public/js/jobs.js` from the browser of
 * a signed-in person. Permission: any logged-in account
 * (`Zugriff::angemeldet()`, app/src/routes.php) - which job TYPES that
 * account may touch is what App\Service\Job\JobRunner checks per type
 * (JobHandler::recht()), the same split InboxController and ErfassungController
 * already use for their own rights. CSRF like every other write; answers in
 * JSON like the other fetch()-driven routes.
 *
 * Decrypting needs an unlocked vault (CLAUDE.md section 4) - a session
 * without one (cookie missing, or a login that never released the vault,
 * docs/spec/01-sicherheit.md section 2) cannot run a step, but the header
 * still shows how many jobs wait, so `gesperrt` still carries `offen`.
 */
final readonly class JobController
{
    public function __construct(
        private Session $session,
        private SessionVault $sessionVault,
        private View $view,
        private JobRunner $runner,
    ) {
    }

    public function step(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return Response::json(['fehler' => 'Sitzung abgelaufen – bitte die Seite neu laden.'], 403);
        }

        $berechtigungen = $this->view->berechtigungen() ?? Berechtigungen::keine();

        $vault = $this->tresor($request);
        if ($vault === null) {
            return Response::json(['status' => 'gesperrt', 'offen' => $this->runner->offen($berechtigungen) ?? 0]);
        }

        $lauf = $this->runner->schritt($berechtigungen, $vault, new \DateTimeImmutable());

        return Response::json(['status' => $lauf->status->value, 'offen' => $lauf->offen]);
    }

    private function tresor(Request $request): ?Vault
    {
        try {
            return $this->sessionVault->unlock(Cookie::vaultKeyFrom($request));
        } catch (CryptoException) {
            return null;
        }
    }
}
