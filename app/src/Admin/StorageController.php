<?php

declare(strict_types=1);

namespace App\Admin;

use App\Domain\BlobStorage;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Service\Storage\StorageSwitchService;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * Admin page for the blob storage (M2-5, issue #12): where the encrypted
 * files lie, switching the backend, and the integrity check.
 *
 * Like the update page this is a step chain: the page's JavaScript calls one
 * endpoint per step, and every step is idempotent, so a closed tab, a
 * timeout or a crash costs nothing but the current request (CLAUDE.md
 * section 1). Nothing in here decrypts - the switch copies ciphertext, and
 * the check compares checksums.
 *
 * ---------------------------------------------------------------------
 * LOGIN REQUIRED since M3-3, exactly like UpdateController: the routes are
 * wrapped in App\Http\LoginGuard (app/src/routes.php). Roles and the
 * Permission enum are still M3-6 (docs/spec/01-sicherheit.md section 4),
 * so until then every account that can log in can move every stored file
 * from here. CSRF is enforced regardless.
 * ---------------------------------------------------------------------
 */
final readonly class StorageController
{
    public function __construct(
        private View $view,
        private Session $session,
        private StorageSwitchService $storage,
    ) {
    }

    public function page(Request $request): ResponseInterface
    {
        $this->session->start();

        return Response::html($this->view->render('admin/speicher', [
            'title' => 'Speicher',
            // External file, never inline: script-src 'self' without
            // 'unsafe-inline' (CLAUDE.md section 4).
            'scripts' => ['/js/speicher.js'],
            'csrf' => $this->session->csrfToken(),
            'flash' => $this->session->pullFlash(),
            'state' => $this->storage->state(),
            'backends' => BlobStorage::cases(),
        ], Area::Admin));
    }

    /**
     * Picks the target backend. It takes effect immediately - new uploads go
     * there from this moment on - and the chain then carries the stock over.
     */
    public function setTarget(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure();
        }

        $ziel = BlobStorage::tryFrom((string) ($request->post['ziel'] ?? ''));
        if ($ziel === null) {
            $this->session->flash('Unbekanntes Speicher-Backend.', FlashArt::Fehler);

            return Response::redirect('/admin/speicher');
        }

        $state = $this->storage->setTarget($ziel);
        $this->session->flash($state->fertig()
            ? sprintf('Speicher-Backend „%s" ist gesetzt – es liegt bereits alles dort.', $ziel->bezeichnung())
            : sprintf(
                'Speicher-Backend „%s" ist gesetzt. %d Dateien werden jetzt verschoben.',
                $ziel->bezeichnung(),
                $state->offen,
            ));

        return Response::redirect('/admin/speicher');
    }

    /**
     * One step per request. The browser repeats `verschieben` and `pruefen`
     * until the answer says there is nothing left.
     *
     * @param array<string, string> $params
     */
    public function step(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return Response::json(['fehler' => 'Sitzung abgelaufen – bitte die Seite neu laden.'], 403);
        }

        try {
            $state = match ($params['schritt']) {
                'verschieben' => $this->storage->move(),
                'aufraeumen' => $this->storage->cleanUp(),
                'pruefstart' => $this->storage->startCheck(),
                'pruefen' => $this->storage->verify(),
                default => null,
            };
        } catch (\Throwable $e) {
            // Class and message only, like everywhere else: a message may end
            // up in a log, and nothing from a receipt belongs there.
            return Response::json(['fehler' => $e->getMessage()], 500);
        }

        if ($state === null) {
            return Response::json(['fehler' => 'Unbekannter Schritt.'], 404);
        }

        return Response::json($state->toArray());
    }

    private function csrfFailure(): ResponseInterface
    {
        $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

        return Response::redirect('/admin/speicher');
    }
}
