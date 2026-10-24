<?php

declare(strict_types=1);

namespace App\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\WorkerRepository;
use App\Service\Worker\WorkerKopplung;
use App\Service\Worker\WorkerProtokoll;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * Admin page "Worker" - pairing part (docs/spec/07-worker.md section 4,
 * issue #51/M7b-1): show a pairing code, confirm the fingerprint, unpair.
 * Status, queue, errors and the module switch follow with issue #53.
 *
 * Permission: `admin.settings` (app/src/routes.php), the same right as the
 * AI providers - the worker is processing configuration. CSRF on every
 * write. The pairing code is shown once, in the answer to the POST that
 * made it, and never stored in plaintext - not in the session either.
 */
final readonly class WorkerController
{
    private const string SEITE = '/admin/worker';

    public function __construct(
        private View $view,
        private Session $session,
        private WorkerRepository $workers,
        private WorkerKopplung $kopplung,
    ) {
    }

    public function page(Request $request): ResponseInterface
    {
        $this->session->start();

        return $this->seite($request, null);
    }

    public function koppeln(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure();
        }

        $code = $this->kopplung->codeErzeugen($this->session->userId(), $request->ip, new \DateTimeImmutable());
        if ($code === null) {
            $this->session->flash('Es ist bereits ein Worker gekoppelt – bitte zuerst entkoppeln.', FlashArt::Fehler);

            return Response::redirect(self::SEITE);
        }

        return $this->seite($request, $code);
    }

    /**
     * @param array<string, string> $params
     */
    public function bestaetigen(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure();
        }
        if (($request->post['verglichen'] ?? '') !== '1') {
            $this->session->flash('Bitte bestätigen, dass der Fingerabdruck mit der Anzeige auf dem Worker übereinstimmt.', FlashArt::Fehler);

            return Response::redirect(self::SEITE);
        }

        $ok = $this->kopplung->bestaetigen((int) ($params['id'] ?? 0), $this->session->userId(), $request->ip, new \DateTimeImmutable());
        if ($ok) {
            $this->session->flash('Worker bestätigt – er holt ab jetzt Aufgaben ab.');
        } else {
            $this->session->flash('Dieser Worker wartet nicht (mehr) auf eine Bestätigung.', FlashArt::Fehler);
        }

        return Response::redirect(self::SEITE);
    }

    /**
     * @param array<string, string> $params
     */
    public function entkoppeln(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure();
        }

        $ok = $this->kopplung->entkoppeln((int) ($params['id'] ?? 0), $this->session->userId(), $request->ip, new \DateTimeImmutable());
        if ($ok) {
            $this->session->flash('Worker entkoppelt – sein Zugang ist gesperrt, seine Aufgaben sind freigegeben.');
        } else {
            $this->session->flash('Dieser Worker ist bereits entkoppelt.', FlashArt::Fehler);
        }

        return Response::redirect(self::SEITE);
    }

    private function seite(Request $request, ?string $code): ResponseInterface
    {
        $now = new \DateTimeImmutable();
        $worker = $this->workers->aktueller();
        $host = $request->header('host') ?? '';

        return Response::html($this->view->render('admin/worker', [
            'title' => 'Worker',
            'csrf' => $this->session->csrfToken(),
            'flash' => $this->session->pullFlash(),
            'worker' => $worker,
            'fingerabdruck' => $worker === null ? '' : WorkerProtokoll::fingerabdruckAnzeige($worker->fingerprint),
            'protokollOk' => $worker === null || WorkerProtokoll::unterstuetzt($worker->protokollVersion),
            'code' => $code,
            'codeBis' => $code === null ? null : $this->workers->offenerCodeBis($now),
            'offenerCodeBis' => $code === null && $worker === null ? $this->workers->offenerCodeBis($now) : null,
            'adresse' => preg_match('/^[A-Za-z0-9.-]+(:\d+)?$/', $host) === 1 ? 'https://' . $host : 'https://belege.verein.de',
        ], Area::Admin));
    }

    private function csrfFailure(): ResponseInterface
    {
        $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

        return Response::redirect(self::SEITE);
    }
}
