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
use App\Service\Account\SessionVault;
use App\Service\Bank\BankRuleViolation;
use App\Service\Bank\Erstattungen;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * Reimbursements for one booking /app/buchungen/{id}/erstattung (M10-3,
 * issue #67, docs/spec/04-bank-und-abgleich.md section 5, E-36): the open
 * reimbursements of the counterparty's IBAN and the combinations that add
 * up to the transfer - one click allocates a combination.
 *
 * Both routes need `matching.edit` (app/src/routes.php); its scope applies
 * to the booking (booking date) and to the receipts (receipt date, cost
 * center). Without the unlocked vault nothing is read or written. The
 * form carries invoice ids only; the service checks everything again
 * under the row locks.
 */
final readonly class ErstattungController
{
    private const string TRESOR_GESPERRT = 'Ihr Tresor ist in dieser Sitzung nicht entsperrt. Bitte melden Sie sich neu an.';

    public function __construct(
        private View $view,
        private Session $session,
        private SessionVault $sessionVault,
        private Erstattungen $erstattungen,
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function ansicht(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            $this->session->flash(self::TRESOR_GESPERRT, FlashArt::Fehler);

            return Response::redirect('/app/buchungen');
        }
        $vorschlag = $this->erstattungen->vorschlag($tresor, (int) $params['id'], $this->bereich());
        if ($vorschlag === null) {
            return $this->nichtGefunden();
        }

        return Response::html($this->view->render('app/erstattung', [
            'title' => 'Erstattung zuordnen',
            'flash' => $this->session->pullFlash(),
            'vorschlag' => $vorschlag,
        ], Area::App));
    }

    /**
     * @param array<string, string> $params
     */
    public function zuordnen(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        $zurueck = '/app/buchungen/' . $id . '/erstattung';
        if (!$this->session->checkCsrf($request)) {
            $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

            return Response::redirect($zurueck);
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            $this->session->flash('Die Zuordnung wurde nicht übernommen. ' . self::TRESOR_GESPERRT, FlashArt::Fehler);

            return Response::redirect('/app/buchungen');
        }

        $wert = $request->post['belege'] ?? '';
        $belege = [];
        foreach (explode(',', is_string($wert) ? $wert : '') as $teil) {
            if (preg_match('/^\d{1,18}$/', trim($teil)) === 1) {
                $belege[] = (int) trim($teil);
            }
        }

        try {
            $zuordnungen = $this->erstattungen->zuordnen($tresor, $id, $belege, $this->bereich(), $this->session->userId(), $request->ip, new \DateTimeImmutable());
        } catch (BankRuleViolation $e) {
            $this->session->flash($e->getMessage(), FlashArt::Fehler);

            return Response::redirect($zurueck);
        }

        $this->session->flash(count($zuordnungen) === 1
            ? 'Ein Beleg wurde der Buchung als Erstattung zugeordnet.'
            : sprintf('%d Belege wurden der Buchung als Erstattung zugeordnet.', count($zuordnungen)));

        return Response::redirect('/app/buchungen/' . $id);
    }

    private function bereich(): Zugriffsbereich
    {
        return ($this->view->berechtigungen() ?? Berechtigungen::keine())->zugriffsbereich(Permission::MatchingEdit);
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
            'title' => 'Buchung nicht gefunden',
            'message' => 'Diese Buchung gibt es nicht (mehr).',
            'startseite' => '/app/buchungen',
        ], Area::App), 404);
    }
}
