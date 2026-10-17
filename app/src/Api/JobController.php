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
use App\Service\Job\JobLauf;
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
 *
 * A step that waits on an external call (issue #47/M7-5, the AI provider,
 * App\Service\Job\ExternerJobHandler) runs in three phases here
 * (docs/spec/06-betrieb.md section 4): the runner prepares the call, then
 * this controller drops that runner, releases the database connection and
 * runs the call with neither database nor session, and finishes the step
 * with a runner built anew - on a fresh connection, because the host closes
 * one that idled for 120 s, and an AI call may take longer.
 */
final readonly class JobController
{
    /**
     * @param \Closure(): JobRunner $runner builds the runner on the current
     *        database connection - once per phase
     * @param \Closure(): void $verbindungFreigeben drops the connection
     *        before an external call (App\Database\ConnectionFactory::
     *        release()); the next $runner() opens a new one
     */
    public function __construct(
        private Session $session,
        private SessionVault $sessionVault,
        private View $view,
        private \Closure $runner,
        private \Closure $verbindungFreigeben,
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
        $runner = ($this->runner)();
        if ($vault === null) {
            return Response::json(['status' => 'gesperrt', 'offen' => $runner->offen($berechtigungen) ?? 0]);
        }

        // Nothing below writes the session: free its lock, so the person's
        // other tabs need not wait for a step that waits on the AI provider.
        $this->session->schliessen();

        $lauf = $runner->schritt($berechtigungen, $vault, new \DateTimeImmutable());
        if ($lauf->aufrufAusstehend()) {
            unset($runner);
            ($this->verbindungFreigeben)();
            $ergebnis = $this->ausfuehren($lauf);
            $lauf = ($this->runner)()->nachAufruf($berechtigungen, $vault, $lauf, $ergebnis, new \DateTimeImmutable());
        }

        return Response::json(['status' => $lauf->status->value, 'offen' => $lauf->offen]);
    }

    /**
     * The external call itself, with neither database nor session. A
     * \Throwable is handed on as the result: JobRunner::nachAufruf() fails
     * the job with its class name, like any step that throws.
     */
    private function ausfuehren(JobLauf $lauf): mixed
    {
        $aufruf = $lauf->aufruf ?? throw new \LogicException('No external call to run.');
        try {
            return ($aufruf->ausfuehren)();
        } catch (\Throwable $e) {
            return $e;
        }
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
