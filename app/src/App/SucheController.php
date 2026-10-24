<?php

declare(strict_types=1);

namespace App\App;

use App\Domain\Berechtigungen;
use App\Domain\Permission;
use App\Domain\Zugriffsbereich;
use App\Http\Cookie;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\CostCenterRepository;
use App\Service\Account\SessionVault;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\Suche\Suchanfrage;
use App\Service\Suche\Suchergebnis;
use App\Service\Suche\SuchindexStand;
use App\Service\Suche\Volltextsuche;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * The full-text search /app/suche (issue #94, docs/spec/
 * 03-erfassung-und-ki.md section 10): find receipts by their recognised
 * text, within the `inbox.view` scope.
 *
 * The search itself is a POST: what one searches for (a supplier, a name)
 * is club data and must not end up in URLs, server logs or the browser
 * history (CLAUDE.md section 4). The result page is `no-store` for the same
 * reason as the inbox's pages.
 *
 * Without an unlocked vault there is nothing to search - the texts are vault
 * ciphertext, and so is the session index's key. When the index is not up
 * to date and that takes more than one step, the page shows how far it is
 * and public/js/suche.js runs the step chain (POST /app/suche/index), then
 * sends the search form again.
 */
final readonly class SucheController
{
    private const string TRESOR_GESPERRT = 'Ihr Tresor ist in dieser Sitzung nicht entsperrt. Bitte melden Sie sich neu an.';

    public function __construct(
        private View $view,
        private Session $session,
        private SessionVault $sessionVault,
        private Volltextsuche $suche,
        private CostCenterRepository $kostenstellen,
    ) {
    }

    public function seite(Request $request): ResponseInterface
    {
        $this->session->start();

        return $this->antwort($request, null);
    }

    public function suchen(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut suchen.', FlashArt::Fehler);

            return Response::redirect('/app/suche');
        }

        $eingabe = $request->post['q'] ?? '';

        return $this->antwort($request, Suchanfrage::aus(is_string($eingabe) ? $eingabe : ''));
    }

    /** One step of the index chain - JSON for public/js/suche.js. */
    public function schritt(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return Response::json(['fehler' => 'Sitzung abgelaufen – bitte die Seite neu laden.'], 403);
        }
        $tresor = $this->tresor($request);
        $benutzer = $this->session->userId();
        if ($tresor === null || $benutzer === null) {
            return Response::json(['fehler' => self::TRESOR_GESPERRT], 403);
        }

        $stand = $this->suche->schritt($benutzer, $this->bereich(), $tresor);

        return Response::json(['fertig' => $stand->aktuell(), 'offen' => $stand->offen]);
    }

    /** "Suchindex neu aufbauen": throws this session's index away, the page builds a new one. */
    public function neu(Request $request): ResponseInterface
    {
        $this->session->start();
        if ($this->session->checkCsrf($request)) {
            $this->suche->verwerfen();
        }

        return Response::redirect('/app/suche');
    }

    private function antwort(Request $request, ?Suchanfrage $anfrage): ResponseInterface
    {
        $tresor = $this->tresor($request);
        $benutzer = $this->session->userId();
        $stand = null;
        $ergebnis = null;
        if ($tresor !== null && $benutzer !== null) {
            [$stand, $ergebnis] = $this->standUndErgebnis($benutzer, $tresor, $anfrage);
        }

        $html = $this->view->render('app/suche', [
            'title' => 'Volltextsuche',
            'csrf' => $this->session->csrfToken(),
            'flash' => $this->session->pullFlash(),
            'entsperrt' => $tresor !== null,
            'anfrage' => $anfrage,
            'stand' => $stand,
            'ergebnis' => $ergebnis,
            'kostenstellen' => $this->kostenstellenNamen(),
            'heute' => new \DateTimeImmutable('today'),
            'scripts' => $stand !== null && !$stand->aktuell() ? ['/js/suche.js'] : [],
        ], Area::App);

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store, private'], $html);
    }

    /**
     * @return array{0: SuchindexStand, 1: ?Suchergebnis}
     */
    private function standUndErgebnis(int $benutzer, Vault $tresor, ?Suchanfrage $anfrage): array
    {
        $bereich = $this->bereich();
        $stand = $this->suche->nachziehen($benutzer, $bereich, $tresor);
        if (!$stand->aktuell() || $anfrage === null || $anfrage->leer()) {
            return [$stand, null];
        }

        $ergebnis = $this->suche->suche($benutzer, $bereich, $tresor, $anfrage);
        if ($ergebnis === null) {
            // The index broke (a part went missing) and was thrown away.
            return [$this->suche->stand($benutzer, $bereich, $tresor), null];
        }

        return [$stand, $ergebnis];
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
     * @return array<int, string> every cost center by id, inactive ones too
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
}
