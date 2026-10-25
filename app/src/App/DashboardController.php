<?php

declare(strict_types=1);

namespace App\App;

use App\Domain\Berechtigungen;
use App\Domain\Permission;
use App\Http\Cookie;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Service\Account\SessionVault;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\Report\Dashboard;
use App\View\Area;
use App\View\View;

/**
 * The start page of the user area: the dashboard (issue #72, docs/spec/
 * 05-auswertung-und-export.md section 1 "Dashboard"). Any logged-in
 * account may open it; WHAT it shows depends on the account's rights and
 * scopes (App\Service\Report\Dashboard) - an account without any of them
 * sees the quick links only.
 *
 * The calendar year (= fiscal year, E-16) comes from `?jahr=`, a plain
 * number; nothing else of the request reaches a query. Sums and balances
 * are vault data: without an unlocked vault the page says so and shows the
 * counts only. The page carries amounts, so it is never cached by the
 * browser.
 */
final readonly class DashboardController
{
    /** The first year the switcher offers - well before any club data. */
    public const int ERSTES_JAHR = 2000;

    /**
     * @param \Closure(): Dashboard $dashboard built only when the page is
     *        actually rendered (it opens the database)
     */
    public function __construct(
        private View $view,
        private Session $session,
        private SessionVault $sessionVault,
        private \Closure $dashboard,
    ) {
    }

    public function start(Request $request): ResponseInterface
    {
        $this->session->start();
        $b = $this->view->berechtigungen() ?? Berechtigungen::keine();
        $heute = new \DateTimeImmutable('today');
        $jahr = self::jahr($request->query['jahr'] ?? null, $b, $heute);
        $tresor = $this->tresor($request);
        // The switcher stays within the years the account may see.
        $standard = Dashboard::standardJahr($b, $heute);
        $erstes = Dashboard::erstesJahr($b, self::ERSTES_JAHR);

        $antwort = Response::html($this->view->render('app/start', [
            'title' => '',
            'flash' => $this->session->pullFlash(),
            'entsperrt' => $tresor !== null,
            'kennzahlen' => ($this->dashboard)()->kennzahlen($b, $tresor, $jahr, $heute),
            'vorjahr' => $jahr > $erstes ? min($jahr, $standard + 1) - 1 : null,
            'folgejahr' => $jahr < $standard ? max($jahr, $erstes - 1) + 1 : null,
            'standardJahr' => $jahr !== $standard ? $standard : null,
            'posteingang' => $b->darf(Permission::InboxView),
            'erfassen' => $b->darf(Permission::DocumentSubmitInternal),
        ], Area::App));

        return new Response($antwort->status, [...$antwort->headers, 'Cache-Control' => 'no-store, private'], $antwort->body, $antwort->additionalCookies);
    }

    /**
     * The requested year if it is a plain number from ERSTES_JAHR to the
     * current year, else the default (Dashboard::standardJahr()). A future
     * year has no bookings and no balance yet.
     */
    private static function jahr(mixed $wert, Berechtigungen $b, \DateTimeImmutable $heute): int
    {
        if (is_string($wert) && preg_match('/^\d{4}$/', $wert) === 1) {
            $jahr = (int) $wert;
            if ($jahr >= self::ERSTES_JAHR && $jahr <= (int) $heute->format('Y')) {
                return $jahr;
            }
        }

        return Dashboard::standardJahr($b, $heute);
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
