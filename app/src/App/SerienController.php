<?php

declare(strict_types=1);

namespace App\App;

use App\Domain\Berechtigungen;
use App\Domain\Permission;
use App\Domain\SeriesPeriod;
use App\Domain\SeriesStatus;
use App\Http\Cookie;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Service\Account\SessionVault;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\Invoice\InvoiceRuleViolation;
use App\Service\Invoice\SerienAnsicht;
use App\Service\Invoice\SerienKennzahlen;
use App\Service\Invoice\Serien;
use App\Service\Processing\Betrag;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * Recurring invoices /app/serien (M8-1, issue #57, docs/spec/
 * 03-erfassung-und-ki.md section 9): the overview "Wiederkehrende Kosten"
 * (issue #58/M8-2) - the suggestions the `detect_recurring` job found and
 * the confirmed series with newest receipt, yearly projection, trend and
 * the "überfällig" mark; one series' page to confirm a
 * suggestion (rhythm, expected amount, next date, tolerance, label,
 * contract reference, which receipts belong to it), change a confirmed one
 * or dismiss either.
 *
 * Behind `document.edit` (app/src/routes.php). A series is about a
 * supplier, across every cost center and period - an account whose
 * `document.edit` carries a scope sees amounts of receipts outside it, so
 * it gets a refusal instead (E-26). Amounts and names are vault data:
 * without an unlocked vault the pages say so and show nothing.
 *
 * Nothing of a series goes into a URL, a flash message or the audit log
 * but its id.
 */
final readonly class SerienController
{
    private const string TRESOR_GESPERRT = 'Ihr Tresor ist in dieser Sitzung nicht entsperrt. Bitte melden Sie sich neu an.';

    public function __construct(
        private View $view,
        private Session $session,
        private SessionVault $sessionVault,
        private Serien $serien,
    ) {
    }

    public function liste(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->unbeschraenkt()) {
            return $this->eingeschraenkt();
        }
        $tresor = $this->tresor($request);
        $liste = $tresor === null ? [] : $this->serien->liste($tresor, new \DateTimeImmutable());
        $bestaetigt = array_values(array_filter($liste, static fn(SerienAnsicht $s): bool => $s->record->status === SeriesStatus::Bestaetigt));

        return Response::html($this->view->render('app/serien', [
            'title' => 'Wiederkehrende Kosten',
            'flash' => $this->session->pullFlash(),
            'entsperrt' => $tresor !== null,
            'vorschlaege' => array_values(array_filter($liste, static fn(SerienAnsicht $s): bool => $s->record->status === SeriesStatus::Vorgeschlagen)),
            'bestaetigt' => $bestaetigt,
            'summen' => SerienKennzahlen::summen($bestaetigt),
        ], Area::App));
    }

    /**
     * @param array<string, string> $params
     */
    public function formular(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        if (!$this->unbeschraenkt()) {
            return $this->eingeschraenkt();
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->gesperrt();
        }
        $serie = $this->serien->ansicht($tresor, (int) $params['id']);
        if ($serie === null) {
            return $this->nichtGefunden();
        }

        return $this->seite($serie, self::felderAus($serie), array_map(static fn($p): int => $p->invoiceId, $serie->mitglieder), null);
    }

    /**
     * @param array<string, string> $params
     */
    public function speichern(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/app/serien/' . $id);
        }
        if (!$this->unbeschraenkt()) {
            return $this->eingeschraenkt();
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->gesperrt(true);
        }
        $serie = $this->serien->ansicht($tresor, $id);
        if ($serie === null) {
            return $this->nichtGefunden();
        }

        $felder = self::felder($request);
        $belege = self::belege($request);
        $warVorschlag = $serie->record->status === SeriesStatus::Vorgeschlagen;
        try {
            $this->serien->speichern($tresor, $id, $felder, $belege, $this->session->userId(), $request->ip, new \DateTimeImmutable());
        } catch (InvoiceRuleViolation $e) {
            return $this->seite($serie, $felder, $belege, $e, 422);
        }

        $this->session->flash($warVorschlag ? 'Serie bestätigt.' : 'Serie gespeichert.');

        return Response::redirect('/app/serien/' . $id);
    }

    /**
     * @param array<string, string> $params
     */
    public function verwerfen(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/app/serien/' . $id);
        }
        if (!$this->unbeschraenkt()) {
            return $this->eingeschraenkt();
        }
        // Like deleting a supplier: no ciphertext is touched, but a page
        // that cannot show the series should not settle it either.
        if ($this->tresor($request) === null) {
            return $this->gesperrt(true);
        }

        try {
            $this->serien->verwerfen($id, $this->session->userId(), $request->ip, new \DateTimeImmutable());
            $this->session->flash('Serie verworfen. Ihre Belege werden nicht noch einmal vorgeschlagen.');
        } catch (InvoiceRuleViolation $e) {
            $this->session->flash($e->getMessage(), FlashArt::Fehler);
        }

        return Response::redirect('/app/serien');
    }

    /**
     * @param array<string, string> $felder
     * @param list<int> $belege the receipts ticked
     */
    private function seite(SerienAnsicht $serie, array $felder, array $belege, ?InvoiceRuleViolation $fehler, int $status = 200): ResponseInterface
    {
        return Response::html($this->view->render('app/serie', [
            'title' => $serie->record->status === SeriesStatus::Vorgeschlagen ? 'Serien-Vorschlag prüfen' : 'Wiederkehrende Rechnung',
            'flash' => $this->session->pullFlash(),
            'serie' => $serie,
            'felder' => $felder,
            'belege' => $belege,
            'fehler' => $fehler?->getMessage(),
            'fehlerFeld' => $fehler?->feld,
            'perioden' => SeriesPeriod::cases(),
        ], Area::App), $status);
    }

    /**
     * @return array<string, string>
     */
    private static function felderAus(SerienAnsicht $serie): array
    {
        return [
            'periode' => $serie->record->period->value,
            'betrag' => Betrag::format($serie->daten->expectedGross),
            'naechste' => $serie->record->nextExpected?->format('Y-m-d') ?? '',
            'toleranz' => (string) $serie->record->toleranceDays,
            'bezeichnung' => $serie->daten->label,
            'vertrag' => $serie->daten->contractRef,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function felder(Request $request): array
    {
        $felder = [];
        foreach (['periode', 'betrag', 'naechste', 'toleranz', 'bezeichnung', 'vertrag'] as $feld) {
            $wert = $request->post[$feld] ?? '';
            $felder[$feld] = is_string($wert) ? $wert : '';
        }

        return $felder;
    }

    /**
     * @return list<int>
     */
    private static function belege(Request $request): array
    {
        $werte = $request->post['belege'] ?? [];
        $ids = [];
        foreach (is_array($werte) ? $werte : [] as $wert) {
            if (is_string($wert) && preg_match('/^[1-9]\d{0,18}$/', $wert) === 1) {
                $ids[] = (int) $wert;
            }
        }

        return array_values(array_unique($ids));
    }

    private function unbeschraenkt(): bool
    {
        $berechtigungen = $this->view->berechtigungen() ?? Berechtigungen::keine();

        return $berechtigungen->zugriffsbereich(Permission::DocumentEdit)->istUnbeschraenkt();
    }

    private function tresor(Request $request): ?Vault
    {
        try {
            return $this->sessionVault->unlock(Cookie::vaultKeyFrom($request));
        } catch (CryptoException) {
            return null;
        }
    }

    private function gesperrt(bool $geschrieben = false): ResponseInterface
    {
        if ($geschrieben) {
            $this->session->flash('Die Änderung wurde nicht übernommen. ' . self::TRESOR_GESPERRT, FlashArt::Fehler);
        }

        return Response::redirect('/app/serien');
    }

    private function eingeschraenkt(): ResponseInterface
    {
        return Response::html($this->view->render('error', [
            'title' => 'Nicht verfügbar',
            'message' => 'Wiederkehrende Rechnungen umfassen alle Belege eines Lieferanten. Ihr Zugriff ist auf einen Bereich beschränkt, deshalb ist diese Seite für Sie nicht verfügbar.',
            'startseite' => '/app',
        ], Area::App), 403);
    }

    private function nichtGefunden(): ResponseInterface
    {
        return Response::html($this->view->render('error', [
            'title' => 'Serie nicht gefunden',
            'message' => 'Diese Serie gibt es nicht (mehr).',
            'startseite' => '/app/serien',
        ], Area::App), 404);
    }

    private function csrfFailure(string $ziel): ResponseInterface
    {
        $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

        return Response::redirect($ziel);
    }
}
