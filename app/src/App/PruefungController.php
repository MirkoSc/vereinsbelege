<?php

declare(strict_types=1);

namespace App\App;

use App\Domain\Berechtigungen;
use App\Domain\DocumentStatus;
use App\Domain\InboxItem;
use App\Domain\InvoiceDirection;
use App\Domain\InvoiceType;
use App\Domain\Permission;
use App\Domain\Supplier;
use App\Domain\Zugriffsbereich;
use App\Http\Cookie;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Http\StreamResponse;
use App\Repository\CategoryRepository;
use App\Repository\CostCenterRepository;
use App\Service\Account\SessionVault;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\Invoice\InvoiceRuleViolation;
use App\Service\Invoice\Pruefung;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * The review page /app/belege/pruefen (issue #37/M6-3, docs/spec/
 * 03-erfassung-und-ki.md section 6 "Prüfansicht"): the queue of documents
 * to capture, and one document with its pages next to the form - dates,
 * amounts, number, direction, partner, category, cost center - and the
 * action "Geprüft, nächster".
 *
 * Behind `document.edit` (Admin, Finanzen - app/src/routes.php), narrowed
 * to that right's scope in SQL: an id outside it answers 404 like one that
 * does not exist. Everything of a receipt is vault data - without the
 * unlocked vault the page says so and shows and writes nothing. Creating a
 * supplier from the form additionally needs `supplier.manage`, checked
 * again by App\Service\Invoice\Pruefung.
 *
 * Nothing of a receipt goes into a URL or a flash message.
 */
final readonly class PruefungController
{
    private const string TRESOR_GESPERRT = 'Ihr Tresor ist in dieser Sitzung nicht entsperrt. Bitte melden Sie sich neu an.';

    public function __construct(
        private View $view,
        private Session $session,
        private SessionVault $sessionVault,
        private Pruefung $pruefung,
        private CategoryRepository $kategorien,
        private CostCenterRepository $kostenstellen,
    ) {
    }

    public function liste(Request $request): ResponseInterface
    {
        $this->session->start();

        $eintraege = $this->pruefung->warteschlange($this->bereich());

        return Response::html($this->view->render('app/pruefen-liste', [
            'title' => 'Belege prüfen',
            'flash' => $this->session->pullFlash(),
            'eintraege' => $eintraege,
            'abgeschnitten' => count($eintraege) >= Pruefung::WARTESCHLANGE,
            'kostenstellen' => $this->kostenstellenNamen(),
            'entsperrt' => $this->tresor($request) !== null,
        ], Area::App));
    }

    /**
     * @param array<string, string> $params
     */
    public function formular(Request $request, array $params): ResponseInterface
    {
        $this->session->start();

        $eintrag = $this->pruefung->eintrag((int) $params['id'], $this->bereich());
        if ($eintrag === null) {
            return $this->nichtGefunden();
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->seite($eintrag, null, [], null);
        }

        $document = $eintrag->document;

        return $this->seite($eintrag, $tresor, $this->pruefung->felder($document, $this->pruefung->beleg($document, $tresor)), null);
    }

    /**
     * @param array<string, string> $params
     */
    public function speichern(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        $ziel = '/app/belege/pruefen/' . $id;
        if (!$this->session->checkCsrf($request)) {
            $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

            return Response::redirect($ziel);
        }

        $eintrag = $this->pruefung->eintrag($id, $this->bereich());
        if ($eintrag === null) {
            return $this->nichtGefunden();
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            $this->session->flash(self::TRESOR_GESPERRT, FlashArt::Fehler);

            return Response::redirect($ziel);
        }

        $felder = self::felder($request);
        $geprueft = ($request->post['aktion'] ?? '') === 'geprueft';
        try {
            $ergebnis = $this->pruefung->speichern(
                $eintrag->document,
                $tresor,
                $felder,
                $geprueft,
                $this->berechtigungen()->darf(Permission::SupplierManage),
                $this->session->userId(),
                $request->ip,
                new \DateTimeImmutable(),
            );
        } catch (InvoiceRuleViolation $e) {
            return $this->seite($eintrag, $tresor, $felder, $e, 422);
        }

        // Only the reference (plaintext) - the message shows on the next
        // document's page, so it has to say which one it was about.
        $referenz = $eintrag->referenz ?? '#' . $id;
        $meldung = sprintf($geprueft ? 'Beleg %s geprüft.' : 'Beleg %s gespeichert.', $referenz);
        if ($ergebnis->lieferantAngelegt) {
            $meldung .= ' Der neue Partner ist angelegt – weitere Angaben unter „Lieferanten“.';
        }
        if ($ergebnis->warnungen !== []) {
            $this->session->flash($meldung . ' Hinweis: ' . implode(' ', $ergebnis->warnungen), FlashArt::Warnung);
        } else {
            $this->session->flash($meldung);
        }

        if (!$geprueft) {
            return Response::redirect($ziel);
        }

        $naechster = $this->pruefung->naechster($this->bereich(), $id);

        return Response::redirect($naechster === null ? '/app/belege/pruefen' : '/app/belege/pruefen/' . $naechster);
    }

    /**
     * One page, decrypted piece by piece into the response - like the
     * inbox's (App\App\InboxController::datei()), plus the page images of
     * the document's PDFs.
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

        $eintrag = $this->pruefung->eintrag((int) $params['id'], $this->bereich());
        $datei = $eintrag === null ? null : $this->pruefung->datei($eintrag, (int) $params['blob'], $tresor);
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

    /**
     * @param array<string, string> $felder
     */
    private function seite(
        InboxItem $eintrag,
        ?Vault $tresor,
        array $felder,
        ?InvoiceRuleViolation $fehler,
        int $status = 200,
    ): ResponseInterface {
        $document = $eintrag->document;
        $bearbeitbar = $tresor !== null && $document->status->pruefbar();
        $warteschlange = $this->pruefung->warteschlange($this->bereich());
        $ids = array_map(static fn(InboxItem $item): int => $item->document->id, $warteschlange);
        $position = array_search($document->id, $ids, true);

        return Response::html($this->view->render('app/pruefen', [
            'title' => 'Beleg prüfen ' . ($eintrag->referenz ?? '#' . $document->id),
            'flash' => $this->session->pullFlash(),
            'eintrag' => $eintrag,
            'entsperrt' => $tresor !== null,
            'bearbeitbar' => $bearbeitbar,
            'ansehbar' => $tresor !== null && ($bearbeitbar || in_array($document->status, [DocumentStatus::Geprueft, DocumentStatus::Festgeschrieben], true)),
            'seiten' => $tresor === null ? ['bilder' => [], 'pdfs' => []] : $this->pruefung->seiten($document, $tresor),
            'felder' => $felder,
            'fehler' => $fehler?->getMessage(),
            'fehlerFeld' => $fehler?->feld,
            'konfliktId' => $fehler?->konfliktId,
            'richtungen' => InvoiceDirection::cases(),
            'belegarten' => InvoiceType::cases(),
            'kategorien' => $this->kategorieAuswahl($felder['kategorie'] ?? ''),
            'lieferanten' => $tresor === null ? [] : $this->lieferantenAuswahl($tresor),
            'kostenstellen' => $this->kostenstellenAuswahl($felder['kostenstelle'] ?? ''),
            'darfLieferantAnlegen' => $this->berechtigungen()->darf(Permission::SupplierManage),
            'position' => $position === false ? null : $position + 1,
            'anzahl' => count($ids),
            'naechster' => Pruefung::naechsterIn($ids, $document->id),
            'steuernMax' => Pruefung::STEUERN_MAX,
            'scripts' => ['/js/pruefansicht.js'],
        ], Area::App), $status);
    }

    /**
     * Every active category with its direction, plus the stored one if it
     * has been deactivated since. The page shows the ones of the chosen
     * direction; the service checks it again.
     *
     * @return list<array{id: int, name: string, richtung: string}>
     */
    private function kategorieAuswahl(string $gewaehlt): array
    {
        $auswahl = [];
        foreach ($this->kategorien->all() as $kategorie) {
            if ($kategorie->active || (string) $kategorie->id === $gewaehlt) {
                $auswahl[] = [
                    'id' => $kategorie->id,
                    'name' => $kategorie->name . ($kategorie->active ? '' : ' (inaktiv)'),
                    'richtung' => $kategorie->direction->value,
                ];
            }
        }

        return $auswahl;
    }

    /**
     * @return list<array{id: int, name: string, rolle: string}>
     */
    private function lieferantenAuswahl(Vault $tresor): array
    {
        return array_map(
            static fn(Supplier $s): array => ['id' => $s->id, 'name' => $s->data->name, 'rolle' => $s->role->value],
            $this->pruefung->lieferanten($tresor),
        );
    }

    /**
     * @return array<int, string> the active ones, plus the chosen one if it
     *         has been deactivated since
     */
    private function kostenstellenAuswahl(string $gewaehlt): array
    {
        $auswahl = $this->kostenstellen->active();
        $alle = $this->kostenstellenNamen();
        if ($gewaehlt !== '' && ctype_digit($gewaehlt) && !isset($auswahl[(int) $gewaehlt]) && isset($alle[(int) $gewaehlt])) {
            $auswahl[(int) $gewaehlt] = $alle[(int) $gewaehlt] . ' (inaktiv)';
        }

        return $auswahl;
    }

    /**
     * @return array<int, string>
     */
    private function kostenstellenNamen(): array
    {
        $namen = [];
        foreach ($this->kostenstellen->all() as $kostenstelle) {
            $namen[$kostenstelle->id] = $kostenstelle->name;
        }

        return $namen;
    }

    /**
     * @return array<string, string>
     */
    private static function felder(Request $request): array
    {
        $felder = [];
        foreach (Pruefung::feldnamen() as $feld) {
            $wert = $request->post[$feld] ?? '';
            $felder[$feld] = is_string($wert) ? $wert : '';
        }

        return $felder;
    }

    private function berechtigungen(): Berechtigungen
    {
        return $this->view->berechtigungen() ?? Berechtigungen::keine();
    }

    private function bereich(): Zugriffsbereich
    {
        return $this->berechtigungen()->zugriffsbereich(Permission::DocumentEdit);
    }

    private function tresor(Request $request): ?Vault
    {
        try {
            return $this->sessionVault->unlock(Cookie::vaultKeyFrom($request));
        } catch (CryptoException) {
            return null;
        }
    }

    private function nichtGefunden(): ResponseInterface
    {
        return Response::html($this->view->render('error', [
            'title' => 'Beleg nicht gefunden',
            'message' => 'Diesen Beleg gibt es nicht, oder er liegt außerhalb Ihres Bereichs.',
            'startseite' => '/app/belege/pruefen',
        ], Area::App), 404);
    }
}
