<?php

declare(strict_types=1);

namespace App\Admin;

use App\Http\Cookie;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\DocumentArtifactRepository;
use App\Repository\JobRepository;
use App\Repository\SettingRepository;
use App\Repository\WorkerRepository;
use App\Service\Account\SessionVault;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\Worker\WorkerFallback;
use App\Service\Worker\WorkerKopplung;
use App\Service\Worker\WorkerProtokoll;
use App\Service\Worker\WorkerRouting;
use App\Service\Worker\WorkerUebergabe;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * Admin page "Worker" (docs/spec/07-worker.md sections 4 and 6): pairing
 * (issue #51/M7b-1: show a pairing code, confirm the fingerprint, unpair)
 * and, since issue #53/M7b-3, the module switch with the fallback period,
 * status (online/offline, last heartbeat, version, capabilities), queue,
 * last errors, throughput and "OCR nachholen".
 *
 * Switching the module on and "OCR nachholen" need an unlocked vault in
 * this session (E-43): from then on documents go to the worker, which can
 * read them while it processes them - only somebody who may read them
 * anyway decides that.
 *
 * Permission: `admin.settings` (app/src/routes.php), the same right as the
 * AI providers - the worker is processing configuration. CSRF on every
 * write. The pairing code is shown once, in the answer to the POST that
 * made it, and never stored in plaintext - not in the session either.
 */
final readonly class WorkerController
{
    private const string SEITE = '/admin/worker';

    /** "OCR nachholen": documents per click (each one seals its data keys). */
    public const int NACHHOLEN_MAX = 50;

    public function __construct(
        private View $view,
        private Session $session,
        private WorkerRepository $workers,
        private WorkerKopplung $kopplung,
        private WorkerRouting $routing,
        private WorkerUebergabe $uebergabe,
        private WorkerFallback $fallback,
        private JobRepository $jobs,
        private DocumentArtifactRepository $artifacts,
        private SettingRepository $settings,
        private SessionVault $sessionVault,
    ) {
    }

    /** Module switch and fallback period (section 6). */
    public function einstellungen(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure();
        }
        $aktiv = ($request->post['aktiv'] ?? '') === '1';
        $minuten = filter_var($request->post['fallback_minuten'] ?? '', FILTER_VALIDATE_INT);
        if (!is_int($minuten) || $minuten < WorkerRouting::FALLBACK_MINUTEN_MIN || $minuten > WorkerRouting::FALLBACK_MINUTEN_MAX) {
            $this->session->flash(sprintf(
                'Die Wartezeit bis zum Rückfall muss zwischen %d und %d Minuten liegen.',
                WorkerRouting::FALLBACK_MINUTEN_MIN,
                WorkerRouting::FALLBACK_MINUTEN_MAX,
            ), FlashArt::Fehler);

            return Response::redirect(self::SEITE);
        }
        if ($aktiv && !$this->routing->modulAktiv() && $this->tresor($request) === null) {
            $this->session->flash('Zum Einschalten muss der Tresor in dieser Sitzung entsperrt sein (erneut anmelden).', FlashArt::Fehler);

            return Response::redirect(self::SEITE);
        }

        $this->settings->set(WorkerRouting::SETTING_FALLBACK_MINUTEN, (string) $minuten);
        $this->settings->set(WorkerRouting::SETTING_AKTIV, $aktiv ? '1' : '0');
        if (!$aktiv) {
            // Back to the browser/session route right away, not with the
            // next cron run.
            $this->fallback->ausfuehren(new \DateTimeImmutable());
        }
        $this->session->flash($aktiv
            ? 'Worker-Modul eingeschaltet – passende Aufgaben gehen an den Worker, solange er online ist.'
            : 'Worker-Modul ausgeschaltet – alles läuft wieder im Browser.');

        return Response::redirect(self::SEITE);
    }

    /** "OCR nachholen" as a batch (section 6), NACHHOLEN_MAX per click. */
    public function ocrNachholen(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure();
        }
        $vault = $this->tresor($request);
        if ($vault === null) {
            $this->session->flash('Dafür muss der Tresor in dieser Sitzung entsperrt sein (erneut anmelden).', FlashArt::Fehler);

            return Response::redirect(self::SEITE);
        }
        $now = new \DateTimeImmutable();
        if (!$this->uebergabe->verfuegbar(WorkerUebergabe::OCR, $now)) {
            $this->session->flash('Der Worker übernimmt gerade keine Texterkennung (Modul aus, offline oder ohne OCR).', FlashArt::Fehler);

            return Response::redirect(self::SEITE);
        }
        $anzahl = $this->uebergabe->ocrNachholen($vault, self::NACHHOLEN_MAX, $now);
        $this->session->flash(sprintf('%d Beleg(e) an die Texterkennung des Workers übergeben.', $anzahl));

        return Response::redirect(self::SEITE);
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
            // Section 4: "sofortige Rückkehr zum Browser-Weg" (issue #53).
            $this->fallback->ausfuehren(new \DateTimeImmutable());
            $this->session->flash('Worker entkoppelt – sein Zugang ist gesperrt, seine Aufgaben laufen wieder im Browser.');
        } else {
            $this->session->flash('Dieser Worker ist bereits entkoppelt.', FlashArt::Fehler);
        }

        return Response::redirect(self::SEITE);
    }

    private function seite(Request $request, ?string $code): ResponseInterface
    {
        $now = new \DateTimeImmutable();
        $worker = $this->workers->aktueller();

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
            'adresse' => $request->ownOrigin(),
            'modulAktiv' => $this->routing->modulAktiv(),
            'fallbackMinuten' => $this->routing->fallbackMinuten(),
            'online' => $worker !== null && WorkerRouting::online($worker, $now),
            'uebersicht' => $this->jobs->workerUebersicht($now->modify('-1 day')),
            'defekte' => $this->artifacts->zaehleDefekte(),
            'nachholbar' => $this->uebergabe->nachholbar(),
            'ocrVerfuegbar' => $this->uebergabe->verfuegbar(WorkerUebergabe::OCR, $now),
        ], Area::Admin));
    }

    private function tresor(Request $request): ?Vault
    {
        try {
            $vault = $this->sessionVault->unlock(Cookie::vaultKeyFrom($request));
        } catch (CryptoException) {
            return null;
        }

        return $vault !== null && $vault->isUnlocked() ? $vault : null;
    }

    private function csrfFailure(): ResponseInterface
    {
        $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

        return Response::redirect(self::SEITE);
    }
}
