<?php

declare(strict_types=1);

namespace App\App;

use App\Api\UploadController;
use App\Domain\ArchiveImportStatus;
use App\Http\Cookie;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Service\Account\SessionVault;
use App\Service\Archiv\ArchivImport;
use App\Service\Archiv\ArchivImportFehler;
use App\Service\Archiv\DateinamenMuster;
use App\Service\Archiv\UngueltigesDateinamenMuster;
use App\Service\Archiv\UngueltigesZip;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\Upload\UploadException;
use App\Support\FileLogger;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * The archive import /app/archiv-import (issue #77/M12-3,
 * docs/spec/05-auswertung-und-export.md section 3): upload a ZIP, see the
 * preview, start, watch the step chain, read the result.
 *
 * Every route needs `archive.import` (Admin, Finanzen) - app/src/routes.php
 * declares it - and every POST a valid CSRF token. Everything read from the
 * archive is vault data: without an unlocked vault the pages say so and
 * nothing is written.
 *
 * The ZIP comes through the chunk upload (public/js/upload.js), under its
 * own prefix: opening, chunks and abort are the shared App\Api\
 * UploadController with a larger limit (App\Service\Archiv\ArchivImport::
 * MAX_ZIP_BYTES); only the closing request differs - it checks a ZIP, not a
 * receipt, and creates the import instead of a blob. File names from the
 * archive never appear in a URL, a flash message or the log.
 */
final readonly class ArchivImportController
{
    private const string START = '/app/archiv-import';

    private const string TRESOR_GESPERRT = 'Ihr Tresor ist in dieser Sitzung nicht entsperrt. Bitte melden Sie sich neu an.';

    private const string CSRF_JSON = 'Sitzung abgelaufen – bitte die Seite neu laden.';

    /** How many past imports the start page lists. */
    private const int VERLAUF = 20;

    /**
     * @param \Closure(): ArchivImport $import built lazily: a chunk request
     *        has no use for it
     */
    public function __construct(
        private View $view,
        private Session $session,
        private SessionVault $sessionVault,
        private UploadController $upload,
        private \Closure $import,
        private ?FileLogger $logger = null,
    ) {
    }

    /** The upload and the latest imports. */
    public function start(Request $request): ResponseInterface
    {
        $this->session->start();
        $tresor = $this->tresor($request);

        return Response::html($this->view->render('app/archiv-import', [
            'title' => 'Archiv importieren',
            'flash' => $this->session->pullFlash(),
            'csrf' => $this->session->csrfToken(),
            'entsperrt' => $tresor !== null,
            'importe' => $tresor === null ? [] : $this->import()->letzte(self::VERLAUF),
            'maxBytes' => ArchivImport::MAX_ZIP_BYTES,
            'scripts' => $tresor === null ? [] : ['/js/upload.js', '/js/archivimport.js'],
        ], Area::App));
    }

    public function uploadOeffnen(Request $request): ResponseInterface
    {
        return $this->upload->create($request);
    }

    /**
     * @param array<string, string> $params
     */
    public function uploadChunk(Request $request, array $params): ResponseInterface
    {
        return $this->upload->chunk($request, $params);
    }

    /**
     * @param array<string, string> $params
     */
    public function uploadAbbrechen(Request $request, array $params): ResponseInterface
    {
        return $this->upload->abort($request, $params);
    }

    /**
     * Closes the upload: reads the ZIP's table of contents and creates the
     * import as a preview. Answers where the browser goes next.
     *
     * @param array<string, string> $params
     */
    public function uploadAbschliessen(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return Response::json(['fehler' => self::CSRF_JSON], 403);
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return Response::json(['fehler' => self::TRESOR_GESPERRT], 403);
        }

        try {
            $id = $this->import()->hochgeladen($tresor, $params['id'], $this->session->userId(), new \DateTimeImmutable());
        } catch (UngueltigesZip $e) {
            return Response::json(['fehler' => $e->getMessage()], 422);
        } catch (UploadException $e) {
            return Response::json(['fehler' => $e->error->message()], $e->error->status());
        } catch (\Throwable $e) {
            // Class and route only - never the message (CLAUDE.md section 4).
            $this->logger?->append(sprintf('archive upload failed: %s in %s', $e::class, $request->path));

            return Response::json(['fehler' => 'Der Upload ist fehlgeschlagen.'], 500);
        }

        return Response::json(['weiter' => self::START . '/' . $id], 201);
    }

    /**
     * The preview (with the pattern of `?muster=`), the running chain or the
     * result - whatever the import's status is.
     *
     * @param array<string, string> $params
     */
    public function ansicht(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->gesperrt();
        }
        $muster = $request->query['muster'] ?? null;

        return $this->importSeite($tresor, (int) $params['id'], is_string($muster) ? $muster : null, null);
    }

    /**
     * @param array<string, string> $params
     */
    public function starten(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure(self::START . '/' . $id);
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->gesperrt();
        }

        $muster = $request->post['muster'] ?? '';
        $muster = is_string($muster) ? $muster : '';
        try {
            $this->import()->starten($id, $muster, new \DateTimeImmutable());
        } catch (UngueltigesDateinamenMuster | ArchivImportFehler $e) {
            return $this->importSeite($tresor, $id, $muster, $e->getMessage(), 422);
        }

        return Response::redirect(self::START . '/' . $id);
    }

    /**
     * One step of the chain, as JSON for public/js/archivimport.js.
     *
     * @param array<string, string> $params
     */
    public function schritt(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return Response::json(['fehler' => self::CSRF_JSON], 403);
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return Response::json(['fehler' => self::TRESOR_GESPERRT], 403);
        }

        try {
            $stand = $this->import()->schritt($tresor, (int) $params['id'], $this->session->userId(), $request->ip, new \DateTimeImmutable());
        } catch (ArchivImportFehler $e) {
            return Response::json(['fehler' => $e->getMessage()], 409);
        }

        return Response::json($stand->toArray());
    }

    /**
     * @param array<string, string> $params
     */
    public function verwerfen(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure(self::START . '/' . $id);
        }

        if (!$this->import()->verwerfen($id, $this->session->userId(), $request->ip, new \DateTimeImmutable())) {
            $this->session->flash('Dieser Import ist schon beendet.', FlashArt::Fehler);

            return Response::redirect(self::START . '/' . $id);
        }
        $this->session->flash('Import abgebrochen, die hochgeladene ZIP-Datei ist gelöscht. Bereits übernommene Belege bleiben erhalten.');

        return Response::redirect(self::START);
    }

    private function importSeite(Vault $tresor, int $id, ?string $muster, ?string $fehler, int $status = 200): ResponseInterface
    {
        $import = $this->import();
        try {
            $datensatz = $import->finde($id);
        } catch (ArchivImportFehler) {
            return $this->nichtGefunden();
        }

        $vorschau = $datensatz->status === ArchiveImportStatus::Vorschau
            ? $import->vorschau($tresor, $id, $muster, new \DateTimeImmutable())
            : null;
        $datensatz = $vorschau?->import ?? $datensatz;

        return Response::html($this->view->render('app/archiv-import-ansicht', [
            'title' => 'Archiv-Import',
            'flash' => $this->session->pullFlash(),
            'csrf' => $this->session->csrfToken(),
            'import' => $datensatz,
            'vorschau' => $vorschau,
            'bericht' => $datensatz->status->offen() && $datensatz->status !== ArchiveImportStatus::Laeuft ? [] : $import->bericht($tresor, $datensatz),
            'platzhalter' => DateinamenMuster::PLATZHALTER,
            'fehler' => $fehler,
            'scripts' => $datensatz->status === ArchiveImportStatus::Laeuft ? ['/js/archivimport.js'] : [],
        ], Area::App), $status);
    }

    private function import(): ArchivImport
    {
        return ($this->import)();
    }

    private function tresor(Request $request): ?Vault
    {
        try {
            return $this->sessionVault->unlock(Cookie::vaultKeyFrom($request));
        } catch (CryptoException) {
            return null;
        }
    }

    /** Back to the start page, which explains the locked vault; nothing was written. */
    private function gesperrt(): ResponseInterface
    {
        $this->session->flash(self::TRESOR_GESPERRT, FlashArt::Fehler);

        return Response::redirect(self::START);
    }

    private function nichtGefunden(): ResponseInterface
    {
        return Response::html($this->view->render('error', [
            'title' => 'Import nicht gefunden',
            'message' => 'Diesen Archiv-Import gibt es nicht.',
            'startseite' => self::START,
        ], Area::App), 404);
    }

    private function csrfFailure(string $ziel): ResponseInterface
    {
        $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

        return Response::redirect($ziel);
    }
}
