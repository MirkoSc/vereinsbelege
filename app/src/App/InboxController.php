<?php

declare(strict_types=1);

namespace App\App;

use App\Domain\Berechtigungen;
use App\Domain\InboxAction;
use App\Domain\Permission;
use App\Domain\Zugriffsbereich;
use App\Http\Cookie;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Http\StreamResponse;
use App\Repository\CostCenterRepository;
use App\Service\Account\SessionVault;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\Inbox\InboxAnsicht;
use App\Service\Inbox\InboxFilter;
use App\Service\Inbox\InboxRuleViolation;
use App\Service\Inbox\Posteingang;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * The inbox /app/posteingang (issue #27/M4-5, docs/spec/02-datenmodell.md
 * "Statusmodell", docs/spec/03-erfassung-und-ki.md section 1): the filtered
 * list, one submission with its pages, and the decisions on it.
 *
 * Reading needs `inbox.view`, deciding `document.edit` (app/src/routes.php).
 * Which documents a viewer sees is the `inbox.view` scope
 * (App\Domain\Zugriffsbereich) - filtered in SQL by the repository, so an id
 * outside it answers 404 exactly like one that does not exist.
 *
 * Everything a submitter entered and every page is vault ciphertext: it
 * shows only in a session whose vault is unlocked. Without it the list
 * still shows reference, date, status and cost center (plaintext), and the
 * decisions are not offered - rejecting needs the document's key for the
 * reason, and deciding on a receipt nobody could read makes no sense.
 */
final readonly class InboxController
{
    private const string TRESOR_GESPERRT = 'Ihr Tresor ist in dieser Sitzung nicht entsperrt. Bitte melden Sie sich neu an.';

    public function __construct(
        private View $view,
        private Session $session,
        private SessionVault $sessionVault,
        private Posteingang $posteingang,
        private CostCenterRepository $kostenstellen,
    ) {
    }

    public function liste(Request $request): ResponseInterface
    {
        $this->session->start();

        $filter = InboxFilter::fromQuery($request->query);
        $tresor = $this->tresor($request);
        [$eintraege, $abgeschnitten] = $this->posteingang->liste($filter, $this->bereich(), $tresor, self::heute());

        return Response::html($this->view->render('app/posteingang', [
            'title' => 'Posteingang',
            'flash' => $this->session->pullFlash(),
            'filter' => $filter,
            'ansichten' => InboxAnsicht::cases(),
            'kostenstellen' => $this->kostenstellen->active(),
            'alleKostenstellen' => $this->kostenstellenNamen(),
            'eintraege' => $eintraege,
            'abgeschnitten' => $abgeschnitten,
            'entsperrt' => $tresor !== null,
            'heute' => self::heute(),
            ...$this->rasterungDaten(),
        ], Area::App));
    }

    /**
     * @param array<string, string> $params
     */
    public function detail(Request $request, array $params): ResponseInterface
    {
        $this->session->start();

        $tresor = $this->tresor($request);
        $eintrag = $this->posteingang->eintrag((int) $params['id'], $this->bereich(), $tresor);
        if ($eintrag === null) {
            return $this->nichtGefunden();
        }

        $document = $eintrag->item->document;
        $darfEntscheiden = $this->berechtigungen()->darf(Permission::DocumentEdit);
        $kostenstellen = $this->kostenstellen->active();
        $alle = $this->kostenstellenNamen();
        if ($document->costCenterId !== null && !isset($kostenstellen[$document->costCenterId])) {
            $kostenstellen[$document->costCenterId] = ($alle[$document->costCenterId] ?? '?') . ' (inaktiv)';
        }

        return Response::html($this->view->render('app/posteingang-detail', [
            'title' => 'Einreichung ' . ($eintrag->item->referenz ?? '#' . $document->id),
            'csrf' => $this->session->csrfToken(),
            'flash' => $this->session->pullFlash(),
            'eintrag' => $eintrag,
            'seiten' => $tresor === null ? [] : $this->posteingang->seiten($document, $tresor),
            'aktionen' => $darfEntscheiden && $tresor !== null ? InboxAction::fuer($document->status) : [],
            'darfEntscheiden' => $darfEntscheiden,
            'kostenstellen' => $kostenstellen,
            'alleKostenstellen' => $alle,
            'entsperrt' => $tresor !== null,
            'heute' => self::heute(),
            ...$this->rasterungDaten(),
        ], Area::App));
    }

    /**
     * One page, decrypted piece by piece into the response - never into a
     * file (CLAUDE.md section 4). `no-store`: a shared computer's browser
     * cache must not keep a receipt either.
     *
     * @param array<string, string> $params
     */
    public function datei(Request $request, array $params): ResponseInterface
    {
        $this->session->start();

        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return new Response(403, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store'], self::TRESOR_GESPERRT);
        }

        $eintrag = $this->posteingang->eintrag((int) $params['id'], $this->bereich(), null);
        $datei = $eintrag === null ? null : $this->posteingang->datei($eintrag->item, (int) $params['blob'], $tresor);
        if ($datei === null) {
            return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store'], 'Nicht gefunden.');
        }

        return new StreamResponse($datei['chunks'], [
            'Content-Type' => $datei['mime'],
            'Content-Disposition' => 'inline; filename="' . $datei['dateiname'] . '"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @param array<string, string> $params */
    public function annehmen(Request $request, array $params): ResponseInterface
    {
        return $this->entscheiden($request, $params, InboxAction::Annehmen);
    }

    /** @param array<string, string> $params */
    public function ablehnen(Request $request, array $params): ResponseInterface
    {
        return $this->entscheiden($request, $params, InboxAction::Ablehnen);
    }

    /** @param array<string, string> $params */
    public function wiedervorlage(Request $request, array $params): ResponseInterface
    {
        return $this->entscheiden($request, $params, InboxAction::Wiedervorlage);
    }

    /**
     * @param array<string, string> $params
     */
    public function kostenstelle(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        $ziel = '/app/posteingang/' . $id;
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure($ziel);
        }

        $eintrag = $this->posteingang->eintrag($id, $this->bereich(), null);
        if ($eintrag === null) {
            return $this->nichtGefunden();
        }

        $wert = $request->post['kostenstelle'] ?? '';
        $kostenstelle = is_string($wert) && ctype_digit($wert) && (int) $wert > 0 ? (int) $wert : null;

        try {
            $geaendert = $this->posteingang->kostenstelle($eintrag->item->document, $kostenstelle, $this->session->userId(), $request->ip, new \DateTimeImmutable());
        } catch (InboxRuleViolation $e) {
            $this->session->flash($e->getMessage(), FlashArt::Fehler);

            return Response::redirect($ziel);
        }

        $this->session->flash($geaendert ? 'Kostenstelle gespeichert.' : 'Die Kostenstelle war bereits so gesetzt.', $geaendert ? FlashArt::Ok : FlashArt::Info);

        return Response::redirect($ziel);
    }

    /**
     * @param array<string, string> $params
     */
    private function entscheiden(Request $request, array $params, InboxAction $aktion): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        $ziel = '/app/posteingang/' . $id;
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure($ziel);
        }

        $tresor = $this->tresor($request);
        if ($tresor === null) {
            $this->session->flash(self::TRESOR_GESPERRT, FlashArt::Fehler);

            return Response::redirect($ziel);
        }

        $eintrag = $this->posteingang->eintrag($id, $this->bereich(), null);
        if ($eintrag === null) {
            return $this->nichtGefunden();
        }

        $text = $request->post[$aktion === InboxAction::Ablehnen ? 'grund' : 'notiz'] ?? '';
        $datum = $request->post['datum'] ?? '';
        $datum = is_string($datum) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $datum) : false;

        try {
            $this->posteingang->entscheiden(
                $aktion,
                $eintrag->item->document,
                $tresor,
                is_string($text) ? $text : '',
                $datum === false ? null : $datum,
                $this->session->userId(),
                $request->ip,
                new \DateTimeImmutable(),
            );
        } catch (InboxRuleViolation $e) {
            $this->session->flash($e->getMessage(), FlashArt::Fehler);

            return Response::redirect($ziel);
        }

        $referenz = $eintrag->item->referenz ?? '#' . $id;
        $this->session->flash(match ($aktion) {
            InboxAction::Annehmen => sprintf('Einreichung %s angenommen.', $referenz),
            InboxAction::Ablehnen => sprintf('Einreichung %s abgelehnt.', $referenz),
            InboxAction::Wiedervorlage => sprintf('Einreichung %s auf Wiedervorlage gelegt.', $referenz),
        });

        // Back to the list: the decision is made, the next one is waiting.
        return Response::redirect('/app/posteingang');
    }

    /**
     * `public/js/rasterung.js` (issue #30/M4-8) mounts and loads pdf.js only
     * on the inbox pages (docs/spec/03-erfassung-und-ki.md section 3:
     * "während ein angemeldeter Nutzer den Posteingang geöffnet hat"),
     * unlike `public/js/jobs.js`, which runs on every authenticated page.
     * `?v=` cache-busts the same way every other asset does
     * (app/views/layout.php) - View::render() only hands the release
     * version to the layout, so a page whose script loads something outside
     * that mechanism has to build its own.
     *
     * Nothing at all for a viewer without `document.edit`: every
     * `/api/rasterung/...` route needs that right anyway, so there is
     * nothing for the script to usefully poll.
     *
     * @return array{scripts: list<string>, pdfjsSrc: string, pdfjsWorkerSrc: string, pdfjsWasmSrc: string}
     */
    private function rasterungDaten(): array
    {
        if (!$this->berechtigungen()->darf(Permission::DocumentEdit)) {
            return ['scripts' => [], 'pdfjsSrc' => '', 'pdfjsWorkerSrc' => '', 'pdfjsWasmSrc' => ''];
        }

        $v = $this->view->version();

        return [
            'scripts' => ['/js/rasterung.js'],
            'pdfjsSrc' => '/js/vendor/pdfjs/pdf.min.mjs?v=' . $v,
            'pdfjsWorkerSrc' => '/js/vendor/pdfjs/pdf.worker.min.mjs?v=' . $v,
            // No `?v=`: pdf.js appends a file name straight onto this
            // (public/js/vendor/README.md), a query string would land in
            // the middle of the path. Only reached for JBIG2/OpenJPEG-coded
            // pages (public/js/vendor/README.md), so a stale browser cache
            // here after a future pdf.js upgrade is a narrow enough risk to
            // accept rather than version a directory prefix.
            'pdfjsWasmSrc' => '/js/vendor/pdfjs/wasm/',
        ];
    }

    private function berechtigungen(): Berechtigungen
    {
        return $this->view->berechtigungen() ?? Berechtigungen::keine();
    }

    private function bereich(): Zugriffsbereich
    {
        return $this->berechtigungen()->zugriffsbereich(Permission::InboxView);
    }

    /**
     * @return array<int, string> every cost center by id, inactive ones too -
     *         a document may carry one that was deactivated since
     */
    private function kostenstellenNamen(): array
    {
        $namen = [];
        foreach ($this->kostenstellen->all() as $kostenstelle) {
            $namen[$kostenstelle->id] = $kostenstelle->name;
        }

        return $namen;
    }

    private function tresor(Request $request): ?Vault
    {
        try {
            return $this->sessionVault->unlock(Cookie::vaultKeyFrom($request));
        } catch (CryptoException) {
            return null;
        }
    }

    private static function heute(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today');
    }

    private function nichtGefunden(): ResponseInterface
    {
        return Response::html($this->view->render('error', [
            'title' => 'Einreichung nicht gefunden',
            'message' => 'Diese Einreichung gibt es nicht, oder sie liegt außerhalb Ihres Bereichs.',
            'startseite' => '/app/posteingang',
        ], Area::App), 404);
    }

    private function csrfFailure(string $ziel): ResponseInterface
    {
        $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

        return Response::redirect($ziel);
    }
}
