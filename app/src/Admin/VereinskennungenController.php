<?php

declare(strict_types=1);

namespace App\Admin;

use App\Domain\AuditAction;
use App\Http\Cookie;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Service\Account\SessionVault;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\MasterData\SupplierRuleViolation;
use App\Service\MasterData\Vereinskennung;
use App\Service\MasterData\Vereinskennungen;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * Admin page /admin/vereinskennungen (issue #245, E-67,
 * docs/spec/03-erfassung-und-ki.md section 7 "Stand #245"): the club's own
 * VAT id and tax number. Like the club's own IBANs, they never become a
 * supplier's; a supplier learns a VAT id or tax number from a receipt only
 * once this page was saved (both empty = the club has none).
 *
 * Permission: `admin.settings`. Vault data, so the page needs the unlocked
 * vault to show or change them. The audit log records which of the two
 * are set, never the values.
 */
final readonly class VereinskennungenController
{
    private const string SEITE = '/admin/vereinskennungen';

    private const string TRESOR_GESPERRT = 'Ihr Tresor ist in dieser Sitzung nicht entsperrt. Bitte melden Sie sich neu an.';

    public function __construct(
        private View $view,
        private Session $session,
        private SessionVault $sessionVault,
        private Vereinskennungen $kennungen,
        private AuditLog $audit,
    ) {
    }

    public function page(Request $request): ResponseInterface
    {
        $this->session->start();
        $tresor = $this->tresor($request);
        $kennung = null;
        if ($tresor !== null) {
            try {
                $kennung = $this->kennungen->lesen($tresor);
            } catch (CryptoException | \JsonException) {
                $kennung = null;
            }
        }

        return $this->formular($tresor !== null, $kennung?->ustId ?? '', $kennung?->steuernummer ?? '', null);
    }

    public function save(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->zurueck('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->zurueck('Nichts gespeichert. ' . self::TRESOR_GESPERRT, FlashArt::Fehler);
        }

        $ustId = self::feld($request, 'ust_id');
        $steuernummer = self::feld($request, 'steuernummer');
        try {
            $kennung = Vereinskennung::pruefen($ustId, $steuernummer);
        } catch (SupplierRuleViolation $fehler) {
            return $this->formular(true, $ustId, $steuernummer, $fehler->getMessage(), 422);
        }

        $this->kennungen->speichern($tresor, $kennung, $this->session->userId(), new \DateTimeImmutable());
        // Whether set, never the values.
        $this->audit->record(AuditAction::EinstellungVereinskennungen, $this->session->userId(), $request->ip, details: [
            'ust_id' => $kennung->ustId !== '',
            'steuernummer' => $kennung->steuernummer !== '',
        ]);

        return $this->zurueck('Vereinskennungen gespeichert.', FlashArt::Ok);
    }

    private function formular(bool $entsperrt, string $ustId, string $steuernummer, ?string $fehler, int $status = 200): ResponseInterface
    {
        return Response::html($this->view->render('admin/vereinskennungen', [
            'title' => 'Vereinskennungen',
            'csrf' => $this->session->csrfToken(),
            'flash' => $this->session->pullFlash(),
            'entsperrt' => $entsperrt,
            'gepflegt' => $this->kennungen->gepflegt(),
            'ustId' => $ustId,
            'steuernummer' => $steuernummer,
            'fehler' => $fehler,
        ], Area::Admin), $status);
    }

    private static function feld(Request $request, string $name): string
    {
        $wert = $request->post[$name] ?? '';

        return is_string($wert) ? trim($wert) : '';
    }

    private function zurueck(string $meldung, FlashArt $art): ResponseInterface
    {
        $this->session->flash($meldung, $art);

        return Response::redirect(self::SEITE);
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
