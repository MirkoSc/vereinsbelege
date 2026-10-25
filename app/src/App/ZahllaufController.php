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
use App\Repository\SettingRepository;
use App\Service\Account\SessionVault;
use App\Service\Bank\BankRuleViolation;
use App\Service\Bank\Zahllaeufe;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\Mail\MailSettingsRepository;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * SEPA payment runs for reimbursements /app/zahllaeufe (issue #85,
 * docs/spec/04-bank-und-abgleich.md section 5 "Stand SEPA-Zahllauf", E-42):
 * the list, a new run from the open reimbursements, one run with its file
 * as download, discarding a run.
 *
 * Every route needs `matching.edit` (app/src/routes.php); its scope limits
 * the receipts offered and the runs shown. Without the unlocked vault
 * nothing is read or written. The form carries invoice ids, the account
 * id, the payer's name and the date; App\Service\Bank\Zahllaeufe checks
 * everything again under the row locks.
 */
final readonly class ZahllaufController
{
    private const string TRESOR_GESPERRT = 'Ihr Tresor ist in dieser Sitzung nicht entsperrt. Bitte melden Sie sich neu an.';

    public function __construct(
        private View $view,
        private Session $session,
        private SessionVault $sessionVault,
        private Zahllaeufe $zahllaeufe,
        private SettingRepository $settings,
    ) {
    }

    public function liste(Request $request): ResponseInterface
    {
        $this->session->start();
        $tresor = $this->tresor($request);
        $ergebnis = $tresor === null ? ['laeufe' => [], 'unlesbar' => 0] : $this->zahllaeufe->liste($tresor, $this->bereich());

        return Response::html($this->view->render('app/zahllaeufe', [
            'title' => 'SEPA-Zahlläufe',
            'flash' => $this->session->pullFlash(),
            'entsperrt' => $tresor !== null,
            ...$ergebnis,
        ], Area::App));
    }

    public function neu(Request $request): ResponseInterface
    {
        $this->session->start();
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            $this->session->flash(self::TRESOR_GESPERRT, FlashArt::Fehler);

            return Response::redirect('/app/zahllaeufe');
        }
        $heute = new \DateTimeImmutable('today');

        return Response::html($this->view->render('app/zahllauf-neu', [
            'title' => 'Neuer SEPA-Zahllauf',
            'flash' => $this->session->pullFlash(),
            'vorbereitung' => $this->zahllaeufe->vorbereitung($tresor, $this->bereich(), $heute),
            'auftraggeber' => $this->settings->get(MailSettingsRepository::VEREINSNAME),
            'ausfuehrung' => $heute->format('Y-m-d'),
        ], Area::App));
    }

    public function anlegen(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

            return Response::redirect('/app/zahllaeufe/neu');
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            $this->session->flash('Der Zahllauf wurde nicht angelegt. ' . self::TRESOR_GESPERRT, FlashArt::Fehler);

            return Response::redirect('/app/zahllaeufe');
        }

        $belege = [];
        $wert = $request->post['belege'] ?? [];
        foreach (is_array($wert) ? $wert : [] as $teil) {
            if (is_string($teil) && preg_match('/^\d{1,18}$/', $teil) === 1) {
                $belege[] = (int) $teil;
            }
        }
        $konto = $request->post['konto'] ?? '';
        $datum = $request->post['ausfuehrung'] ?? '';
        $ausfuehrung = is_string($datum) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $datum) : false;
        if ($ausfuehrung === false || $ausfuehrung->format('Y-m-d') !== $datum) {
            $this->session->flash('Bitte ein gültiges Ausführungsdatum angeben.', FlashArt::Fehler);

            return Response::redirect('/app/zahllaeufe/neu');
        }
        $auftraggeber = $request->post['auftraggeber'] ?? '';

        try {
            $id = $this->zahllaeufe->anlegen(
                $tresor,
                $this->bereich(),
                $belege,
                is_string($konto) && preg_match('/^\d{1,18}$/', $konto) === 1 ? (int) $konto : 0,
                is_string($auftraggeber) ? $auftraggeber : '',
                $ausfuehrung,
                $this->session->userId(),
                $request->ip,
                new \DateTimeImmutable(),
            );
        } catch (BankRuleViolation $e) {
            $this->session->flash($e->getMessage(), FlashArt::Fehler);

            return Response::redirect('/app/zahllaeufe/neu');
        }

        $this->session->flash('Der Zahllauf wurde angelegt. Laden Sie die SEPA-Datei herunter und reichen Sie sie im Online-Banking ein.');

        return Response::redirect('/app/zahllaeufe/' . $id);
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

            return Response::redirect('/app/zahllaeufe');
        }
        try {
            $lauf = $this->zahllaeufe->finde($tresor, (int) $params['id'], $this->bereich());
        } catch (CryptoException | \JsonException) {
            $this->session->flash('Dieser Zahllauf lässt sich nicht entschlüsseln.', FlashArt::Fehler);

            return Response::redirect('/app/zahllaeufe');
        }
        if ($lauf === null) {
            return $this->nichtGefunden();
        }

        return Response::html($this->view->render('app/zahllauf', [
            'title' => 'SEPA-Zahllauf ' . $lauf->record->id,
            'flash' => $this->session->pullFlash(),
            'lauf' => $lauf,
        ], Area::App));
    }

    /**
     * The pain.001 file: built in memory, sent as attachment and never
     * cached - nothing of it is written to disk.
     *
     * @param array<string, string> $params
     */
    public function datei(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            $this->session->flash(self::TRESOR_GESPERRT, FlashArt::Fehler);

            return Response::redirect('/app/zahllaeufe');
        }
        try {
            $datei = $this->zahllaeufe->datei($tresor, $id, $this->bereich(), $this->session->userId(), $request->ip, new \DateTimeImmutable());
        } catch (CryptoException | \JsonException | BankRuleViolation $e) {
            $this->session->flash($e instanceof BankRuleViolation ? $e->getMessage() : 'Dieser Zahllauf lässt sich nicht entschlüsseln.', FlashArt::Fehler);

            return Response::redirect('/app/zahllaeufe/' . $id);
        }
        if ($datei === null) {
            return $this->nichtGefunden();
        }

        return new Response(200, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $datei['name'] . '"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ], $datei['xml']);
    }

    /**
     * @param array<string, string> $params
     */
    public function verwerfen(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

            return Response::redirect('/app/zahllaeufe/' . $id);
        }
        if ($this->tresor($request) === null) {
            $this->session->flash('Der Zahllauf wurde nicht verworfen. ' . self::TRESOR_GESPERRT, FlashArt::Fehler);

            return Response::redirect('/app/zahllaeufe');
        }
        try {
            $this->zahllaeufe->verwerfen($id, $this->bereich(), $this->session->userId(), $request->ip, new \DateTimeImmutable());
        } catch (BankRuleViolation $e) {
            $this->session->flash($e->getMessage(), FlashArt::Fehler);

            return Response::redirect('/app/zahllaeufe/' . $id);
        }
        $this->session->flash('Der Zahllauf wurde verworfen. Seine Belege stehen wieder für einen neuen Zahllauf bereit.');

        return Response::redirect('/app/zahllaeufe/' . $id);
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
            'title' => 'Zahllauf nicht gefunden',
            'message' => 'Diesen Zahllauf gibt es nicht (mehr).',
            'startseite' => '/app/zahllaeufe',
        ], Area::App), 404);
    }
}
