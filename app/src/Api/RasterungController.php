<?php

declare(strict_types=1);

namespace App\Api;

use App\Domain\Berechtigungen;
use App\Http\Cookie;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Http\StreamResponse;
use App\Service\Account\SessionVault;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\Document\PdfRasterung;
use App\Service\Document\RasterungStatus;
use App\Service\Job\JobRunner;
use App\View\View;

/**
 * The `render_pages` browser job's four requests (issue #30/M4-8,
 * docs/spec/06-betrieb.md section 4): `public/js/rasterung.js` on the inbox
 * pages fetches a task, downloads its source PDF, renders it with pdf.js and
 * uploads the pages. Permission: `document.edit` on every route (the same
 * right `pdf_erzeugen` and the inbox decision itself need) - unlike
 * `POST /api/jobs/step`, which serves several session job types behind one
 * generic "logged in" route, there is exactly one browser job type here.
 *
 * The lock App\Service\Document\PdfRasterung::naechste() hands out travels
 * in the URL path, the same way the upload component's chunk id does
 * (App\Api\UploadController): a random, single-purpose credential, not a
 * session-bound one, checked against the job row rather than the account.
 *
 * `naechste()`'s response is JSON like every other fetch()-driven route;
 * `quelle()` streams bytes for pdf.js to consume and therefore skips CSRF,
 * the same way App\App\InboxController::datei() does - a GET that only
 * reads, credentialed by the lock in its own path.
 */
final readonly class RasterungController
{
    private const string TRESOR_GESPERRT = 'Ihr Tresor ist in dieser Sitzung nicht entsperrt. Bitte melden Sie sich neu an.';

    private const string CSRF_MESSAGE = 'Sitzung abgelaufen – bitte die Seite neu laden.';

    /**
     * @param \Closure(): resource|null $body the request body of the
     *        `seite`-Route; defaults to php://input. Injectable the same
     *        way App\Api\UploadController::chunk() takes one, so a test can
     *        drive it without a real HTTP request.
     */
    public function __construct(
        private Session $session,
        private SessionVault $sessionVault,
        private View $view,
        private PdfRasterung $rasterung,
        private JobRunner $runner,
        private ?\Closure $body = null,
    ) {
    }

    public function naechste(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return Response::json(['fehler' => self::CSRF_MESSAGE], 403);
        }

        $vault = $this->tresor($request);
        if ($vault === null) {
            return Response::json(['status' => 'gesperrt', 'offen' => $this->offen()]);
        }

        $aufgabe = $this->rasterung->naechste($vault, new \DateTimeImmutable());
        if ($aufgabe->status !== RasterungStatus::Aufgabe) {
            return Response::json(['status' => $aufgabe->status->value, 'offen' => $this->offen()]);
        }

        return Response::json([
            'status' => RasterungStatus::Aufgabe->value,
            'job' => $aufgabe->jobId,
            'lock' => $aufgabe->lock,
            'quelle' => $aufgabe->quelleIndex,
            'seite' => $aufgabe->seite,
            'quellen' => $aufgabe->quellenGesamt,
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function quelle(Request $request, array $params): ResponseInterface
    {
        $this->session->start();

        $vault = $this->tresor($request);
        if ($vault === null) {
            return new Response(403, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store'], self::TRESOR_GESPERRT);
        }

        $datei = $this->rasterung->quelle((int) $params['job'], $params['lock'], (int) $params['quelle'], $vault);
        if ($datei === null) {
            return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store'], 'Nicht gefunden.');
        }

        return new StreamResponse($datei['chunks'], [
            'Content-Type' => $datei['mime'],
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function seite(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return Response::json(['fehler' => self::CSRF_MESSAGE], 403);
        }

        $vault = $this->tresor($request);
        if ($vault === null) {
            return Response::json(['status' => 'gesperrt']);
        }

        $oeffnen = $this->body ?? static fn() => fopen('php://input', 'rb');
        $stream = $oeffnen();
        if (!is_resource($stream)) {
            throw new \RuntimeException('Cannot read the request body.');
        }
        $jpeg = stream_get_contents($stream);
        fclose($stream);

        $ergebnis = $this->rasterung->seiteSpeichern(
            (int) $params['job'],
            $params['lock'],
            (int) $params['quelle'],
            (int) $params['seite'],
            (int) $params['seiten'],
            $jpeg === false ? '' : $jpeg,
            $vault,
            new \DateTimeImmutable(),
        );

        $antwort = ['status' => $ergebnis->status->value];
        if ($ergebnis->naechsteQuelle !== null) {
            // Where to continue (issue #30/M4-8, App\Service\Document\
            // SeiteErgebnis): the job's lock is still held, so the browser
            // must not call naechste() again to find out - it would find
            // nothing reclaimable and stall.
            $antwort['quelle'] = $ergebnis->naechsteQuelle;
            $antwort['seite'] = $ergebnis->naechsteSeite;
        }

        return Response::json($antwort, self::statusCode($ergebnis->status));
    }

    /**
     * @param array<string, string> $params
     */
    public function abbruch(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return Response::json(['fehler' => self::CSRF_MESSAGE], 403);
        }

        $grund = is_string($request->post['grund'] ?? null) ? $request->post['grund'] : '';
        $ok = $this->rasterung->abbrechen((int) $params['job'], $params['lock'], $grund, new \DateTimeImmutable());

        return Response::json(['status' => $ok ? 'ok' : RasterungStatus::Verloren->value], $ok ? 200 : 409);
    }

    private static function statusCode(RasterungStatus $status): int
    {
        return match ($status) {
            RasterungStatus::Ok, RasterungStatus::Fertig => 200,
            RasterungStatus::Unerwartet, RasterungStatus::Verloren => 409,
            RasterungStatus::UngueltigerTyp => 415,
            RasterungStatus::ZuGross => 413,
            RasterungStatus::Aufgabe, RasterungStatus::Leer => throw new \LogicException('seiteSpeichern() never returns this status.'),
        };
    }

    private function offen(): int
    {
        $berechtigungen = $this->view->berechtigungen() ?? Berechtigungen::keine();

        return $this->runner->offen($berechtigungen) ?? 0;
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
